<?php
declare(strict_types=1);

namespace LogPulse\Controllers;

use LogPulse\Database\DB;

class ProjectController
{
    public static function list(array $user): void
    {
        $isAdmin = ($user['role'] === 'admin');
        $since24h = date('Y-m-d H:i:s', strtotime('-24 hours'));

        $sql = "SELECT e.*, 
                (SELECT COUNT(*) FROM logs l WHERE l.endpoint_id = e.id) as log_count,
                (SELECT COUNT(*) FROM logs l WHERE l.endpoint_id = e.id AND l.level IN ('ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY') AND l.created_at >= :since24h) as error_count_24h,
                (SELECT MAX(created_at) FROM logs l WHERE l.endpoint_id = e.id) as last_log_at
                FROM endpoints e ";

        $params = ['since24h' => $since24h];

        if (!$isAdmin) {
            $sql .= " WHERE e.user_id = :uid ";
            $params['uid'] = $user['id'];
        }

        $sql .= " ORDER BY e.created_at DESC";

        $projects = DB::query($sql, $params);

        // Format projects for response
        $formatted = array_map(function ($p) {
            return [
                'id' => (int)$p['id'],
                'name' => $p['name'],
                'slug' => $p['slug'],
                'description' => $p['description'] ?? '',
                'target_url' => $p['target_url'] ?? '',
                'ingest_token' => $p['ingest_token'],
                'retention_days' => (int)$p['retention_days'],
                'is_active' => (bool)$p['is_active'],
                'log_count' => (int)($p['log_count'] ?? 0),
                'error_count_24h' => (int)($p['error_count_24h'] ?? 0),
                'last_log_at' => $p['last_log_at'],
                'uptime' => [
                    'is_up' => $p['last_is_up'] !== null ? (bool)$p['last_is_up'] : null,
                    'status_code' => $p['last_status_code'] !== null ? (int)$p['last_status_code'] : null,
                    'response_time_ms' => $p['last_response_time_ms'] !== null ? (int)$p['last_response_time_ms'] : null,
                    'last_checked_at' => $p['last_checked_at'],
                ],
                'created_at' => $p['created_at'],
            ];
        }, $projects);

        echo json_encode(['success' => true, 'projects' => $formatted]);
    }

    public static function create(array $user): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim((string)($body['name'] ?? ''));
        $description = trim((string)($body['description'] ?? ''));
        $targetUrl = trim((string)($body['target_url'] ?? ''));
        $retentionDays = max(1, min(90, (int)($body['retention_days'] ?? 14)));

        if (empty($name)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Project name is required.']);
            return;
        }

