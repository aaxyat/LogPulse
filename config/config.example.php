<?php
declare(strict_types=1);

/**
 * LogPulse Configuration Template
 * Automatically loads .env if present.
 */

if (!function_exists('logpulse_load_env')) {
    function logpulse_load_env(string $path): void {
        if (!file_exists($path) || !is_readable($path)) {
            return;
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val);
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }
            if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                putenv("{$key}={$val}");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
}
logpulse_load_env(dirname(__DIR__) . '/.env');

return [
    'app' => [
        'name' => getenv('APP_NAME') ?: 'LogPulse',
        'env' => getenv('APP_ENV') ?: 'production', // 'development' or 'production'
        'url' => getenv('APP_URL') ?: 'https://logs.yourdomain.com',
        'jwt_secret' => getenv('JWT_SECRET') ?: 'CHANGE_THIS_TO_A_64_CHAR_RANDOM_HEX_STRING_FOR_SECURITY',
        'jwt_expiry_hours' => (int)(getenv('JWT_EXPIRY_HOURS') ?: 72),
        'timezone' => getenv('TIMEZONE') ?: 'UTC',
    ],

    'database' => [
        'driver' => getenv('DB_DRIVER') ?: 'mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('DB_PORT') ?: 3306),
        'database' => getenv('DB_DATABASE') ?: 'cpaneluser_logpulse',
        'username' => getenv('DB_USERNAME') ?: 'cpaneluser_loguser',
        'password' => getenv('DB_PASSWORD') ?: 'SecretPassword123!',
        'charset' => getenv('DB_CHARSET') ?: 'utf8mb4',
    ],

    'mail' => [
        'mailer' => getenv('MAIL_MAILER') ?: 'smtp',
        'host' => getenv('MAIL_HOST') ?: 'mail.722411.xyz',
        'port' => (int)(getenv('MAIL_PORT') ?: 465),
        'encryption' => getenv('MAIL_ENCRYPTION') ?: 'ssl', // 'ssl' (port 465) or 'tls' (port 587)
        'username' => getenv('MAIL_USERNAME') ?: 'dontreply@722411.xyz',
        'password' => getenv('MAIL_PASSWORD') ?: '',
        'from_address' => getenv('MAIL_FROM_ADDRESS') ?: 'dontreply@722411.xyz',
        'from_name' => getenv('MAIL_FROM_NAME') ?: 'LogPulse',
    ],

    'retention' => [
        'default_days' => (int)(getenv('RETENTION_DEFAULT_DAYS') ?: 14),
        'max_days' => (int)(getenv('RETENTION_MAX_DAYS') ?: 90),
    ],

    'rate_limits' => [
        'default_per_minute' => 600, // per endpoint
        'auth_attempts_per_minute' => 15,
    ],

    'alerts' => [
        'cooldown_minutes' => (int)(getenv('ALERT_COOLDOWN_MINUTES') ?: 15),
        'email_from' => getenv('MAIL_FROM_ADDRESS') ?: 'dontreply@722411.xyz',
    ],

    'cors' => [
        'allowed_origins' => ['*'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-API-Key'],
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    ],
];
