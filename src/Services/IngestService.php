<?php
declare(strict_types=1);

namespace LogPulse\Services;

use LogPulse\Database\DB;

class IngestService
{
    private const VALID_LEVELS = [
        'DEBUG' => 'DEBUG',
        'INFO' => 'INFO',
        'NOTICE' => 'NOTICE',
        'WARN' => 'WARN',
        'WARNING' => 'WARN',
        'ERR' => 'ERROR',
        'ERROR' => 'ERROR',
        'CRIT' => 'CRITICAL',
        'CRITICAL' => 'CRITICAL',
        'ALERT' => 'ALERT',
        'EMERG' => 'EMERGENCY',
        'EMERGENCY' => 'EMERGENCY',
    ];

    public static function process(array $endpoint, string $rawBody): array
    {
        if (trim($rawBody) === '') {
            return [
                'success' => false,
                'error' => 'Empty request body.',
                'ingested' => 0
            ];
        }

        $decoded = json_decode($rawBody, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            return [
                'success' => false,
                'error' => 'Invalid JSON payload: ' . json_last_error_msg(),
                'ingested' => 0
            ];
        }

        $records = [];
        if (is_array($decoded) && array_is_list($decoded)) {
            $records = $decoded;
        } elseif (is_array($decoded)) {
            $records = [$decoded];
        } else {
            return [
                'success' => false,
                'error' => 'Payload must be a JSON object or array of objects.',
                'ingested' => 0
            ];
        }

        if (count($records) > 500) {
            return [
                'success' => false,
                'error' => 'Batch size limit exceeded. Maximum 500 logs per request.',
                'ingested' => 0
            ];
        }

        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $ip = explode(',', $ip)[0];
        $ip = substr(trim($ip), 0, 45);
        $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown', 0, 255);

        $now = date('Y-m-d H:i:s');
        $endpointId = (int)$endpoint['id'];

        $insertedCount = 0;
        $hasErrorOrCritical = false;

        DB::transaction(function ($pdo) use ($records, $endpointId, $ip, $userAgent, $now, &$insertedCount, &$hasErrorOrCritical) {
            $sql = "INSERT INTO logs (endpoint_id, level, message, context, payload, ip_address, user_agent, created_at)
                    VALUES (:endpoint_id, :level, :message, :context, :payload, :ip_address, :user_agent, :created_at)";
            $stmt = $pdo->prepare($sql);

            foreach ($records as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $rawLevel = strtoupper(trim((string)($item['level'] ?? $item['severity'] ?? $item['type'] ?? 'INFO')));
                $level = self::VALID_LEVELS[$rawLevel] ?? 'INFO';

                if (in_array($level, ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'], true)) {
                    $hasErrorOrCritical = true;
                }

                $msg = $item['message'] ?? $item['msg'] ?? $item['log'] ?? $item['text'] ?? null;
                if ($msg === null) {
                    $msg = json_encode($item, JSON_UNESCAPED_SLASHES);
                } elseif (!is_string($msg)) {
                    $msg = json_encode($msg, JSON_UNESCAPED_SLASHES);
                }

                $timestamp = self::parseTimestamp($item['timestamp'] ?? $item['time'] ?? $item['date'] ?? null, $now);

                $contextData = $item['context'] ?? $item['meta'] ?? $item['metadata'] ?? null;
                $contextJson = $contextData !== null ? json_encode($contextData, JSON_UNESCAPED_SLASHES) : null;
                $payloadJson = json_encode($item, JSON_UNESCAPED_SLASHES);

                $stmt->execute([
                    ':endpoint_id' => $endpointId,
                    ':level' => $level,
                    ':message' => $msg,
                    ':context' => $contextJson,
                    ':payload' => $payloadJson,
                    ':ip_address' => $ip,
                    ':user_agent' => $userAgent,
                    ':created_at' => $timestamp,
                ]);

                $insertedCount++;
            }
        });

        // Trigger alert evaluation if error occurred
        if ($hasErrorOrCritical) {
            AlertService::evaluateForEndpoint($endpointId);
        }

        return [
            'success' => true,
            'ingested' => $insertedCount,
            'endpoint' => $endpoint['slug'],
        ];
    }

    private static function parseTimestamp($value, string $default): string
    {
        if (empty($value)) {
            return $default;
        }

        if (is_numeric($value)) {
            // Unix timestamp in seconds or milliseconds
            $ts = (int)$value;
            if ($ts > 9999999999) {
                $ts = (int)($ts / 1000);
            }
            return date('Y-m-d H:i:s', $ts);
        }

        if (is_string($value)) {
            $parsed = strtotime($value);
            if ($parsed !== false && $parsed > 0) {
                return date('Y-m-d H:i:s', $parsed);
            }
        }

        return $default;
    }
}
