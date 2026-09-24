<?php
declare(strict_types=1);

namespace LogPulse\Controllers;

use LogPulse\Database\DB;

class EndpointController
{
    public static function list(array $user): void
    {
        $isAdmin = ($user['role'] === 'admin');
        if ($isAdmin) {
            $endpoints = DB::query("SELECT e.*, u.name as owner_name, 
                                    (SELECT COUNT(*) FROM logs l WHERE l.endpoint_id = e.id) as log_count,
                                    (SELECT MAX(created_at) FROM logs l WHERE l.endpoint_id = e.id) as last_log_at
                                    FROM endpoints e 
                                    LEFT JOIN users u ON e.user_id = u.id 
                                    ORDER BY e.created_at DESC");
        } else {
            $endpoints = DB::query("SELECT e.*, 
                                    (SELECT COUNT(*) FROM logs l WHERE l.endpoint_id = e.id) as log_count,
                                    (SELECT MAX(created_at) FROM logs l WHERE l.endpoint_id = e.id) as last_log_at
                                    FROM endpoints e 
                                    WHERE e.user_id = :uid 
                                    ORDER BY e.created_at DESC", [
                'uid' => $user['id']
            ]);
        }

        echo json_encode(['success' => true, 'endpoints' => $endpoints]);
    }

    public static function create(array $user): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim((string)($body['name'] ?? ''));
        $retentionDays = max(1, min(90, (int)($body['retention_days'] ?? 14)));

        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Endpoint name is required.']);
            return;
        }

        // Generate URL-friendly slug
        $baseSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
        if (empty($baseSlug)) {
            $baseSlug = 'endpoint-' . bin2hex(random_bytes(3));
        }

        $slug = $baseSlug;
        $counter = 1;
        while (DB::one("SELECT id FROM endpoints WHERE slug = :slug", ['slug' => $slug])) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        $token = 'lp_' . bin2hex(random_bytes(20));

        DB::execute("INSERT INTO endpoints (user_id, name, slug, ingest_token, retention_days, is_active, created_at)
                     VALUES (:user_id, :name, :slug, :token, :retention, 1, :created_at)", [
            'user_id' => $user['id'],
            'name' => $name,
            'slug' => $slug,
            'token' => $token,
            'retention' => $retentionDays,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $newId = (int)DB::lastInsertId();
        $endpoint = DB::one("SELECT * FROM endpoints WHERE id = :id", ['id' => $newId]);

        http_response_code(201);
        echo json_encode(['success' => true, 'endpoint' => $endpoint]);
    }

    public static function update(array $user, int $id): void
    {
        $endpoint = DB::one("SELECT * FROM endpoints WHERE id = :id", ['id' => $id]);
        if (!$endpoint || ($user['role'] !== 'admin' && (int)$endpoint['user_id'] !== (int)$user['id'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Endpoint not found.']);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = isset($body['name']) ? trim((string)$body['name']) : $endpoint['name'];
        $retentionDays = isset($body['retention_days']) ? max(1, min(90, (int)$body['retention_days'])) : (int)$endpoint['retention_days'];
        $isActive = isset($body['is_active']) ? (int)(bool)$body['is_active'] : (int)$endpoint['is_active'];

        DB::execute("UPDATE endpoints 
                     SET name = :name, retention_days = :retention, is_active = :active 
                     WHERE id = :id", [
            'name' => $name,
            'retention' => $retentionDays,
            'active' => $isActive,
            'id' => $id,
        ]);

        $updated = DB::one("SELECT * FROM endpoints WHERE id = :id", ['id' => $id]);
        echo json_encode(['success' => true, 'endpoint' => $updated]);
    }

    public static function regenerateToken(array $user, int $id): void
    {
        $endpoint = DB::one("SELECT * FROM endpoints WHERE id = :id", ['id' => $id]);
        if (!$endpoint || ($user['role'] !== 'admin' && (int)$endpoint['user_id'] !== (int)$user['id'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Endpoint not found.']);
            return;
        }

        $newToken = 'lp_' . bin2hex(random_bytes(20));
        DB::execute("UPDATE endpoints SET ingest_token = :token WHERE id = :id", [
            'token' => $newToken,
            'id' => $id,
        ]);

        echo json_encode([
            'success' => true,
            'ingest_token' => $newToken,
            'message' => 'New ingest token generated. Prior token has been invalidated.'
        ]);
    }

    public static function delete(array $user, int $id): void
    {
        $endpoint = DB::one("SELECT * FROM endpoints WHERE id = :id", ['id' => $id]);
        if (!$endpoint || ($user['role'] !== 'admin' && (int)$endpoint['user_id'] !== (int)$user['id'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Endpoint not found.']);
            return;
        }

        DB::execute("DELETE FROM endpoints WHERE id = :id", ['id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Endpoint and associated logs deleted.']);
    }
}
