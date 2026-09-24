<?php
declare(strict_types=1);

namespace LogPulse\Auth;

use LogPulse\Database\DB;

class IngestAuth
{
    public static function authenticate(?string $pathToken = null): ?array
    {
        $token = $pathToken;

        if (empty($token)) {
            $headers = function_exists('getallheaders') ? getallheaders() : [];
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? '';
            if (!empty($authHeader) && preg_match('/Bearer\s+(\S+)/i', $authHeader, $matches)) {
                $token = $matches[1];
            }

            if (empty($token)) {
                $token = $headers['X-API-Key'] ?? $headers['x-api-key'] ?? $_SERVER['HTTP_X_API_KEY'] ?? null;
            }
        }

        if (empty($token) || !is_string($token)) {
            return null;
        }

        $endpoint = DB::one("SELECT * FROM endpoints WHERE is_active = 1 AND ingest_token = :token LIMIT 1", [
            'token' => $token
        ]);

        if (!$endpoint) {
            return null;
        }

        // Timing-safe verification
        if (!hash_equals($endpoint['ingest_token'], $token)) {
            return null;
        }

        return $endpoint;
    }
}
