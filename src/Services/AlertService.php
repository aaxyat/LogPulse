<?php
declare(strict_types=1);

namespace LogPulse\Services;

use LogPulse\Database\DB;

class AlertService
{
    public static function evaluateForEndpoint(int $endpointId): void
    {
        $rules = DB::query("SELECT * FROM alert_rules 
                            WHERE is_active = 1 
                            AND (endpoint_id = :endpoint_id OR endpoint_id IS NULL)", [
            'endpoint_id' => $endpointId
        ]);

        foreach ($rules as $rule) {
            self::checkRule($rule, $endpointId);
        }
    }

    public static function evaluateAll(): int
    {
        $rules = DB::query("SELECT * FROM alert_rules WHERE is_active = 1");
        $triggered = 0;

        foreach ($rules as $rule) {
            $endpointId = $rule['endpoint_id'] !== null ? (int)$rule['endpoint_id'] : null;
            if (self::checkRule($rule, $endpointId)) {
                $triggered++;
            }
        }

        return $triggered;
    }

    private static function checkRule(array $rule, ?int $endpointId): bool
    {
        $windowMinutes = (int)($rule['time_window_minutes'] ?? 5);
        $thresholdCount = (int)($rule['count_threshold'] ?? 5);
        $levelThreshold = $rule['level_threshold'] ?? 'ERROR';

        // Cooldown check (minimum 10 minutes between duplicate alerts)
        if (!empty($rule['last_triggered_at'])) {
            $lastTriggered = strtotime($rule['last_triggered_at']);
            if (time() - $lastTriggered < ($windowMinutes * 60)) {
                return false;
            }
        }

        $sinceTime = date('Y-m-d H:i:s', time() - ($windowMinutes * 60));

        $sql = "SELECT COUNT(*) as cnt FROM logs 
                WHERE created_at >= :since 
                AND level IN ('ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY')";
        $params = ['since' => $sinceTime];

        if ($endpointId !== null) {
            $sql .= " AND endpoint_id = :endpoint_id";
            $params['endpoint_id'] = $endpointId;
        }

        $row = DB::one($sql, $params);
        $count = (int)($row['cnt'] ?? 0);

        if ($count >= $thresholdCount) {
            self::dispatchAlert($rule, $count, $sinceTime, $endpointId);
            return true;
        }

        return false;
    }

    public static function dispatchAlert(array $rule, int $count, string $sinceTime, ?int $endpointId): void
    {
        $channel = $rule['channel_type'];
        $destination = $rule['target_destination'];
        $ruleName = $rule['name'];

        $endpointName = 'All Endpoints';
        if ($endpointId !== null) {
            $ep = DB::one("SELECT name, slug FROM endpoints WHERE id = :id", ['id' => $endpointId]);
            if ($ep) {
                $endpointName = "{$ep['name']} ({$ep['slug']})";
            }
        }

        $title = "🚨 LogPulse Alert: {$ruleName}";
        $message = "Threshold exceeded! {$count} error logs detected in the last {$rule['time_window_minutes']} minutes on {$endpointName}.";

        $sampleLogs = DB::query("SELECT level, message, created_at FROM logs 
                                 WHERE created_at >= :since 
                                 AND level IN ('ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY') 
                                 ORDER BY id DESC LIMIT 3", [
            'since' => $sinceTime
        ]);

        $success = false;
        $details = $message;

        switch ($channel) {
            case 'slack':
                $success = self::sendSlackWebhook($destination, $title, $message, $sampleLogs);
                break;
            case 'discord':
                $success = self::sendDiscordWebhook($destination, $title, $message, $sampleLogs);
                break;
            case 'email':
                $success = self::sendEmail($destination, $title, $message, $sampleLogs);
                break;
            case 'webhook':
            default:
                $success = self::sendGenericWebhook($destination, [
                    'event' => 'alert.triggered',
                    'rule' => $ruleName,
                    'endpoint' => $endpointName,
                    'error_count' => $count,
                    'window_minutes' => $rule['time_window_minutes'],
                    'timestamp' => date('c'),
                    'sample_logs' => $sampleLogs
                ]);
                break;
        }

        DB::execute("UPDATE alert_rules SET last_triggered_at = :now WHERE id = :id", [
            'now' => date('Y-m-d H:i:s'),
            'id' => $rule['id']
        ]);

        DB::execute("INSERT INTO alert_history (rule_id, endpoint_id, trigger_count, details, sent_at)
                     VALUES (:rule_id, :endpoint_id, :trigger_count, :details, :sent_at)", [
            'rule_id' => $rule['id'],
            'endpoint_id' => $endpointId,
            'trigger_count' => $count,
            'details' => $details,
            'sent_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function sendGenericWebhook(string $url, array $payload): bool
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'User-Agent: LogPulse-Alert-Bot/1.0'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return $code >= 200 && $code < 300;
    }

    private static function sendSlackWebhook(string $url, string $title, string $message, array $samples): bool
    {
        $blocks = [
            [
                'type' => 'header',
                'text' => ['type' => 'plain_text', 'text' => $title, 'emoji' => true]
            ],
            [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => $message]
            ]
        ];

        if (!empty($samples)) {
            $sampleText = "*Recent error samples:*\n";
            foreach ($samples as $s) {
                $sampleText .= "• `[{$s['level']}]` " . substr($s['message'], 0, 120) . "\n";
            }
            $blocks[] = [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => $sampleText]
            ];
        }

        return self::sendGenericWebhook($url, ['blocks' => $blocks]);
    }

    private static function sendDiscordWebhook(string $url, string $title, string $message, array $samples): bool
    {
        $fields = [];
        foreach ($samples as $idx => $s) {
            $fields[] = [
                'name' => "Log " . ($idx + 1) . " [{$s['level']}]",
                'value' => substr($s['message'], 0, 250),
                'inline' => false
            ];
        }

        $payload = [
            'username' => 'LogPulse Alerts',
            'embeds' => [
                [
                    'title' => $title,
                    'description' => $message,
                    'color' => 16007998, // Rose red
                    'fields' => $fields,
                    'timestamp' => date('c')
                ]
            ]
        ];

        return self::sendGenericWebhook($url, $payload);
    }

    private static function sendEmail(string $to, string $subject, string $message, array $samples): bool
    {
        $body = $message . "\n\nRecent Errors:\n";
        foreach ($samples as $s) {
            $body .= "[{$s['level']}] {$s['created_at']}: {$s['message']}\n";
        }
        $headers = 'From: LogPulse <noreply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . ">\r\n" .
                   'X-Mailer: PHP/' . phpversion();
        return @mail($to, $subject, $body, $headers);
    }
}
