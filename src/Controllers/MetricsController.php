<?php
declare(strict_types=1);

namespace LogPulse\Controllers;

use LogPulse\Database\DB;

class MetricsController
{
    public static function dashboardStats(array $user): void
    {
        $userId = (int)$user['id'];
        $isAdmin = ($user['role'] === 'admin');

        $endpointFilter = '';
        $params = [];

        if (!$isAdmin) {
            $endpointFilter = "WHERE endpoint_id IN (SELECT id FROM endpoints WHERE user_id = :uid)";
            $params['uid'] = $userId;
        }

        // 1. Total logs in last 24h
        $since24h = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $total24hSql = "SELECT COUNT(*) as total, 
                               SUM(CASE WHEN level IN ('ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY') THEN 1 ELSE 0 END) as errors,
                               SUM(CASE WHEN level = 'INFO' THEN 1 ELSE 0 END) as info_count,
                               SUM(CASE WHEN level = 'WARN' THEN 1 ELSE 0 END) as warn_count,
                               SUM(CASE WHEN level = 'DEBUG' THEN 1 ELSE 0 END) as debug_count
                        FROM logs 
                        " . ($endpointFilter ? "{$endpointFilter} AND created_at >= :since" : "WHERE created_at >= :since");
        $params24h = array_merge($params, ['since' => $since24h]);
        $stats24h = DB::one($total24hSql, $params24h);

        $totalLogs24h = (int)($stats24h['total'] ?? 0);
        $totalErrors24h = (int)($stats24h['errors'] ?? 0);
        $errorRate = $totalLogs24h > 0 ? round(($totalErrors24h / $totalLogs24h) * 100, 2) : 0.0;

        // 2. Active endpoints
        $epSql = $isAdmin ? "SELECT COUNT(*) as total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active FROM endpoints" 
                         : "SELECT COUNT(*) as total, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active FROM endpoints WHERE user_id = :uid";
        $epStats = DB::one($epSql, $params);
        $totalEndpoints = (int)($epStats['total'] ?? 0);
        $activeEndpoints = (int)($epStats['active'] ?? 0);

        // 3. Hourly histogram for Chart.js (last 12 data points)
        $hourlyPoints = [];
        for ($i = 11; $i >= 0; $i--) {
            $startHour = date('Y-m-d H:00:00', strtotime("-{$i} hours"));
            $endHour = date('Y-m-d H:59:59', strtotime("-{$i} hours"));
            $label = date('H:i', strtotime("-{$i} hours"));

            $hSql = "SELECT COUNT(*) as total, 
                            SUM(CASE WHEN level IN ('ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY') THEN 1 ELSE 0 END) as errors
                     FROM logs " . 
                     ($endpointFilter ? "{$endpointFilter} AND created_at >= :start AND created_at <= :end" 
                                      : "WHERE created_at >= :start AND created_at <= :end");
            $hParams = array_merge($params, ['start' => $startHour, 'end' => $endHour]);
            $hRow = DB::one($hSql, $hParams);

            $hourlyPoints[] = [
                'time' => $label,
                'total' => (int)($hRow['total'] ?? 0),
                'errors' => (int)($hRow['errors'] ?? 0),
            ];
        }

        // 4. Uptime monitors quick status
        $uptimeSql = $isAdmin ? "SELECT COUNT(*) as total, SUM(CASE WHEN last_is_up = 1 THEN 1 ELSE 0 END) as up_count FROM uptime_monitors WHERE is_active = 1"
                              : "SELECT COUNT(*) as total, SUM(CASE WHEN last_is_up = 1 THEN 1 ELSE 0 END) as up_count FROM uptime_monitors WHERE is_active = 1 AND user_id = :uid";
        $uptimeStats = DB::one($uptimeSql, $params);
        $totalMonitors = (int)($uptimeStats['total'] ?? 0);
        $upMonitors = (int)($uptimeStats['up_count'] ?? 0);

        echo json_encode([
            'success' => true,
            'metrics' => [
                'total_logs_24h' => $totalLogs24h,
                'total_errors_24h' => $totalErrors24h,
                'error_rate_pct' => $errorRate,
                'active_endpoints' => $activeEndpoints,
                'total_endpoints' => $totalEndpoints,
                'uptime_monitors_up' => $upMonitors,
                'uptime_monitors_total' => $totalMonitors,
                'level_counts' => [
                    'INFO' => (int)($stats24h['info_count'] ?? 0),
                    'WARN' => (int)($stats24h['warn_count'] ?? 0),
                    'ERROR' => $totalErrors24h,
                    'DEBUG' => (int)($stats24h['debug_count'] ?? 0),
                ],
                'timeline' => $hourlyPoints,
            ]
        ]);
    }
}
