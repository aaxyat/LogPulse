<?php
declare(strict_types=1);

/**
 * LogPulse Configuration Template
 * Copy this file to config.php and update with your cPanel MySQL credentials.
 */

return [
    'app' => [
        'name' => 'LogPulse',
        'env' => getenv('APP_ENV') ?: 'production', // 'development' or 'production'
        'url' => getenv('APP_URL') ?: 'https://logs.yourdomain.com',
        'jwt_secret' => getenv('JWT_SECRET') ?: 'CHANGE_THIS_TO_A_64_CHAR_RANDOM_HEX_STRING_FOR_SECURITY',
        'jwt_expiry_hours' => 72,
        'timezone' => 'UTC',
    ],

    'database' => [
        'driver' => 'mysql',
        'host' => getenv('DB_HOST') ?: '127.0.0.1',
        'port' => (int)(getenv('DB_PORT') ?: 3306),
        'database' => getenv('DB_DATABASE') ?: 'cpaneluser_logpulse',
        'username' => getenv('DB_USERNAME') ?: 'cpaneluser_loguser',
        'password' => getenv('DB_PASSWORD') ?: 'SecretPassword123!',
        'charset' => 'utf8mb4',
    ],

    'retention' => [
        'default_days' => 14,
        'max_days' => 90,
    ],

    'rate_limits' => [
        'default_per_minute' => 600, // per endpoint
        'auth_attempts_per_minute' => 15,
    ],

    'alerts' => [
        'cooldown_minutes' => 15, // minimum time between repeat alerts for same rule
        'email_from' => 'noreply@yourdomain.com',
    ],

    'cors' => [
        'allowed_origins' => ['*'],
        'allowed_headers' => ['Content-Type', 'Authorization', 'X-API-Key'],
        'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
    ],
];
