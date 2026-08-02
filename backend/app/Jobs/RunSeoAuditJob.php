<?php

namespace App\Jobs;

use App\Models\Audit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunSeoAuditJob implements ShouldQueue
{
    use Queueable;

    public const GENERIC_FAILURE_REASON = 'Audit processing failed.';

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public int $auditId
    ) {}

    public function handle(): void
    {
        $audit = Audit::findOrFail($this->auditId);

        $audit->update([
            'status' => Audit::STATUS_RUNNING,
            'started_at' => now(),
            'completed_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
        ]);

        // Next step:
        // Move the existing SEO crawl/scoring/issue generation logic here.

        $audit->update([
            'status' => Audit::STATUS_COMPLETED,
            'completed_at' => now(),
            'failed_at' => null,
            'failure_reason' => null,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $audit = Audit::find($this->auditId);

        if ($audit) {
            $audit->update([
                'status' => Audit::STATUS_FAILED,
                'completed_at' => null,
                'failed_at' => now(),
                'failure_reason' => self::GENERIC_FAILURE_REASON,
            ]);
        }

        Log::warning('SEO audit job failed.', [
            'audit_id' => $this->auditId,
            'exception' => $exception::class,
        ]);
    }
}
