<?php
declare(strict_types=1);

namespace LogPulse\Controllers;

use LogPulse\Database\DB;
use LogPulse\Services\ConfigVault;
use LogPulse\Services\MailService;

class SetupController
{
    /**
     * Diagnostic pre-flight checks
     */
    public static function check(): void
    {
        $isConfigured = ConfigVault::isConfigured();
        $configDir = dirname(__DIR__, 2) . '/config';

        $checks = [
            'php_version' => [
                'name' => 'PHP 8.1+ Runtime',
                'passed' => version_compare(PHP_VERSION, '8.1.0', '>='),
                'current' => PHP_VERSION,
                'required' => '>= 8.1.0'
            ],
            'pdo' => [
                'name' => 'PDO Extension',
                'passed' => extension_loaded('pdo'),
                'current' => extension_loaded('pdo') ? 'Installed' : 'Missing',
                'required' => 'Required'
            ],
            'pdo_mysql' => [
                'name' => 'PDO MySQL Driver',
                'passed' => extension_loaded('pdo_mysql'),
                'current' => extension_loaded('pdo_mysql') ? 'Installed' : 'Optional (SQLite available)',
                'required' => 'Recommended for cPanel'
            ],
            'openssl' => [
                'name' => 'OpenSSL (AES-256-GCM)',
                'passed' => extension_loaded('openssl'),
                'current' => extension_loaded('openssl') ? 'Installed' : 'Missing',
                'required' => 'Required for Encrypted Vault'
            ],
            'curl' => [
                'name' => 'cURL Extension',
                'passed' => extension_loaded('curl'),
                'current' => extension_loaded('curl') ? 'Installed' : 'Missing',
                'required' => 'Required for Alerts & Pings'
            ],
            'config_writable' => [
                'name' => 'Config Directory Writable',
                'passed' => is_writable($configDir),
                'current' => is_writable($configDir) ? 'Writable' : 'Read-only',
                'required' => 'Writable'
            ]
        ];

        $allPassed = true;
        foreach ($checks as $c) {
            if (!$c['passed'] && $c['required'] === 'Required') {
                $allPassed = false;
            }
        }

        echo json_encode([
            'success' => true,
            'is_configured' => $isConfigured,
            'all_passed' => $allPassed,
            'checks' => $checks,
            'suggested_key' => ConfigVault::generateMasterKey()
        ]);
    }