        if (!empty($targetUrl) && !filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid target URL format (must begin with http:// or https://).']);
            return;
        }

        $baseSlug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $name), '-'));
        if (empty($baseSlug)) {
            $baseSlug = 'project-' . bin2hex(random_bytes(3));
        }

        $slug = $baseSlug;
        $counter = 1;
        while (DB::one("SELECT id FROM endpoints WHERE slug = :slug", ['slug' => $slug])) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        $token = 'lp_' . bin2hex(random_bytes(20));
        $now = date('Y-m-d H:i:s');

        DB::execute("INSERT INTO endpoints (user_id, name, slug, description, target_url, ingest_token, retention_days, is_active, created_at)
                     VALUES (:user_id, :name, :slug, :description, :target_url, :token, :retention, 1, :created_at)", [
            'user_id' => $user['id'],
            'name' => $name,
            'slug' => $slug,
            'description' => $description,
            'target_url' => $targetUrl,
            'token' => $token,
            'retention' => $retentionDays,
            'created_at' => $now,
        ]);

        $newId = (int)DB::lastInsertId();

        // If target URL provided, probe immediately
        if (!empty($targetUrl)) {
            self::probeUrl($newId, $targetUrl);
        }

        $project = DB::one("SELECT * FROM endpoints WHERE id = :id", ['id' => $newId]);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'project' => [
                'id' => $newId,
                'name' => $project['name'],
                'slug' => $project['slug'],
                'description' => $project['description'],
                'target_url' => $project['target_url'],
                'ingest_token' => $project['ingest_token'],
                'retention_days' => (int)$project['retention_days'],
                'uptime' => [
                    'is_up' => $project['last_is_up'] !== null ? (bool)$project['last_is_up'] : null,
                    'status_code' => $project['last_status_code'] !== null ? (int)$project['last_status_code'] : null,
                    'response_time_ms' => $project['last_response_time_ms'] !== null ? (int)$project['last_response_time_ms'] : null,
                    'last_checked_at' => $project['last_checked_at'],
                ],
                'created_at' => $project['created_at'],
            ]
        ]);
    }

    public static function get(array $user, int $id): void
    {
        $project = DB::one("SELECT * FROM endpoints WHERE id = :id", ['id' => $id]);
        if (!$project || ($user['role'] !== 'admin' && (int)$project['user_id'] !== (int)$user['id'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Project not found.']);
            return;
        }

        echo json_encode(['success' => true, 'project' => $project]);
    }

    public static function update(array $user, int $id): void
    {
        $project = DB::one("SELECT * FROM endpoints WHERE id = :id", ['id' => $id]);
        if (!$project || ($user['role'] !== 'admin' && (int)$project['user_id'] !== (int)$user['id'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Project not found.']);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = isset($body['name']) ? trim((string)$body['name']) : $project['name'];
        $description = isset($body['description']) ? trim((string)$body['description']) : ($project['description'] ?? '');
        $targetUrl = isset($body['target_url']) ? trim((string)$body['target_url']) : ($project['target_url'] ?? '');
        $retentionDays = isset($body['retention_days']) ? max(1, min(90, (int)$body['retention_days'])) : (int)$project['retention_days'];

        DB::execute("UPDATE endpoints 
                     SET name = :name, description = :description, target_url = :target_url, retention_days = :retention 
                     WHERE id = :id", [
            'name' => $name,
            'description' => $description,
            'target_url' => $targetUrl,
            'retention' => $retentionDays,
            'id' => $id,
        ]);

        if (!empty($targetUrl)) {
            self::probeUrl($id, $targetUrl);
        }

        $updated = DB::one("SELECT * FROM endpoints WHERE id = :id", ['id' => $id]);
        echo json_encode(['success' => true, 'project' => $updated]);
    }

    public static function ping(array $user, int $id): void
    {
        $project = DB::one("SELECT * FROM endpoints WHERE id = :id", ['id' => $id]);
        if (!$project || ($user['role'] !== 'admin' && (int)$project['user_id'] !== (int)$user['id'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Project not found.']);
            return;
        }

        if (empty($project['target_url'])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'No target URL configured for this project.']);
            return;
        }

        $res = self::probeUrl($id, $project['target_url']);
        echo json_encode(['success' => true, 'uptime' => $res]);
    }

    public static function delete(array $user, int $id): void
    {
        $project = DB::one("SELECT * FROM endpoints WHERE id = :id", ['id' => $id]);
        if (!$project || ($user['role'] !== 'admin' && (int)$project['user_id'] !== (int)$user['id'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Project not found.']);
            return;
        }

        DB::execute("DELETE FROM endpoints WHERE id = :id", ['id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Project and associated logs removed.']);
    }

    private static function probeUrl(int $projectId, string $url): array
    {
        $start = microtime(true);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'LogPulse-Probe/1.0',
        ]);
        curl_exec($ch);
        $durationMs = (int)round((microtime(true) - $start) * 1000);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $isUp = ($httpCode >= 200 && $httpCode < 400);
        $now = date('Y-m-d H:i:s');

        DB::execute("UPDATE endpoints 
                     SET last_checked_at = :checked_at,
                         last_status_code = :status_code,
                         last_response_time_ms = :latency,
                         last_is_up = :is_up
                     WHERE id = :id", [
            'checked_at' => $now,
            'status_code' => $httpCode,
            'latency' => $durationMs,
            'is_up' => $isUp ? 1 : 0,
            'id' => $projectId,
        ]);

        return [
            'is_up' => $isUp,
            'status_code' => $httpCode,
            'response_time_ms' => $durationMs,
            'checked_at' => $now,
        ];
    }
}
