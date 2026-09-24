<?php
declare(strict_types=1);

namespace LogPulse\Controllers;

use LogPulse\Database\DB;
use LogPulse\Services\AlertService;

class AlertController
{
    public static function list(array $user): void
    {
        $isAdmin = ($user['role'] === 'admin');
        if ($isAdmin) {
            $rules = DB::query("SELECT a.*, e.name as endpoint_name, e.slug as endpoint_slug 
                                FROM alert_rules a 
                                LEFT JOIN endpoints e ON a.endpoint_id = e.id 
                                ORDER BY a.id DESC");
        } else {
            $rules = DB::query("SELECT a.*, e.name as endpoint_name, e.slug as endpoint_slug 
                                FROM alert_rules a 
                                LEFT JOIN endpoints e ON a.endpoint_id = e.id 
                                WHERE a.user_id = :uid 
                                ORDER BY a.id DESC", [
                'uid' => $user['id']
            ]);
        }

        $history = DB::query("SELECT h.*, r.name as rule_name 
                              FROM alert_history h 
                              JOIN alert_rules r ON h.rule_id = r.id 
                              " . ($isAdmin ? "" : "WHERE r.user_id = :uid") . " 
                              ORDER BY h.id DESC LIMIT 20", 
                              $isAdmin ? [] : ['uid' => $user['id']]);

        echo json_encode(['success' => true, 'rules' => $rules, 'recent_history' => $history]);
    }

    public static function create(array $user): void
    {
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        $name = trim((string)($body['name'] ?? ''));
        $endpointId = !empty($body['endpoint_id']) ? (int)$body['endpoint_id'] : null;
        $levelThreshold = strtoupper(trim((string)($body['level_threshold'] ?? 'ERROR')));
        $countThreshold = max(1, (int)($body['count_threshold'] ?? 5));
        $windowMinutes = max(1, min(1440, (int)($body['time_window_minutes'] ?? 5)));
        $channelType = in_array($body['channel_type'] ?? '', ['webhook', 'slack', 'discord', 'email'], true) 
                        ? $body['channel_type'] : 'webhook';
        $targetDestination = trim((string)($body['target_destination'] ?? ''));

        if (empty($name) || empty($targetDestination)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Rule name and target destination (URL or email) are required.']);
            return;
        }

        if ($channelType === 'email' && !filter_var($targetDestination, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid email destination address.']);
            return;
        }

        if ($channelType !== 'email' && !filter_var($targetDestination, FILTER_VALIDATE_URL)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid webhook target URL.']);
            return;
        }

        DB::execute("INSERT INTO alert_rules (user_id, endpoint_id, name, level_threshold, count_threshold, time_window_minutes, channel_type, target_destination, is_active, created_at)
                     VALUES (:user_id, :endpoint_id, :name, :level, :count, :window, :channel, :target, 1, :created_at)", [
            'user_id' => $user['id'],
            'endpoint_id' => $endpointId,
            'name' => $name,
            'level' => $levelThreshold,
            'count' => $countThreshold,
            'window' => $windowMinutes,
            'channel' => $channelType,
            'target' => $targetDestination,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $newId = (int)DB::lastInsertId();
        $rule = DB::one("SELECT * FROM alert_rules WHERE id = :id", ['id' => $newId]);

        http_response_code(201);
        echo json_encode(['success' => true, 'rule' => $rule]);
    }

    public static function delete(array $user, int $id): void
    {
        $rule = DB::one("SELECT * FROM alert_rules WHERE id = :id", ['id' => $id]);
        if (!$rule || ($user['role'] !== 'admin' && (int)$rule['user_id'] !== (int)$user['id'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Alert rule not found.']);
            return;
        }

        DB::execute("DELETE FROM alert_rules WHERE id = :id", ['id' => $id]);
        echo json_encode(['success' => true, 'message' => 'Alert rule deleted.']);
    }

    public static function test(array $user, int $id): void
    {
        $rule = DB::one("SELECT * FROM alert_rules WHERE id = :id", ['id' => $id]);
        if (!$rule || ($user['role'] !== 'admin' && (int)$rule['user_id'] !== (int)$user['id'])) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Alert rule not found.']);
            return;
        }

        AlertService::dispatchAlert($rule, 1, date('Y-m-d H:i:s', time() - 300), $rule['endpoint_id'] !== null ? (int)$rule['endpoint_id'] : null);
        echo json_encode(['success' => true, 'message' => "Test alert sent to {$rule['channel_type']} destination."]);
    }
}
