<?php
declare(strict_types=1);

namespace LogPulse\Controllers;

use LogPulse\Database\DB;
use LogPulse\Services\UptimeService;

class UptimeController
{
    public static function list(array $user): void
    {
        $isAdmin = ($user['role'] === 'admin');
        if ($isAdmin) {
            $monitors = DB::query("SELECT m.*, u.name as owner_name,
                                   (SELECT COUNT(*) FROM check_runs c WHERE c.monitor_id = m.id) as total_checks,
                                   (SELECT ROUND(AVG(is_up) * 100, 1) FROM check_runs c WHERE c.monitor_id = m.id AND c.checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as uptime_7d
                                   FROM uptime_monitors m 
                                   LEFT JOIN users u ON m.user_id = u.id 
                                   ORDER BY m.created_at DESC");
        } else {
            $monitors = DB::query("SELECT m.*,
                                   (SELECT COUNT(*) FROM check_runs c WHERE c.monitor_id = m.id) as total_checks,
                                   (SELECT ROUND(AVG(is_up) * 100, 1) FROM check_runs c WHERE c.monitor_id = m.id AND c.checked_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) as uptime_7d
                                   FROM uptime_monitors m 
                                   WHERE m.user_id = :uid 
                                   ORDER BY m.created_at DESC", [
                'uid' => $user['id']
            ]);
        }

        echo json_encode(['success' => true, 'monitors' => $monitors]);
    }

    public static function create(array $user): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim((string)($body['name'] ?? ''));
        $targetUrl = trim((string)($body['target_url'] ?? ''));
        $rawMethod = isset($body['method']) && is_string($body['method']) ? strtoupper($body['method']) : 'GET';
        $method = in_array($rawMethod, ['GET', 'POST', 'HEAD'], true) ? $rawMethod : 'GET';
        $expectedStatus = (int)($body['expected_status'] ?? 200);
        $checkInterval = max(1, min(60, (int)($body['check_interval_minutes'] ?? 5)));
        $timeout = max(2, min(30, (int)($body['timeout_seconds'] ?? 10)));

        if (empty($name) || !filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Valid monitor name and target URL (http/https) required.']);
            return;
        }

        DB::execute("INSERT INTO uptime_monitors (user_id, name, target_url, method, expected_status, check_interval_minutes, timeout_seconds, is_active, created_at)
                     VALUES (:user_id, :name, :target_url, :method, :expected_status, :interval, :timeout, 1, :created_at)", [
            'user_id' => $user['id'],
            'name' => $name,
            'target_url' => $targetUrl,
            'method' => $method,
            'expected_status' => $expectedStatus,
            'interval' => $checkInterval,
            'timeout' => $timeout,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $newId = (int)DB::lastInsertId();
        $monitor = DB::one("SELECT * FROM uptime_monitors WHERE id = :id", ['id' => $newId]);

        // Execute first probe immediately
        $pingResult = UptimeService::checkMonitor($monitor);

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'monitor' => $monitor,
            'initial_probe' => $pingResult
        ]);
    }

    public static function pingNow(array $user, int $id): void
    {
        $monitor = DB::one("SELECT * FROM uptime_monitors WHERE id = :id", ['id' => $id]);
        if (!$monitor || ($user['role'] !== 'admin' && (int)$monitor['user_id'] !== (int)$user['id'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Monitor not found.']);
            return;
        }

        $result = UptimeService::checkMonitor($monitor);
        echo json_encode(['success' => true, 'result' => $result]);
    }

    public static function history(array $user, int $id): void
    {
        $monitor = DB::one("SELECT * FROM uptime_monitors WHERE id = :id", ['id' => $id]);
        if (!$monitor || ($user['role'] !== 'admin' && (int)$monitor['user_id'] !== (int)$user['id'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Monitor not found.']);
            return;
        }

        $runs = DB::query("SELECT * FROM check_runs WHERE monitor_id = :id ORDER BY id DESC LIMIT 50", ['id' => $id]);
        echo json_encode(['success' => true, 'runs' => $runs]);
    }

    public static function delete(array $user, int $id): void
    {
        $monitor = DB::one("SELECT * FROM uptime_monitors WHERE id = :id", ['id' => $id]);
        if (!$monitor || ($user['role'] !== 'admin' && (int)$monitor['user_id'] !== (int)$user['id'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Monitor not found.']);
            return;
        }

        DB::execute("DELETE FROM uptime_monitors WHERE id = :id", ['id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Monitor deleted.']);
    }
}
