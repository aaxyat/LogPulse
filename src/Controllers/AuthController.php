<?php
declare(strict_types=1);

namespace LogPulse\Controllers;

use LogPulse\Auth\AuthMiddleware;
use LogPulse\Auth\JWT;
use LogPulse\Database\DB;

class AuthController
{
    public static function login(array $config): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $email = trim((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');

        if (empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Email and password are required.']);
            return;
        }

        $user = DB::one("SELECT * FROM users WHERE email = :email LIMIT 1", ['email' => $email]);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Invalid email or password.']);
            return;
        }

        if ($user['status'] !== 'active') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Account is suspended.']);
            return;
        }

        $secret = $config['app']['jwt_secret'];
        $expiryHours = (int)($config['app']['jwt_expiry_hours'] ?? 72);

        $token = JWT::sign([
            'sub' => $user['id'],
            'email' => $user['email'],
            'role' => $user['role'],
            'name' => $user['name'],
        ], $secret, $expiryHours * 3600);

        // Also set HttpOnly cookie for seamless browser navigation
        setcookie('logpulse_token', $token, [
            'expires' => time() + ($expiryHours * 3600),
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        echo json_encode([
            'success' => true,
            'token' => $token,
            'user' => [
                'id' => (int)$user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
            ]
        ]);
    }

    public static function me(array $user): void
    {
        echo json_encode([
            'success' => true,
            'user' => [
                'id' => (int)$user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role'],
            ]
        ]);
    }

    public static function logout(): void
    {
        setcookie('logpulse_token', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        echo json_encode(['success' => true, 'message' => 'Logged out successfully.']);
    }

    public static function createUser(array $currentUser): void
    {
        if ($currentUser['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Only administrators can create users.']);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim((string)($body['name'] ?? ''));
        $email = trim((string)($body['email'] ?? ''));
        $password = (string)($body['password'] ?? '');
        $role = in_array($body['role'] ?? '', ['admin', 'user'], true) ? $body['role'] : 'user';

        if (empty($name) || empty($email) || strlen($password) < 8) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid name, email, and password (min 8 chars) required.']);
            return;
        }

        $existing = DB::one("SELECT id FROM users WHERE email = :email LIMIT 1", ['email' => $email]);
        if ($existing) {
            http_response_code(409);
            echo json_encode(['success' => false, 'error' => 'User with this email already exists.']);
            return;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        DB::execute("INSERT INTO users (name, email, password_hash, role, status, created_at)
                     VALUES (:name, :email, :hash, :role, 'active', :created_at)", [
            'name' => $name,
            'email' => $email,
            'hash' => $hash,
            'role' => $role,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $newId = (int)DB::lastInsertId();

        echo json_encode([
            'success' => true,
            'user' => [
                'id' => $newId,
                'name' => $name,
                'email' => $email,
                'role' => $role,
            ]
        ]);
    }

    public static function listUsers(array $currentUser): void
    {
        if ($currentUser['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Admin privileges required.']);
            return;
        }

        $users = DB::query("SELECT id, name, email, role, status, created_at FROM users ORDER BY id ASC");
        echo json_encode(['success' => true, 'users' => $users]);
    }
}