    /**
     * Live test of database credentials
     */
    public static function testDatabase(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $driver = $input['driver'] ?? 'mysql';

        try {
            if ($driver === 'sqlite') {
                $dbPath = $input['database'] ?? (dirname(__DIR__, 2) . '/database.sqlite');
                $pdo = new \PDO("sqlite:" . $dbPath);
            } else {
                $host = $input['host'] ?? '127.0.0.1';
                $port = (int)($input['port'] ?? 3306);
                $dbname = $input['database'] ?? '';
                $username = $input['username'] ?? '';
                $password = $input['password'] ?? '';

                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
                $pdo = new \PDO($dsn, $username, $password, [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_TIMEOUT => 5
                ]);
            }

            $stmt = $pdo->query("SELECT 1");
            $res = $stmt->fetchColumn();

            echo json_encode([
                'success' => true,
                'message' => 'Database connection established successfully!'
            ]);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Database connection failed: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Live test of SMTP credentials
     */
    public static function testMail(): void
    {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
        $host = $input['host'] ?? 'mail.722411.xyz';
        $port = (int)($input['port'] ?? 465);
        $encryption = $input['encryption'] ?? 'ssl';
        $username = $input['username'] ?? '';
        $password = $input['password'] ?? '';
        $recipient = $input['test_recipient'] ?? $username;

        if (empty($password)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'SMTP password is required to test mail.'
            ]);
            return;
        }

        $mailConfig = [
            'host' => $host,
            'port' => $port,
            'encryption' => $encryption,
            'username' => $username,
            'password' => $password,
            'from_address' => $username,
            'from_name' => 'LogPulse Setup'
        ];

        $html = MailService::buildTemplate(
            'SMTP Configuration Test',
            '<p>This email confirms that your LogPulse transactional SMTP transport is operational.</p>',
            null,
            null,
            [['text' => 'VERIFIED', 'color' => '#34d399', 'bg' => 'rgba(52, 211, 153, 0.15)']]
        );

        $ok = MailService::send($recipient, 'LogPulse SMTP Verification Test', $html, '', $mailConfig);

        if ($ok) {
            echo json_encode([
                'success' => true,
                'message' => "SMTP verification email sent successfully to {$recipient}!"
            ]);
        } else {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'SMTP test failed: ' . (MailService::getLastError() ?? 'Unknown error')
            ]);
        }
    }

    /**
     * Execute full encrypted installation
     */
    public static function install(): void
    {
        if (ConfigVault::isConfigured()) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'System is already installed. Setup wizard is locked.'
            ]);
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        // Validate required sections
        if (empty($input['admin']['email']) || empty($input['admin']['password'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Admin email and password are required.']);
            return;
        }

        $masterKey = !empty($input['master_key']) ? trim($input['master_key']) : ConfigVault::generateMasterKey();

        // Build complete configuration tree
        $appUrl = rtrim($input['app_url'] ?? (($_SERVER['REQUEST_SCHEME'] ?? 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')), '/');
        
        $config = [
            'app' => [
                'name' => $input['app_name'] ?? 'LogPulse',
                'env' => 'production',
                'url' => $appUrl,
                'jwt_secret' => bin2hex(random_bytes(32)),
                'jwt_expiry_hours' => 72,
                'timezone' => $input['timezone'] ?? 'UTC',
            ],
            'database' => $input['database'] ?? [
                'driver' => 'mysql',
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => 'ayushbco_logpulse',
                'username' => 'ayushbco_loguser',
                'password' => '',
                'charset' => 'utf8mb4',
            ],
            'mail' => [
                'mailer' => 'smtp',
                'host' => $input['mail']['host'] ?? 'mail.722411.xyz',
                'port' => (int)($input['mail']['port'] ?? 465),
                'encryption' => $input['mail']['encryption'] ?? 'ssl',
                'username' => $input['mail']['username'] ?? 'dontreply@722411.xyz',
                'password' => $input['mail']['password'] ?? '',
                'from_address' => $input['mail']['from_address'] ?? 'dontreply@722411.xyz',
                'from_name' => $input['mail']['from_name'] ?? 'LogPulse',
            ],
            'retention' => [
                'default_days' => 14,
                'max_days' => 90,
            ],
            'rate_limits' => [
                'default_per_minute' => 600,
                'auth_attempts_per_minute' => 15,
            ],
            'alerts' => [
                'cooldown_minutes' => 15,
                'email_from' => $input['mail']['from_address'] ?? 'dontreply@722411.xyz',
            ],
            'cors' => [
                'allowed_origins' => ['*'],
                'allowed_headers' => ['Content-Type', 'Authorization', 'X-API-Key'],
                'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            ]
        ];

        try {
            // 1. Encrypt and save configuration to config/config.enc
            $saved = ConfigVault::save($config, $masterKey);
            if (!$saved) {
                throw new \RuntimeException("Failed to write encrypted config file to disk.");
            }

            // 2. Initialize Database & Run Schema Migration
            DB::init($config['database']);
            $schemaFile = dirname(__DIR__, 2) . '/config/schema.sql';
            if (file_exists($schemaFile)) {
                $sql = file_get_contents($schemaFile);
                if ($config['database']['driver'] === 'sqlite') {
                    // Normalize MySQL schema types for SQLite
                    $sql = preg_replace('/INT AUTO_INCREMENT PRIMARY KEY/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
                    $sql = preg_replace('/ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;/i', ';', $sql);
                    $sql = preg_replace('/DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP/i', 'DATETIME DEFAULT CURRENT_TIMESTAMP', $sql);
                    $sql = preg_replace('/BIGINT UNSIGNED AUTO_INCREMENT/i', 'INTEGER PRIMARY KEY AUTOINCREMENT', $sql);
                }
                
                $statements = array_filter(array_map('trim', explode(';', $sql)));
                foreach ($statements as $stmt) {
                    if (!empty($stmt)) {
                        DB::execute($stmt);
                    }
                }
            }

            // 3. Seed Primary Admin User
            $adminEmail = trim($input['admin']['email']);
            $adminName = trim($input['admin']['name'] ?? 'System Administrator');
            $adminPass = $input['admin']['password'];
            $passHash = password_hash($adminPass, PASSWORD_BCRYPT);

            $existing = DB::fetchOne("SELECT id FROM users WHERE email = :email", ['email' => $adminEmail]);
            if ($existing) {
                DB::execute("UPDATE users SET password_hash = :hash, name = :name, role = 'admin' WHERE id = :id", [
                    'hash' => $passHash,
                    'name' => $adminName,
                    'id' => $existing['id']
                ]);
            } else {
                DB::execute("INSERT INTO users (email, password_hash, name, role, is_active, created_at)
                             VALUES (:email, :hash, :name, 'admin', 1, :now)", [
                    'email' => $adminEmail,
                    'hash' => $passHash,
                    'name' => $adminName,
                    'now' => date('Y-m-d H:i:s')
                ]);
            }

            // 4. Send Welcome / Installation Confirmation Email if SMTP configured
            if (!empty($config['mail']['password'])) {
                $html = MailService::buildTemplate(
                    'LogPulse Installation Complete',
                    "<p>Your LogPulse instance has been successfully configured and encrypted with AES-256-GCM.</p>
                     <p>You can now manage projects, stream logs, and receive telemetry alerts.</p>",
                    $appUrl . '/login',
                    'Access Dashboard',
                    [['text' => 'INITIALIZED', 'color' => '#38bdf8', 'bg' => 'rgba(56, 189, 248, 0.15)']]
                );
                @MailService::send($adminEmail, 'LogPulse Installation Complete', $html, '', $config['mail']);
            }

            echo json_encode([
                'success' => true,
                'message' => 'LogPulse installed and encrypted successfully!',
                'master_key' => $masterKey,
                'redirect' => '/login'
            ]);

        } catch (\Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Installation failed: ' . $e->getMessage()
            ]);
        }
    }
}
