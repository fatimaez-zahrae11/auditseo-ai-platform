<?php

namespace App\Jobs;

use App\Models\Audit;
use App\Services\Audit\AuditProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunSeoAuditJob implements ShouldQueue
{
    use Queueable;

    public const GENERIC_FAILURE_REASON = AuditProcessingService::GENERIC_FAILURE_REASON;

    public int $tries = 2;

    public int $timeout = 180;

    public int $backoff = 30;

    public function __construct(
        public int $auditId
    ) {}

    public function handle(AuditProcessingService $processingService): void
    {
        $audit = Audit::findOrFail($this->auditId);

        $processingService->process($audit);
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
                'failure_reason' => self::GENERIC_FAILURE_REASON,
            ]);

        Log::warning('SEO audit job failed.', [
            'audit_id' => $this->auditId,
            'exception' => $exception::class,
        ]);
    }
}
