<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthCheckController extends Controller
{
    public function index(): JsonResponse
    {
        try {
            DB::select('select 1');
        } catch (Throwable) {
            return response()->json([
                'status' => 'degraded',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function readiness(): JsonResponse
    {
        $databaseStatus = 'error';
        $auditQueueStatus = 'not_checked';
        $auditCounts = null;

        try {
            DB::select('select 1');

            $auditCounts = $this->auditQueueRiskCounts();
            $databaseStatus = 'ok';
            $auditQueueStatus = $auditCounts['stale_pending'] > 0
                || $auditCounts['stale_running'] > 0
                    ? 'at_risk'
                    : 'ok';
        } catch (Throwable) {
            // Readiness responses must never include database exception details.
        }

        $redisStatus = $this->redisStatus();
        $ready = $databaseStatus === 'ok'
            && $redisStatus !== 'error'
            && $auditQueueStatus === 'ok';

        $body = [
            'status' => $ready ? 'ready' : 'not_ready',
            'checks' => [
                'database' => $databaseStatus,
                'redis' => $redisStatus,
                'audit_queue' => $auditQueueStatus,
            ],
        ];

        if ($auditCounts !== null) {
            $body['audit_counts'] = $auditCounts;
        }

        return response()->json($body, $ready ? 200 : 503);
    }

    /**
     * @return array{stale_pending: int, stale_running: int, recent_failed: int}
     */
    private function auditQueueRiskCounts(): array
    {
        $pendingThreshold = max(1, (int) config('health.audit_queue.stale_pending_minutes', 10));
        $runningThreshold = max(1, (int) config('health.audit_queue.stale_running_minutes', 5));
        $failedWindow = max(1, (int) config('health.audit_queue.recent_failed_minutes', 60));

        return [
            'stale_pending' => Audit::query()
                ->where('status', Audit::STATUS_PENDING)
                ->where('created_at', '<=', now()->subMinutes($pendingThreshold))
                ->count(),
            'stale_running' => Audit::query()
                ->where('status', Audit::STATUS_RUNNING)
                ->where('started_at', '<=', now()->subMinutes($runningThreshold))
                ->count(),
            'recent_failed' => Audit::query()
                ->where('status', Audit::STATUS_FAILED)
                ->where('failed_at', '>=', now()->subMinutes($failedWindow))
                ->count(),
        ];
    }

    private function redisStatus(): string
    {
        $connections = $this->requiredRedisConnections();

        if ($connections === []) {
            return 'not_required';
        }

        try {
            foreach ($connections as $connection) {
                Redis::connection($connection)->command('ping');
            }
        } catch (Throwable) {
            return 'error';
        }

        return 'ok';
    }

    /**
     * @return list<string>
     */
    private function requiredRedisConnections(): array
    {
        $connections = [];
        $queue = (string) config('queue.default');
        $cache = (string) config('cache.default');

        if (config("queue.connections.{$queue}.driver") === 'redis') {
            $connections[] = (string) config("queue.connections.{$queue}.connection", 'default');
        }

        if (config("cache.stores.{$cache}.driver") === 'redis') {
            $connections[] = (string) config("cache.stores.{$cache}.connection", 'cache');
        }

        return array_values(array_unique($connections));
    }
}
