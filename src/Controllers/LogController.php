<?php
declare(strict_types=1);

namespace LogPulse\Controllers;

use LogPulse\Auth\IngestAuth;
use LogPulse\Database\DB;
use LogPulse\Services\IngestService;
use LogPulse\Services\RetentionService;

class LogController
{
    public static function ingest(?string $pathToken = null): void
    {
        $endpoint = IngestAuth::authenticate($pathToken);
        if (!$endpoint) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'error' => 'Unauthorized: Invalid or inactive Ingest Token. Provide token in URL path /api/v1/ingest/{token} or Authorization header.'
            ]);
            return;
        }

        $rawBody = file_get_contents('php://input');
        $result = IngestService::process($endpoint, $rawBody);

        http_response_code($result['success'] ? 200 : 400);
        echo json_encode($result);
    }

    public static function list(array $user): void
    {
        $endpointId = isset($_GET['endpoint_id']) && is_numeric($_GET['endpoint_id']) ? (int)$_GET['endpoint_id'] : null;
        $level = !empty($_GET['level']) && strtoupper((string)$_GET['level']) !== 'ALL' ? strtoupper((string)$_GET['level']) : null;
        $query = !empty($_GET['q']) ? trim((string)$_GET['q']) : null;
        $sinceId = isset($_GET['since_id']) && is_numeric($_GET['since_id']) ? (int)$_GET['since_id'] : null;
        $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $offset = ($page - 1) * $limit;

        // Permissions check
        $accessibleEndpointIds = [];
        if ($user['role'] === 'admin') {
            if ($endpointId !== null) {
                $accessibleEndpointIds = [$endpointId];
            }
        } else {
            $userEndpoints = DB::query("SELECT id FROM endpoints WHERE user_id = :uid", ['uid' => $user['id']]);
            $allUserEpIds = array_map(fn($e) => (int)$e['id'], $userEndpoints);
            if (empty($allUserEpIds)) {
                echo json_encode([
                    'success' => true,
                    'logs' => [],
                    'total' => 0,
                    'page' => 1,
                    'limit' => $limit,
                    'has_more' => false,
                ]);
                return;
            }

            if ($endpointId !== null) {
                if (!in_array($endpointId, $allUserEpIds, true)) {
                    http_response_code(403);
                    echo json_encode(['success' => false, 'error' => 'Access denied to this endpoint.']);
                    return;
                }
                $accessibleEndpointIds = [$endpointId];
            } else {
                $accessibleEndpointIds = $allUserEpIds;
            }
        }

        $conditions = [];
        $params = [];

        if (!empty($accessibleEndpointIds)) {
            $inPlaceholders = implode(',', array_map(fn($i) => ":ep_{$i}", array_keys($accessibleEndpointIds)));
            $conditions[] = "l.endpoint_id IN ({$inPlaceholders})";
            foreach ($accessibleEndpointIds as $i => $id) {
                $params["ep_{$i}"] = $id;
            }
        }

        if ($sinceId !== null) {
            $conditions[] = "l.id > :since_id";
            $params['since_id'] = $sinceId;
        }

        if ($level !== null) {
            $conditions[] = "l.level = :level";
            $params['level'] = $level;
        }

        if ($query !== null) {
            $conditions[] = "(l.message LIKE :q OR l.level LIKE :q)";
            $params['q'] = '%' . $query . '%';
        }

        $whereClause = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        // Count total for pagination if not streaming since_id
        $total = 0;
        if ($sinceId === null) {
            $countSql = "SELECT COUNT(*) as cnt FROM logs l {$whereClause}";
            $countRow = DB::one($countSql, $params);
            $total = (int)($countRow['cnt'] ?? 0);
        }

        // Fetch logs with endpoint metadata
        $sql = "SELECT l.id, l.endpoint_id, l.level, l.message, l.created_at, l.ip_address, e.name as endpoint_name, e.slug as endpoint_slug
                FROM logs l 
                LEFT JOIN endpoints e ON l.endpoint_id = e.id 
                {$whereClause} 
                ORDER BY l.id DESC 
                LIMIT {$limit}";

        if ($sinceId === null && $offset > 0) {
            $sql .= " OFFSET {$offset}";
        }

        $logs = DB::query($sql, $params);

        echo json_encode([
            'success' => true,
            'logs' => $logs,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'has_more' => ($sinceId === null) && (($page * $limit) < $total),
            'latest_id' => !empty($logs) ? (int)$logs[0]['id'] : $sinceId,
        ]);
    }

    public static function get(array $user, int $id): void
    {
        $sql = "SELECT l.*, e.name as endpoint_name, e.slug as endpoint_slug, e.user_id as endpoint_user_id 
                FROM logs l 
                LEFT JOIN endpoints e ON l.endpoint_id = e.id 
                WHERE l.id = :id LIMIT 1";
        $log = DB::one($sql, ['id' => $id]);

        if (!$log) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Log entry not found.']);
            return;
        }

        if ($user['role'] !== 'admin' && (int)$log['endpoint_user_id'] !== (int)$user['id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Access denied.']);
            return;
        }

        $context = $log['context'] ? json_decode($log['context'], true) : null;
        $payload = $log['payload'] ? json_decode($log['payload'], true) : null;

        echo json_encode([
            'success' => true,
            'log' => [
                'id' => (int)$log['id'],
                'endpoint_id' => (int)$log['endpoint_id'],
                'endpoint_name' => $log['endpoint_name'],
                'endpoint_slug' => $log['endpoint_slug'],
                'level' => $log['level'],
                'message' => $log['message'],
                'context' => $context,
                'payload' => $payload,
                'ip_address' => $log['ip_address'],
                'user_agent' => $log['user_agent'],
                'created_at' => $log['created_at'],
            ]
        ]);
    }

    public static function prune(array $user, int $endpointId): void
    {
        $endpoint = DB::one("SELECT * FROM endpoints WHERE id = :id", ['id' => $endpointId]);
        if (!$endpoint || ($user['role'] !== 'admin' && (int)$endpoint['user_id'] !== (int)$user['id'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Endpoint not found.']);
            return;
        }

        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $days = max(1, (int)($body['keep_days'] ?? $endpoint['retention_days']));

        $deleted = RetentionService::pruneEndpoint($endpointId, $days);

        echo json_encode([
            'success' => true,
            'deleted_count' => $deleted,
            'message' => "Successfully pruned {$deleted} logs older than {$days} days."
        ]);
    }
}
