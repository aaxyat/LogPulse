<?php
declare(strict_types=1);

namespace LogPulse\Services;

use LogPulse\Database\DB;

class RetentionService
{
    public static function pruneAll(): array
    {
        $endpoints = DB::query("SELECT id, name, slug, retention_days FROM endpoints");
        $results = [];

        foreach ($endpoints as $ep) {
            $days = max(1, (int)$ep['retention_days']);
            $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

            $deleted = DB::execute("DELETE FROM logs WHERE endpoint_id = :id AND created_at < :cutoff", [
                'id' => $ep['id'],
                'cutoff' => $cutoff
            ]);

            $results[] = [
                'endpoint' => $ep['slug'],
                'retention_days' => $days,
                'deleted_logs' => $deleted
            ];
        }

        // Also prune check runs older than 30 days
        $checkCutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
        $deletedChecks = DB::execute("DELETE FROM check_runs WHERE checked_at < :cutoff", [
            'cutoff' => $checkCutoff
        ]);

        return [
            'endpoints' => $results,
            'pruned_uptime_checks' => $deletedChecks,
        ];
    }

    public static function pruneEndpoint(int $endpointId, int $keepDays): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$keepDays} days"));
        return DB::execute("DELETE FROM logs WHERE endpoint_id = :id AND created_at < :cutoff", [
            'id' => $endpointId,
            'cutoff' => $cutoff
        ]);
    }
}
