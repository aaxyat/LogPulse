<?php
declare(strict_types=1);

namespace LogPulse\Auth;

use LogPulse\Database\DB;

class AuthMiddleware
{
    private static ?array $currentUser = null;

    public static function authenticate(string $jwtSecret): ?array
    {
        $token = self::extractToken();
        if (!$token) {
            return null;
        }

        $payload = JWT::verify($token, $jwtSecret);
        if (!$payload || empty($payload['sub'])) {
            return null;
        }

        $userId = (int)$payload['sub'];
        $user = DB::one("SELECT id, name, email, role, status FROM users WHERE id = :id LIMIT 1", ['id' => $userId]);

        if (!$user || $user['status'] !== 'active') {
            return null;
        }

        self::$currentUser = $user;
        return $user;
    }

    public static function user(): ?array
    {
        return self::$currentUser;
    }

    public static function requireAuth(string $jwtSecret): array
    {
        $user = self::authenticate($jwtSecret);
        if (!$user) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'Unauthorized: Valid Bearer token or session required.'
            ]);
            exit;
        }
        return $user;
    }

    public static function requireAdmin(string $jwtSecret): array
    {
        $user = self::requireAuth($jwtSecret);
        if ($user['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Forbidden: Administrative privileges required.'
            ]);
            exit;
        }
        return $user;
    }

    private static function extractToken(): ?string
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
            return $matches[1];
        }

        if (!empty($_COOKIE['logpulse_token'])) {
            return $_COOKIE['logpulse_token'];
        }

        if (!empty($_GET['token'])) {
            return (string)$_GET['token'];
        }

        return null;
    }
}
