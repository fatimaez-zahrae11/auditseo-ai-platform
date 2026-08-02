<?php

namespace Tests\Feature;

use App\Jobs\RunSeoAuditJob;
use App\Models\Audit;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class AuditQueueArchitectureTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_queue_fields_are_available_with_safe_defaults_and_casts(): void
    {
        $audit = $this->createAudit();

        $this->assertSame(Audit::STATUS_PENDING, $audit->status);
        $this->assertNull($audit->started_at);
        $this->assertNull($audit->completed_at);
        $this->assertNull($audit->failed_at);
        $this->assertNull($audit->failure_reason);

        $audit->update([
            'status' => Audit::STATUS_RUNNING,
            'started_at' => now(),
            'completed_at' => now(),
            'failed_at' => now(),
            'failure_reason' => 'Internal failure detail.',
        ]);
        $audit->refresh();

        $this->assertSame(Audit::STATUS_RUNNING, $audit->status);
        $this->assertInstanceOf(\DateTimeInterface::class, $audit->started_at);
        $this->assertInstanceOf(\DateTimeInterface::class, $audit->completed_at);
        $this->assertInstanceOf(\DateTimeInterface::class, $audit->failed_at);
        $this->assertIsArray($audit->raw_data);
        $this->assertArrayNotHasKey('failure_reason', $audit->toArray());

        $databaseDefaultAuditId = DB::table('audits')->insertGetId([
            'domain_id' => $audit->domain_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame(
            Audit::STATUS_PENDING,
            DB::table('audits')->where('id', $databaseDefaultAuditId)->value('status'),
        );
        $this->assertTrue(collect(Schema::getIndexes('audits'))->contains(
            fn (array $index): bool => $index['columns'] === ['status'],
        ));
    }

    public function test_audit_status_migration_can_be_reversed_and_reapplied_on_sqlite(): void
    {
        $this->assertTrue(Schema::hasColumns('audits', [
            'status',
            'started_at',
            'completed_at',
            'failed_at',
            'failure_reason',
        ]));

        $migration = require database_path('migrations/2026_08_02_205436_add_queue_status_fields_to_audits_table.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('audits', 'status'));
        $this->assertFalse(Schema::hasColumn('audits', 'failure_reason'));

        $migration->up();

        $this->assertTrue(Schema::hasColumns('audits', [
            'status',
            'started_at',
            'completed_at',
            'failed_at',
            'failure_reason',
        ]));
        $this->assertSame(Audit::STATUS_PENDING, $this->createAudit()->status);
    }

    public function test_run_seo_audit_job_records_running_and_completed_transitions(): void
    {
        $audit = $this->createAudit();
        $statuses = [];

        Audit::updated(function (Audit $updatedAudit) use (&$statuses, $audit): void {
            if ($updatedAudit->is($audit)) {
                $statuses[] = $updatedAudit->status;
            }
        });

        $job = new RunSeoAuditJob($audit->id);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame($audit->id, $job->auditId);

        $job->handle();
        $audit->refresh();

        $this->assertSame([Audit::STATUS_RUNNING, Audit::STATUS_COMPLETED], $statuses);
        $this->assertSame(Audit::STATUS_COMPLETED, $audit->status);
        $this->assertNotNull($audit->started_at);
        $this->assertNotNull($audit->completed_at);
        $this->assertNull($audit->failed_at);
        $this->assertNull($audit->failure_reason);
    }

    public function test_run_seo_audit_job_failure_callback_stores_only_a_generic_reason(): void
    {
        $audit = $this->createAudit([
            'status' => Audit::STATUS_RUNNING,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
        $exception = new RuntimeException('Provider response for https://example.com/?token=secret');
        Log::spy();

        (new RunSeoAuditJob($audit->id))->failed($exception);
        $audit->refresh();

        $this->assertSame(Audit::STATUS_FAILED, $audit->status);
        $this->assertNull($audit->completed_at);
        $this->assertNotNull($audit->failed_at);
        $this->assertSame(RunSeoAuditJob::GENERIC_FAILURE_REASON, $audit->failure_reason);
        $this->assertStringNotContainsString('secret', $audit->failure_reason);

        Log::shouldHaveReceived('warning')->once()->with('SEO audit job failed.', [
            'audit_id' => $audit->id,
            'exception' => RuntimeException::class,
        ]);
    }

    public function test_failure_reason_is_not_exposed_by_the_audit_api(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAudit([
            'status' => Audit::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => RunSeoAuditJob::GENERIC_FAILURE_REASON,
        ], $user);
        Sanctum::actingAs($user);

        $this->getJson("/api/audits/{$audit->id}")
            ->assertOk()
            ->assertJsonPath('audit.status', Audit::STATUS_FAILED)
            ->assertJsonMissingPath('audit.failure_reason');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAudit(array $attributes = [], ?User $user = null): Audit
    {
        $user ??= User::factory()->create();
        $domain = Domain::create([
            'user_id' => $user->id,
            'domain_name' => 'example-'.$user->id.'-'.uniqid().'.com',
            'url' => 'https://example.com',
        ]);

        return $domain->audits()->create([
            'global_score' => 0,
            'technical_score' => 0,
            'content_score' => 0,
            'links_score' => 0,
            'performance_score' => 0,
            'raw_data' => ['title' => 'Example'],
            ...$attributes,
        ]);
    }
}
