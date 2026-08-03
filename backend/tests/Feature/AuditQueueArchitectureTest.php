<?php

namespace Tests\Feature;

use App\Exceptions\AuditProcessingException;
use App\Jobs\RunSeoAuditJob;
use App\Models\Audit;
use App\Models\Domain;
use App\Models\User;
use App\Services\Audit\AuditProcessingService;
use App\Services\Seo\SeoCrawlerService;
use App\Services\Seo\SeoScoringService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
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
        $this->assertSame('https://example.com/requested', $audit->requested_url);
        $this->assertNull($audit->final_url);

        $audit->update([
            'status' => Audit::STATUS_RUNNING,
            'started_at' => now(),
            'completed_at' => now(),
            'failed_at' => now(),
            'failure_reason' => 'Internal failure detail.',
            'requested_url' => 'https://example.com/updated',
            'final_url' => 'https://www.example.com/final',
        ]);
        $audit->refresh();

        $this->assertSame(Audit::STATUS_RUNNING, $audit->status);
        $this->assertInstanceOf(\DateTimeInterface::class, $audit->started_at);
        $this->assertInstanceOf(\DateTimeInterface::class, $audit->completed_at);
        $this->assertInstanceOf(\DateTimeInterface::class, $audit->failed_at);
        $this->assertIsArray($audit->raw_data);
        $this->assertSame('https://example.com/updated', $audit->requested_url);
        $this->assertSame('https://www.example.com/final', $audit->final_url);
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

    public function test_audit_url_migration_can_be_reversed_and_reapplied_on_sqlite(): void
    {
        $this->assertTrue(Schema::hasColumns('audits', [
            'requested_url',
            'final_url',
        ]));

        $migration = require database_path('migrations/2026_08_03_000000_add_requested_and_final_urls_to_audits_table.php');

        $migration->down();

        $this->assertFalse(Schema::hasColumn('audits', 'requested_url'));
        $this->assertFalse(Schema::hasColumn('audits', 'final_url'));

        $migration->up();

        $this->assertTrue(Schema::hasColumns('audits', [
            'requested_url',
            'final_url',
        ]));
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

    public function test_redis_retry_after_is_safely_above_the_audit_job_timeout(): void
    {
        $job = new RunSeoAuditJob(1);
        $retryAfter = (int) config('queue.connections.redis.retry_after');

        $this->assertSame(180, $job->timeout);
        $this->assertGreaterThan($job->timeout, $retryAfter);
        $this->assertSame(30, $job->backoff);
    }

    public function test_run_seo_audit_job_uses_a_non_releasing_per_audit_overlap_lock(): void
    {
        $job = new RunSeoAuditJob(123);
        $middleware = $job->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('seo-audit:123', $middleware[0]->key);
        $this->assertNull($middleware[0]->releaseAfter);
        $this->assertSame(RunSeoAuditJob::OVERLAP_LOCK_SECONDS, $middleware[0]->expiresAfter);
        $this->assertGreaterThan($job->timeout, $middleware[0]->expiresAfter);
        $this->assertLessThan((int) config('queue.connections.redis.retry_after'), $middleware[0]->expiresAfter);
    }

    public function test_run_seo_audit_job_records_running_and_completed_transitions(): void
    {
        $requestedUrl = 'https://example.com/queued-path?view=full';
        $finalUrl = 'https://www.example.com/final-path';
        $audit = $this->createAudit([
            'requested_url' => $requestedUrl,
        ]);
        $statuses = [];
        $rawData = $this->completeRawData($finalUrl);
        $crawler = $this->mock(SeoCrawlerService::class);
        $crawler->shouldReceive('crawl')
            ->once()
            ->with($requestedUrl)
            ->andReturnUsing(function () use (&$statuses, $audit, $rawData): array {
                $statuses[] = $audit->fresh()->status;

                return $rawData;
            });
        $processingService = new AuditProcessingService($crawler, new SeoScoringService);

        Audit::updated(function (Audit $updatedAudit) use (&$statuses, $audit): void {
            if ($updatedAudit->is($audit)) {
                $statuses[] = $updatedAudit->status;
            }
        });

        $job = new RunSeoAuditJob($audit->id);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertSame($audit->id, $job->auditId);

        $job->handle($processingService);
        $audit->refresh();

        $this->assertSame([Audit::STATUS_RUNNING, Audit::STATUS_COMPLETED], $statuses);
        $this->assertSame(Audit::STATUS_COMPLETED, $audit->status);
        $this->assertNotNull($audit->started_at);
        $this->assertNotNull($audit->completed_at);
        $this->assertNull($audit->failed_at);
        $this->assertNull($audit->failure_reason);
        $this->assertSame($requestedUrl, $audit->requested_url);
        $this->assertSame($finalUrl, $audit->final_url);
        $this->assertEquals($rawData, $audit->raw_data);
    }

    public function test_first_attempt_failure_stays_running_until_the_terminal_failure_callback(): void
    {
        $requestedUrl = 'https://example.com/page?token=secret123&signature=abc';
        $user = User::factory()->create();
        $audit = $this->createAudit([
            'requested_url' => $requestedUrl,
        ], $user);
        $crawler = $this->mock(SeoCrawlerService::class);
        $crawler->shouldReceive('crawl')
            ->once()
            ->with($requestedUrl)
            ->andThrow(new RuntimeException("Connection failed for {$requestedUrl}."));
        $processingService = new AuditProcessingService($crawler, new SeoScoringService);
        $job = new RunSeoAuditJob($audit->id);
        $exception = null;

        try {
            $job->handle($processingService);
            $this->fail('The processing exception was not rethrown.');
        } catch (AuditProcessingException $caughtException) {
            $exception = $caughtException;
            $this->assertSame(AuditProcessingException::MESSAGE, $caughtException->getMessage());
            $this->assertNull($caughtException->getPrevious());
            $this->assertStringNotContainsString('secret123', (string) $caughtException);
            $this->assertStringNotContainsString('signature=abc', (string) $caughtException);
            $this->assertStringNotContainsString('token=secret123', (string) $caughtException);
        }

        $audit->refresh();

        $this->assertSame(Audit::STATUS_RUNNING, $audit->status);
        $this->assertNotNull($audit->started_at);
        $this->assertNull($audit->failed_at);
        $this->assertNull($audit->completed_at);
        $this->assertNull($audit->failure_reason);
        $this->assertArrayNotHasKey('failure_reason', $audit->toArray());

        Sanctum::actingAs($user);
        $this->getJson("/api/audits/{$audit->id}")
            ->assertOk()
            ->assertJsonPath('audit.status', Audit::STATUS_RUNNING)
            ->assertJsonMissingPath('audit.failure_reason');

        $this->assertInstanceOf(AuditProcessingException::class, $exception);
        $job->failed($exception);
        $audit->refresh();

        $this->assertSame(Audit::STATUS_FAILED, $audit->status);
        $this->assertNotNull($audit->failed_at);
        $this->assertNull($audit->completed_at);
        $this->assertSame(AuditProcessingService::GENERIC_FAILURE_REASON, $audit->failure_reason);
        $this->assertStringNotContainsString('Sensitive', $audit->failure_reason);
    }

    public function test_terminal_job_exception_is_sanitized_in_failed_job_storage_and_logs(): void
    {
        $requestedUrl = 'https://example.com/page?token=secret123&signature=abc';
        $audit = $this->createAudit(['requested_url' => $requestedUrl]);
        $crawler = $this->mock(SeoCrawlerService::class);
        $crawler->shouldReceive('crawl')
            ->once()
            ->with($requestedUrl)
            ->andThrow(new RuntimeException("Transport failed for {$requestedUrl}; raw response: secret123."));
        $processingService = new AuditProcessingService($crawler, new SeoScoringService);
        $job = new RunSeoAuditJob($audit->id);
        Log::spy();

        try {
            $job->handle($processingService);
            $this->fail('The processing exception was not rethrown.');
        } catch (AuditProcessingException $exception) {
            $this->assertSame(AuditProcessingException::MESSAGE, $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }

        $job->failed($exception);
        $this->app['queue']->connection('database')->push($job);
        $payload = DB::table('jobs')->value('payload');
        $uuid = $this->app['queue.failer']->log('database', 'default', $payload, $exception);
        $failedJob = DB::table('failed_jobs')->where('uuid', $uuid)->sole();
        $audit->refresh();

        $this->assertSame(Audit::STATUS_FAILED, $audit->status);
        $this->assertSame(AuditProcessingService::GENERIC_FAILURE_REASON, $audit->failure_reason);
        $this->assertStringContainsString(AuditProcessingException::MESSAGE, $failedJob->exception);
        $this->assertStringNotContainsString('raw response', $failedJob->exception);

        foreach (['secret123', 'signature=abc', 'token=secret123'] as $sensitiveValue) {
            $this->assertStringNotContainsString($sensitiveValue, $failedJob->exception);
            $this->assertStringNotContainsString($sensitiveValue, $failedJob->payload);
        }

        Log::shouldHaveReceived('warning')->with('SEO audit attempt failed.', [
            'audit_id' => $audit->id,
            'exception' => RuntimeException::class,
        ])->once();
        Log::shouldHaveReceived('warning')->with('SEO audit job failed.', [
            'audit_id' => $audit->id,
            'exception' => AuditProcessingException::class,
        ])->once();
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_a_failed_first_attempt_can_retry_successfully_without_resetting_started_at(): void
    {
        $requestedUrl = 'https://example.com/retry-path';
        $finalUrl = 'https://example.com/retry-success';
        $audit = $this->createAudit(['requested_url' => $requestedUrl]);
        $rawData = $this->completeRawData($finalUrl);
        $attempt = 0;
        $crawler = $this->mock(SeoCrawlerService::class);
        $crawler->shouldReceive('crawl')
            ->twice()
            ->with($requestedUrl)
            ->andReturnUsing(function () use (&$attempt, $rawData): array {
                $attempt++;

                if ($attempt === 1) {
                    throw new RuntimeException('Sensitive transient failure.');
                }

                return $rawData;
            });
        $processingService = new AuditProcessingService($crawler, new SeoScoringService);
        $job = new RunSeoAuditJob($audit->id);

        try {
            $job->handle($processingService);
            $this->fail('The first attempt did not fail.');
        } catch (RuntimeException) {
            // Laravel will release the job for its remaining attempt.
        }

        $audit->refresh();
        $firstStartedAt = $audit->started_at?->copy();
        $this->assertSame(Audit::STATUS_RUNNING, $audit->status);
        $this->assertNotNull($firstStartedAt);
        $this->assertNull($audit->failed_at);
        $this->assertNull($audit->failure_reason);

        $job->handle($processingService);
        $audit->refresh();

        $this->assertSame(Audit::STATUS_COMPLETED, $audit->status);
        $this->assertTrue($firstStartedAt->equalTo($audit->started_at));
        $this->assertNotNull($audit->completed_at);
        $this->assertNull($audit->failed_at);
        $this->assertNull($audit->failure_reason);
        $this->assertSame($finalUrl, $audit->final_url);
    }

    public function test_completed_audits_are_not_overwritten_by_duplicate_processing_or_failure_callbacks(): void
    {
        $completedAt = now()->subMinute();
        $audit = $this->createAudit([
            'status' => Audit::STATUS_COMPLETED,
            'started_at' => now()->subMinutes(2),
            'completed_at' => $completedAt,
            'final_url' => 'https://example.com/already-completed',
        ]);
        $persistedCompletedAt = $audit->fresh()->completed_at;
        $crawler = $this->mock(SeoCrawlerService::class);
        $crawler->shouldNotReceive('crawl');
        $processingService = new AuditProcessingService($crawler, new SeoScoringService);
        $job = new RunSeoAuditJob($audit->id);

        $job->handle($processingService);
        $job->failed(new RuntimeException('Late worker failure with sensitive details.'));
        $audit->refresh();

        $this->assertSame(Audit::STATUS_COMPLETED, $audit->status);
        $this->assertTrue($persistedCompletedAt->equalTo($audit->completed_at));
        $this->assertNull($audit->failed_at);
        $this->assertNull($audit->failure_reason);
        $this->assertSame('https://example.com/already-completed', $audit->final_url);
    }

    public function test_terminally_failed_audits_are_not_reprocessed_by_stale_jobs(): void
    {
        $failedAt = now()->subMinute();
        $audit = $this->createAudit([
            'status' => Audit::STATUS_FAILED,
            'started_at' => now()->subMinutes(2),
            'failed_at' => $failedAt,
            'failure_reason' => RunSeoAuditJob::GENERIC_FAILURE_REASON,
        ]);
        $persistedFailedAt = $audit->fresh()->failed_at;
        $crawler = $this->mock(SeoCrawlerService::class);
        $crawler->shouldNotReceive('crawl');
        $processingService = new AuditProcessingService($crawler, new SeoScoringService);

        (new RunSeoAuditJob($audit->id))->handle($processingService);
        $audit->refresh();

        $this->assertSame(Audit::STATUS_FAILED, $audit->status);
        $this->assertTrue($persistedFailedAt->equalTo($audit->failed_at));
        $this->assertSame(RunSeoAuditJob::GENERIC_FAILURE_REASON, $audit->failure_reason);
        $this->assertNull($audit->completed_at);
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
            'requested_url' => 'https://example.com/requested',
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function completeRawData(string $finalUrl): array
    {
        return [
            'title' => 'A descriptive page title for content SEO',
            'title_length' => 40,
            'meta_description' => str_repeat('Useful description ', 5),
            'meta_description_length' => 95,
            'word_count' => 500,
            'h1_count' => 1,
            'h2_count' => 1,
            'title_matches_h1' => true,
            'heading_structure' => [
                ['tag' => 'h1', 'text' => 'A descriptive page title for content SEO'],
                ['tag' => 'h2', 'text' => 'Section'],
            ],
            'images_missing_alt_count' => 0,
            'images_alt_missing_ratio' => 0.0,
            'uses_https' => true,
            'robots_txt_found' => true,
            'sitemap_xml_found' => true,
            'robots_txt_allows_audited_url' => true,
            'sitemap_xml_is_valid' => true,
            'sitemap_non_https_urls_count' => 0,
            'sitemap_broken_urls_count' => 0,
            'pages_with_http_errors_count' => 0,
            'pages_with_missing_title_count' => 0,
            'pages_with_missing_meta_description_count' => 0,
            'pages_with_missing_h1_count' => 0,
            'pages_with_noindex_count' => 0,
            'pages_with_low_word_count_count' => 0,
            'duplicate_titles_count' => 0,
            'duplicate_meta_descriptions_count' => 0,
            'duplicate_h1_count' => 0,
            'duplicate_title_groups' => [],
            'duplicate_meta_description_groups' => [],
            'duplicate_h1_groups' => [],
            'duplicate_content_count' => 0,
            'thin_content_pages_count' => 0,
            'canonical_conflicts_count' => 0,
            'http_status_code' => 200,
            'redirect_count' => 0,
            'final_url' => $finalUrl,
            'canonical_url' => $finalUrl,
            'canonical_matches_final_url' => true,
            'meta_robots' => null,
            'viewport_found' => true,
            'html_lang' => 'en',
            'links_count' => 1,
            'response_time_ms' => 100,
            'page_size_bytes' => 1000,
            'is_html_response' => true,
            'compression_enabled' => true,
            'cache_headers_present' => true,
            'structured_data_found' => true,
            'structured_data_errors_count' => 0,
        ];
    }
}
