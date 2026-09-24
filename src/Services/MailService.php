<?php
declare(strict_types=1);

namespace LogPulse\Services;

/**
 * Zero-Dependency Pure PHP SMTP Mail Service
 * Implements RFC 2821 / RFC 2822 SMTP transport over SSL/TLS without external libraries.
 */
class MailService
{
    private static ?string $lastError = null;

    public static function getLastError(): ?string
    {
        return self::$lastError;
    }

    /**
     * Send an email via configured SMTP transport.
     *
     * @param string|array $to Email address or array of email addresses
     * @param string $subject Email subject line
     * @param string $htmlBody HTML content
     * @param string $textBody Plain text fallback
     * @param array $config Optional override configuration
     * @return bool True if accepted by remote SMTP server
     */
    public static function send(
        string|array $to,
        string $subject,
        string $htmlBody,
        string $textBody = '',
        array $config = []
    ): bool {
        self::$lastError = null;

        if (empty($config)) {
            $fullConfig = require dirname(__DIR__, 2) . '/config/config.php';
            $config = $fullConfig['mail'] ?? [];
        }

        $host = $config['host'] ?? 'mail.722411.xyz';
        $port = (int)($config['port'] ?? 465);
        $encryption = strtolower($config['encryption'] ?? 'ssl');
        $username = $config['username'] ?? '';
        $password = $config['password'] ?? '';
        $fromAddress = $config['from_address'] ?? $username;
        $fromName = $config['from_name'] ?? 'LogPulse';

        if (empty($password)) {
            self::$lastError = "SMTP configuration error: MAIL_PASSWORD is not set.";
            error_log("[MailService] " . self::$lastError);
            return false;
        }

        $recipients = is_array($to) ? $to : [$to];
        $recipients = array_map('trim', $recipients);
        if (empty($recipients)) {
            self::$lastError = "No valid recipient email address provided.";
            return false;
        }

        // Establish socket connection
        $isSsl = ($encryption === 'ssl' || $port === 465);
        $proto = $isSsl ? 'ssl://' : 'tcp://';
        $target = $proto . $host . ':' . $port;

        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ]
        ]);

        $timeout = 15;
        $socket = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$socket) {
            self::$lastError = "Connection failed to {$target}: {$errstr} ({$errno})";
            error_log("[MailService] " . self::$lastError);
            return false;
        }

        stream_set_timeout($socket, $timeout);

        try {
            // 1. Initial Greeting
            $response = self::readResponse($socket);
            if (!str_starts_with($response, '220')) {
                throw new \RuntimeException("Unexpected greeting: {$response}");
            }

            // 2. EHLO
            $clientDomain = $_SERVER['SERVER_NAME'] ?? '722411.xyz';
            $response = self::sendCommand($socket, "EHLO {$clientDomain}");
            if (!str_starts_with($response, '250')) {
                throw new \RuntimeException("EHLO rejected: {$response}");
            }

            // 3. STARTTLS if port 587
            if (!$isSsl && ($encryption === 'tls' || $port === 587)) {
                $response = self::sendCommand($socket, "STARTTLS");
                if (!str_starts_with($response, '220')) {
                    throw new \RuntimeException("STARTTLS failed: {$response}");
                }
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException("TLS handshake failed after STARTTLS.");
                }
                $response = self::sendCommand($socket, "EHLO {$clientDomain}");
                if (!str_starts_with($response, '250')) {
                    throw new \RuntimeException("EHLO rejected after STARTTLS: {$response}");
                }
            }

            // 4. AUTH LOGIN
            $response = self::sendCommand($socket, "AUTH LOGIN");
            if (!str_starts_with($response, '334')) {
                throw new \RuntimeException("AUTH LOGIN rejected: {$response}");
            }

            $response = self::sendCommand($socket, base64_encode($username));
            if (!str_starts_with($response, '334')) {
                throw new \RuntimeException("Username rejected: {$response}");
            }

            $response = self::sendCommand($socket, base64_encode($password));
            if (!str_starts_with($response, '235')) {
                throw new \RuntimeException("Authentication failed: {$response}");
            }

            // 5. MAIL FROM
            $response = self::sendCommand($socket, "MAIL FROM:<{$fromAddress}>");
            if (!str_starts_with($response, '250')) {
                throw new \RuntimeException("MAIL FROM rejected: {$response}");
            }

            // 6. RCPT TO
            foreach ($recipients as $rcpt) {
                $response = self::sendCommand($socket, "RCPT TO:<{$rcpt}>");
                if (!str_starts_with($response, '250') && !str_starts_with($response, '251')) {
                    throw new \RuntimeException("Recipient <{$rcpt}> rejected: {$response}");
                }
            }

            // 7. DATA
            $response = self::sendCommand($socket, "DATA");
            if (!str_starts_with($response, '354')) {
                throw new \RuntimeException("DATA rejected: {$response}");
            }

            // 8. Build RFC 2822 Message
            if (empty($textBody)) {
                $textBody = strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $htmlBody));
            }

            $boundary = '=_lp_mime_' . bin2hex(random_bytes(12));
            $toHeader = implode(', ', $recipients);
            $msgIdDomain = substr(strrchr($fromAddress, "@"), 1) ?: '722411.xyz';
            $messageId = '<' . bin2hex(random_bytes(16)) . '@' . $msgIdDomain . '>';

            $headers = [
                "From: {$fromName} <{$fromAddress}>",
                "To: {$toHeader}",
                "Subject: {$subject}",
                "Date: " . date('r'),
                "Message-ID: {$messageId}",
                "MIME-Version: 1.0",
                "Content-Type: multipart/alternative; boundary=\"{$boundary}\"",
                "X-Mailer: LogPulse-Engine/1.0",
                "Auto-Submitted: auto-generated",
            ];

            $payload = implode("\r\n", $headers) . "\r\n\r\n";
            
            // Plain text part
            $payload .= "--{$boundary}\r\n";
            $payload .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $payload .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $payload .= $textBody . "\r\n\r\n";

            // HTML part
            $payload .= "--{$boundary}\r\n";
            $payload .= "Content-Type: text/html; charset=UTF-8\r\n";
            $payload .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
            $payload .= $htmlBody . "\r\n\r\n";

            // End boundary
            $payload .= "--{$boundary}--\r\n";
            $payload .= ".\r\n";

            fwrite($socket, $payload);
            $response = self::readResponse($socket);
            if (!str_starts_with($response, '250')) {
                throw new \RuntimeException("Message body rejected: {$response}");
            }

            // 9. QUIT
            self::sendCommand($socket, "QUIT");
            fclose($socket);
            return true;

        } catch (\Throwable $e) {
            self::$lastError = $e->getMessage();
            error_log("[MailService Exception] " . self::$lastError);
            if (is_resource($socket)) {
                @fwrite($socket, "QUIT\r\n");
                @fclose($socket);
            }
            return false;
        }
    }

    /**
     * Build branded responsive HTML template for transactional emails
     */
    public static function buildTemplate(
        string $headline,
        string $messageHtml,
        ?string $actionUrl = null,
        ?string $actionText = null,
        array $badges = []
    ): string {
        $badgeHtml = '';
        foreach ($badges as $badge) {
            $color = $badge['color'] ?? '#38bdf8';
            $bg = $badge['bg'] ?? 'rgba(56, 189, 248, 0.15)';
            $text = htmlspecialchars($badge['text'] ?? '');
            $badgeHtml .= "<span style=\"display: inline-block; padding: 3px 10px; font-size: 11px; font-family: monospace; font-weight: bold; border-radius: 4px; color: {$color}; background: {$bg}; margin-right: 6px;\">{$text}</span>";
        }

        $buttonHtml = '';
        if ($actionUrl && $actionText) {
            $buttonHtml = "
            <div style=\"margin: 28px 0 16px 0; text-align: center;\">
                <a href=\"{$actionUrl}\" style=\"background: #0ea5e9; color: #06090f; padding: 12px 24px; font-weight: bold; font-size: 13px; text-decoration: none; border-radius: 6px; display: inline-block;\">
                    {$actionText} &rarr;
                </a>
            </div>";
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$headline}</title>
</head>
<body style="margin: 0; padding: 0; background-color: #06090f; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #f1f5f9;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background-color: #06090f; padding: 40px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" style="max-width: 580px; background-color: #0b0f19; border: 1px solid #1e293b; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.5);" cellspacing="0" cellpadding="0">
                    <!-- Header -->
                    <tr>
                        <td style="padding: 24px 28px; background: #0c1322; border-bottom: 1px solid #1e293b;">
                            <table width="100%">
                                <tr>
                                    <td>
                                        <div style="font-size: 18px; font-weight: 800; letter-spacing: -0.5px; color: #ffffff;">
                                            Log<span style="color: #38bdf8;">Pulse</span>
                                            <span style="font-size: 10px; font-family: monospace; font-weight: normal; color: #94a3b8; margin-left: 6px; padding: 2px 6px; border: 1px solid #334155; border-radius: 4px;">TELEMETRY</span>
                                        </div>
                                    </td>
                                    <td align="right">
                                        {$badgeHtml}
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <!-- Main Body -->
                    <tr>
                        <td style="padding: 32px 28px;">
                            <h1 style="font-size: 20px; font-weight: 700; color: #ffffff; margin: 0 0 16px 0; line-height: 1.3;">
                                {$headline}
                            </h1>
                            <div style="font-size: 14px; line-height: 1.6; color: #cbd5e1;">
                                {$messageHtml}
                            </div>
                            {$buttonHtml}
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding: 20px 28px; background: #080c14; border-top: 1px solid #1e293b; text-align: center; font-size: 11px; color: #64748b;">
                            <p style="margin: 0;">This is an automated notification sent by LogPulse via <code>dontreply@722411.xyz</code>.</p>
                            <p style="margin: 4px 0 0 0;">Do not reply to this email &mdash; incoming messages are unmonitored and rejected.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    private static function sendCommand($socket, string $cmd): string
    {
        fwrite($socket, $cmd . "\r\n");
        return self::readResponse($socket);
    }

    private static function readResponse($socket): string
    {
        $response = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $response .= $line;
            // Complete response when 4th character is space or line length is 3 (e.g., '250 OK')
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
            if (strlen($line) === 3) {
                break;
            }
        }
        return trim($response);
    }
}
