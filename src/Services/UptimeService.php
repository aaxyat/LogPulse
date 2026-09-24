<?php
declare(strict_types=1);

namespace LogPulse\Services;

use LogPulse\Database\DB;

class UptimeService
{
    public static function checkAll(): array
    {
        $monitors = DB::query("SELECT * FROM uptime_monitors WHERE is_active = 1");
        $results = [];

        foreach ($monitors as $m) {
            $results[] = self::checkMonitor($m);
        }

        return $results;
    }

    public static function checkMonitor(array $monitor): array
    {
        $url = $monitor['target_url'];
        $method = strtoupper($monitor['method'] ?? 'GET');
        $expectedStatus = (int)($monitor['expected_status'] ?? 200);
        $timeout = (int)($monitor['timeout_seconds'] ?? 10);

        $start = microtime(true);
        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_NOBODY => ($method === 'HEAD'),
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'LogPulse-Uptime-Monitor/1.0 (+https://logpulse.io)',
        ]);

        $response = curl_exec($ch);
        $durationMs = (int)round((microtime(true) - $start) * 1000);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        $isUp = ($httpCode === $expectedStatus) || ($expectedStatus === 200 && $httpCode >= 200 && $httpCode < 300);
        $errorMessage = !$isUp ? (!empty($curlError) ? $curlError : "Unexpected HTTP Status: {$httpCode} (expected {$expectedStatus})") : null;

        $now = date('Y-m-d H:i:s');

        // Record check run
        DB::execute("INSERT INTO check_runs (monitor_id, status_code, response_time_ms, is_up, error_message, checked_at)
                     VALUES (:monitor_id, :status_code, :response_time_ms, :is_up, :error_message, :checked_at)", [
            'monitor_id' => $monitor['id'],
            'status_code' => $httpCode,
            'response_time_ms' => $durationMs,
            'is_up' => $isUp ? 1 : 0,
            'error_message' => $errorMessage,
            'checked_at' => $now,
        ]);

        // Update monitor summary
        DB::execute("UPDATE uptime_monitors 
                     SET last_checked_at = :checked_at, 
                         last_status_code = :status_code, 
                         last_response_time_ms = :response_time_ms, 
                         last_is_up = :is_up 
                     WHERE id = :id", [
            'checked_at' => $now,
            'status_code' => $httpCode,
            'response_time_ms' => $durationMs,
            'is_up' => $isUp ? 1 : 0,
            'id' => $monitor['id'],
        ]);

        return [
            'monitor_id' => $monitor['id'],
            'name' => $monitor['name'],
            'url' => $url,
            'is_up' => $isUp,
            'status_code' => $httpCode,
            'response_time_ms' => $durationMs,
            'error' => $errorMessage,
            'checked_at' => $now,
        ];
    }
}
