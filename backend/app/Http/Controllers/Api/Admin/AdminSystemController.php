<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AccessLog;
use App\Models\AdminActionLog;
use App\Models\Audit;
use App\Services\AdminActionLogger;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class AdminSystemController extends Controller
{
    private const DEFAULT_LOG_LINES = 100;

    private const MAX_LOG_LINES = 200;

    private const MAX_LOG_BYTES = 1_048_576;

    private const MAX_LOG_LINE_LENGTH = 2_000;

    public function logs(Request $request, AdminActionLogger $actionLogger): JsonResponse
    {
        $requestedLines = filter_var(
            $request->query('lines'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $limit = min(
            $requestedLines === false ? self::DEFAULT_LOG_LINES : $requestedLines,
            self::MAX_LOG_LINES,
        );
        $path = storage_path('logs/laravel.log');

        // This fixed path is intentional: request input must never select a server file.
        $logLines = $this->latestLines($path, $limit);
        $exists = is_file($path);
        $actionLogger->log(
            $request->user(),
            AdminActionLog::ACTION_SYSTEM_LOGS_VIEWED,
            metadata: ['lines_returned' => count($logLines)],
            request: $request,
        );

        // System details are safe only behind the auth:sanctum, active, and admin route group.
        return response()->json([
            'lines' => $logLines,
            'count' => count($logLines),
            'generated_at' => CarbonImmutable::now(),
            'note' => $exists
                ? 'Log output is redacted, line-limited, and returned newest first.'
                : 'The Laravel application log does not exist; no log lines are available.',
        ]);
    }

    public function healthDetailed(
        Request $request,
        AdminActionLogger $actionLogger,
    ): JsonResponse {
        $generatedAt = CarbonImmutable::now();
        $queueConnection = $this->safeConfigName(config('queue.default'));
        $cacheDriver = $this->safeConfigName(config('cache.default'));
        $databaseStatus = 'unavailable';
        $auditCounts = [
            'stale_pending' => null,
            'stale_running' => null,
            'recent_failed' => null,
        ];
        $failedJobs = null;
        $accessLogs = null;

        try {
            DB::select('select 1');
            $databaseStatus = 'ok';
            $auditCounts = $this->auditRiskCounts($generatedAt);
            $failedJobs = $this->recentFailedJobs($generatedAt);
            $accessLogs = $this->recentAccessLogs($generatedAt);
        } catch (Throwable) {
            // Operational exception messages and traces must never enter this response.
        }

        $redisStatus = $this->redisStatus($queueConnection, $cacheDriver);
        $actionLogger->log(
            $request->user(),
            AdminActionLog::ACTION_SYSTEM_HEALTH_VIEWED,
            request: $request,
        );

        return response()->json([
            'app_env' => $this->safeConfigName(app()->environment()),
            'debug_enabled' => (bool) config('app.debug', false),
            'database_status' => $databaseStatus,
            'redis_status' => $redisStatus,
            'cache_status' => $this->cacheStatus($cacheDriver, $redisStatus),
            'queue_connection' => $queueConnection,
            'cache_driver' => $cacheDriver,
            'stale_pending_audits' => $auditCounts['stale_pending'],
            'stale_running_audits' => $auditCounts['stale_running'],
            'recent_failed_audits' => $auditCounts['recent_failed'],
            'recent_failed_jobs' => $failedJobs,
            'access_logs_last_24h' => $accessLogs,
            'generated_at' => $generatedAt,
        ]);
    }

    /**
     * @return list<string>
     */
    private function latestLines(string $path, int $limit): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            return [];
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        try {
            if (fseek($handle, 0, SEEK_END) !== 0) {
                return [];
            }

            $size = ftell($handle);

            if ($size === false || $size === 0) {
                return [];
            }

            $bytes = min($size, self::MAX_LOG_BYTES);

            if (fseek($handle, -$bytes, SEEK_END) !== 0) {
                return [];
            }

            $contents = fread($handle, $bytes);

            if ($contents === false) {
                return [];
            }
        } finally {
            fclose($handle);
        }

        if ($bytes < $size) {
            $firstNewline = strpos($contents, "\n");
            $contents = $firstNewline === false ? '' : substr($contents, $firstNewline + 1);
        }

        $lines = preg_split('/\R/u', $contents) ?: [];

        if ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return collect(array_slice($lines, -$limit))
            ->reverse()
            ->map(fn (string $line): string => $this->redactLogLine($line))
            ->values()
            ->all();
    }

    private function redactLogLine(string $line): string
    {
        $line = Str::limit($line, 16_000, '…');
        $patterns = [
            '~\b(?:postgres(?:ql)?|mysql|mariadb|redis|rediss|mongodb(?:\+srv)?):\/\/[^\s"\'<>]+~i' => '[REDACTED_DSN]',
            '~(["\']?(?:Authorization|Proxy-Authorization)["\']?\s*[:=]\s*)(["\'])(?:Bearer\s+)?[^"\']*\2~i' => '$1$2[REDACTED]$2',
            '~((?:Authorization|Proxy-Authorization)\s*[:=]\s*)(?:Bearer\s+)?[^\s,}\]]+~i' => '$1[REDACTED]',
            '~\bBearer\s+[A-Za-z0-9._\~+\/=\-]+~i' => 'Bearer [REDACTED]',
            '~((?:Cookie|Set-Cookie)\s*[:=]\s*)[^\r\n]+~i' => '$1[REDACTED]',
            '~("?(?:x[_-]?)?api[_-]?key"?\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s,}\]]+)~i' => '$1[REDACTED]',
            '~("?(?:password|passwd|pwd|(?:client[_-]?)?secret|(?:access[_-]?|refresh[_-]?|auth[_-]?|id[_-]?)?token|db[_-]?(?:password|username|user))"?\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s,}\]]+)~i' => '$1[REDACTED]',
            '~\b([A-Z][A-Z0-9_]{2,}\s*=\s*)(?!\[REDACTED(?:_DSN|_PATH)?\])("[^"]*"|\'[^\']*\'|[^\s,;]+)~' => '$1[REDACTED]',
            '~\b[A-Za-z]:\\\\[^\s"\']+~' => '[REDACTED_PATH]',
            '~(?<![A-Za-z0-9])/(?:var|home|srv|opt|app|workspace|Users?)/[^\s"\']+~i' => '[REDACTED_PATH]',
        ];

        $redacted = preg_replace(array_keys($patterns), array_values($patterns), $line);

        return Str::limit($redacted ?? '[REDACTED]', self::MAX_LOG_LINE_LENGTH, '…');
    }

    /**
     * @return array{stale_pending: int|null, stale_running: int|null, recent_failed: int|null}
     */
    private function auditRiskCounts(CarbonImmutable $now): array
    {
        if (! Schema::hasTable('audits')) {
            return [
                'stale_pending' => null,
                'stale_running' => null,
                'recent_failed' => null,
            ];
        }

        $pendingThreshold = max(1, (int) config('health.audit_queue.stale_pending_minutes', 10));
        $runningThreshold = max(1, (int) config('health.audit_queue.stale_running_minutes', 15));
        $failedWindow = max(1, (int) config('health.audit_queue.recent_failed_minutes', 60));

        return [
            'stale_pending' => Audit::query()
                ->where('status', Audit::STATUS_PENDING)
                ->where('created_at', '<=', $now->subMinutes($pendingThreshold))
                ->count(),
            'stale_running' => Audit::query()
                ->where('status', Audit::STATUS_RUNNING)
                ->where('started_at', '<=', $now->subMinutes($runningThreshold))
                ->count(),
            'recent_failed' => Audit::query()
                ->where('status', Audit::STATUS_FAILED)
                ->where('failed_at', '>=', $now->subMinutes($failedWindow))
                ->count(),
        ];
    }

    private function recentFailedJobs(CarbonImmutable $now): ?int
    {
        if (! Schema::hasTable('failed_jobs')) {
            return null;
        }

        $window = max(1, (int) config('health.audit_queue.recent_failed_minutes', 60));

        return DB::table('failed_jobs')
            ->where('failed_at', '>=', $now->subMinutes($window))
            ->count();
    }

    private function recentAccessLogs(CarbonImmutable $now): ?int
    {
        if (! Schema::hasTable('access_logs')) {
            return null;
        }

        return AccessLog::query()
            ->where('created_at', '>=', $now->subDay())
            ->count();
    }

    private function redisStatus(string $queueConnection, string $cacheDriver): string
    {
        $connections = [];

        if (config("queue.connections.{$queueConnection}.driver") === 'redis') {
            $connections[] = $this->safeConfigName(
                config("queue.connections.{$queueConnection}.connection", 'default'),
            );
        }

        if (config("cache.stores.{$cacheDriver}.driver") === 'redis') {
            $connections[] = $this->safeConfigName(
                config("cache.stores.{$cacheDriver}.connection", 'cache'),
            );
        }

        if ($connections === []) {
            return 'not_required';
        }

        try {
            foreach (array_unique($connections) as $connection) {
                Redis::connection($connection)->command('ping');
            }
        } catch (Throwable) {
            return 'unavailable';
        }

        return 'ok';
    }

    private function cacheStatus(string $cacheDriver, string $redisStatus): string
    {
        if (config("cache.stores.{$cacheDriver}.driver") === 'redis') {
            return $redisStatus;
        }

        try {
            Cache::store($cacheDriver)->get('__admin_system_health_probe__');
        } catch (Throwable) {
            return 'unavailable';
        }

        return 'ok';
    }

    private function safeConfigName(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/\A[A-Za-z0-9_.-]{1,50}\z/', $value) === 1
            ? $value
            : 'custom';
    }
}
