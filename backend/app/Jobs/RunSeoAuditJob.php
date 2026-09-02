<?php

namespace App\Jobs;

use App\Exceptions\AuditProcessingException;
use App\Exceptions\CrawlUnavailableException;
use App\Models\Audit;
use App\Services\Audit\AuditProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class RunSeoAuditJob implements ShouldQueue
{
    use Queueable;

    public const GENERIC_FAILURE_REASON = AuditProcessingService::GENERIC_FAILURE_REASON;

    public const OVERLAP_LOCK_SECONDS = 240;

    public int $tries = 2;

    public int $timeout = 180;

    public int $backoff = 30;

    public function __construct(
        public int $auditId
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("seo-audit:{$this->auditId}"))
                ->dontRelease()
                ->expireAfter(self::OVERLAP_LOCK_SECONDS),
        ];
    }

    public function handle(AuditProcessingService $processingService): void
    {
        try {
            $audit = Audit::findOrFail($this->auditId);

            $processingService->process($audit);
        } catch (Throwable $exception) {
            Log::warning('SEO audit attempt failed.', [
                'audit_id' => $this->auditId,
                'exception' => $exception::class,
            ]);

            // Do not retain the original exception as a previous exception:
            // Laravel persists the full exception chain for failed jobs.
            throw new AuditProcessingException(
                $exception instanceof ValidationException,
                $this->safeFailureReason($exception),
            );
        }
    }

    public function failed(Throwable $exception): void
    {
        Audit::query()
            ->whereKey($this->auditId)
            ->where('status', '!=', Audit::STATUS_COMPLETED)
            ->update([
                'status' => Audit::STATUS_FAILED,
                'completed_at' => null,
                'failed_at' => now(),
                'failure_reason' => $this->safeFailureReason($exception),
            ]);

        Log::warning('SEO audit job failed.', [
            'audit_id' => $this->auditId,
            'exception' => $exception::class,
        ]);
    }

    private function safeFailureReason(Throwable $exception): string
    {
        if ($exception instanceof AuditProcessingException) {
            return $exception->failureReason();
        }

        if ($exception instanceof CrawlUnavailableException
            || $exception instanceof ConnectionException
            || $exception instanceof TimeoutExceededException) {
            return CrawlUnavailableException::PUBLIC_MESSAGE;
        }

        return self::GENERIC_FAILURE_REASON;
    }
}
