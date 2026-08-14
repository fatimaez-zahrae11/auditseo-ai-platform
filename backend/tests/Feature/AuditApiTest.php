<?php

namespace Tests\Feature;

use App\Exceptions\AuditProcessingException;
use App\Jobs\RunSeoAuditJob;
use App\Models\Audit;
use App\Models\Domain;
use App\Models\User;
use App\Security\CurlTransportCapabilities;
use App\Security\DnsResolver;
use App\Services\Audit\AuditProcessingService;
use App\Services\Seo\SeoCrawlerService;
use Exception;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\InflateStream;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

class AuditApiTest extends TestCase
{
    use RefreshDatabase;

    private string $responseHtml;

    private int $robotsStatus;

    private int $sitemapStatus;

    private int $pageStatus;

    private string $robotsBody;

    private string $sitemapBody;

    /**
     * @var array<string, string>
     */
    private array $responseHeaders;

    private int $responseDelayMicroseconds;

    /**
     * @var array<string, int>
     */
    private array $linkStatuses;

    /**
     * @var array<string, array{body: string, status: int}>
     */
    private array $pageResponses;

    /**
     * @var array<string, array{status: int, location: string}>
     */
    private array $redirects;

    /**
     * @var array<string, list<string>>
     */
    private array $dnsAnswers;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->responseHtml = $this->completeHtml();
        $this->robotsStatus = 200;
        $this->sitemapStatus = 200;
        $this->pageStatus = 200;
        $this->robotsBody = 'User-agent: *';
        $this->sitemapBody = '<urlset></urlset>';
        $this->responseHeaders = [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Encoding' => 'gzip',
            'Cache-Control' => 'public, max-age=300',
            'Server' => 'example-server',
        ];
        $this->responseDelayMicroseconds = 0;
        $this->redirects = [];
        $this->dnsAnswers = [];
        $this->linkStatuses = [];
        $aboutHtml = $this->contentHtml(
            'About AuditSEO Platform Overview',
            'A helpful about page with a sufficiently descriptive and unique meta description for crawler tests.',
            '<h1>About AuditSEO Platform</h1><h2>Overview</h2><p>'.str_repeat('about content ', 300).'</p>',
            '/about',
        );
        $this->pageResponses = [
            'https://example.com/about' => [
                'body' => $aboutHtml,
                'status' => 200,
            ],
            'http://example.com/about' => [
                'body' => $aboutHtml,
                'status' => 200,
            ],
        ];

        $resolver = Mockery::mock(DnsResolver::class);
        $resolver->shouldReceive('resolve')
            ->andReturnUsing(
                fn (string $hostname): array => $this->dnsAnswers[$hostname]
                    ?? ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
            );
        $this->app->instance(DnsResolver::class, $resolver);

        Http::fake(function (Request $request) {
            if (isset($this->redirects[$request->url()])) {
                $redirect = $this->redirects[$request->url()];

                return Http::response('', $redirect['status'], ['Location' => $redirect['location']]);
            }

            if (str_ends_with($request->url(), '/robots.txt')) {
                return Http::response($this->robotsBody, $this->robotsStatus);
            }

            if (str_ends_with($request->url(), '/sitemap.xml')) {
                return Http::response($this->sitemapBody, $this->sitemapStatus);
            }

            if (isset($this->pageResponses[$request->url()])) {
                $page = $this->pageResponses[$request->url()];

                return Http::response($page['body'], $page['status'], ['Content-Type' => 'text/html']);
            }

            if (isset($this->linkStatuses[$request->url()])) {
                return Http::response('', $this->linkStatuses[$request->url()]);
            }

            if ($this->responseDelayMicroseconds > 0) {
                usleep($this->responseDelayMicroseconds);
            }

            return Http::response($this->responseHtml, $this->pageStatus, $this->responseHeaders);
        });
    }

    /**
     * Execute the real queued job directly for crawler/processing regressions.
     *
     * The returned 200 response is a test-only processing snapshot. It is not
     * the response contract of POST /api/audits.
     */
    private function processAuditThroughJob(array $data): TestResponse
    {
        $user = auth()->user();
        $this->assertInstanceOf(User::class, $user);
        $url = $data['url'] ?? null;
        $this->assertIsString($url);
        $domainName = strtolower((string) parse_url($url, PHP_URL_HOST));

        $domain = Domain::firstOrCreate(
            [
                'user_id' => $user->id,
                'domain_name' => $domainName,
            ],
            ['url' => $url],
        );
        $audit = $domain->audits()->create([
            'global_score' => 0,
            'technical_score' => 0,
            'content_score' => 0,
            'links_score' => 0,
            'performance_score' => 0,
            'raw_data' => null,
            'requested_url' => $url,
            'status' => Audit::STATUS_PENDING,
        ]);

        $job = new RunSeoAuditJob($audit->id);

        try {
            $job->handle($this->app->make(AuditProcessingService::class));
        } catch (AuditProcessingException $exception) {
            $job->failed($exception);

            if ($exception->isValidationFailure()) {
                return TestResponse::fromBaseResponse(response()->json([
                    'message' => 'The given data was invalid.',
                    'errors' => [
                        'url' => ['The requested URL could not be processed safely.'],
                    ],
                ], 422));
            }

            return TestResponse::fromBaseResponse(response()->json([
                'message' => 'Unable to fetch the requested URL.',
            ], 502));
        } catch (Throwable $exception) {
            $job->failed($exception);

            return TestResponse::fromBaseResponse(response()->json([
                'message' => 'Unable to fetch the requested URL.',
            ], 502));
        }

        $audit = Audit::with(['domain', 'issues'])->findOrFail($audit->id);

        return TestResponse::fromBaseResponse(response()->json([
            'audit' => $audit,
            'domain' => $audit->domain,
            'issues' => $audit->issues,
            'raw_data' => $audit->raw_data,
        ]));
    }

    public function test_unauthenticated_users_cannot_access_audit_routes(): void
    {
        parent::postJson('/api/audits', ['url' => 'https://example.com'])->assertUnauthorized();
        $this->getJson('/api/audits')->assertUnauthorized();
        $this->getJson('/api/audits/1')->assertUnauthorized();
    }

    public function test_an_authenticated_user_can_queue_an_audit_without_running_the_crawler(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $crawler = $this->mock(SeoCrawlerService::class);
        $crawler->shouldNotReceive('crawl');

        $response = parent::postJson('/api/audits', [
            'url' => 'https://example.com/page',
        ]);

        $response
            ->assertAccepted()
            ->assertJsonPath('message', 'Audit queued for processing.')
            ->assertJsonCount(3, 'audit')
            ->assertJsonPath('audit.status', Audit::STATUS_PENDING)
            ->assertJsonPath('audit.requested_url', 'https://example.com/page')
            ->assertJsonPath('poll_url', '/api/audits/'.$response->json('audit.id'))
            ->assertJsonMissingPath('audit.raw_data')
            ->assertJsonMissingPath('audit.issues')
            ->assertJsonMissingPath('audit.global_score');
        $this->assertNotSame(201, $response->getStatusCode());

        $this->assertDatabaseHas('domains', [
            'user_id' => $user->id,
            'domain_name' => 'example.com',
        ]);
        $this->assertDatabaseHas('audits', [
            'id' => $response->json('audit.id'),
            'global_score' => 0,
            'technical_score' => 0,
            'content_score' => 0,
            'links_score' => 0,
            'performance_score' => 0,
            'status' => Audit::STATUS_PENDING,
            'requested_url' => 'https://example.com/page',
            'raw_data' => null,
        ]);
        $audit = Audit::findOrFail($response->json('audit.id'));
        $this->assertNull($audit->final_url);
        $this->assertNull($audit->started_at);
        $this->assertNull($audit->completed_at);
        Http::assertNothingSent();
        Queue::assertPushed(
            RunSeoAuditJob::class,
            fn (RunSeoAuditJob $job): bool => $job->auditId === $audit->id,
        );

        $this->getJson("/api/audits/{$audit->id}")
            ->assertOk()
            ->assertJsonPath('audit.status', Audit::STATUS_PENDING)
            ->assertJsonPath('audit.requested_url', 'https://example.com/page')
            ->assertJsonPath('audit.raw_data', null);

        $this->forgetMock(SeoCrawlerService::class);
    }

    public function test_polling_returns_the_completed_audit_after_the_dispatched_job_is_processed(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $queuedResponse = parent::postJson('/api/audits', [
            'url' => 'https://example.com/polling',
        ])->assertAccepted();
        $auditId = (int) $queuedResponse->json('audit.id');

        $this->getJson("/api/audits/{$auditId}")
            ->assertOk()
            ->assertJsonPath('audit.status', Audit::STATUS_PENDING)
            ->assertJsonPath('audit.raw_data', null);

        (new RunSeoAuditJob($auditId))->handle($this->app->make(AuditProcessingService::class));

        $this->getJson("/api/audits/{$auditId}")
            ->assertOk()
            ->assertJsonPath('audit.status', Audit::STATUS_COMPLETED)
            ->assertJsonPath('audit.requested_url', 'https://example.com/polling')
            ->assertJsonPath('audit.raw_data.final_url', 'https://example.com/polling')
            ->assertJsonStructure([
                'audit' => [
                    'issues',
                    'raw_data',
                ],
            ]);
    }

    public function test_queue_dispatch_failure_marks_the_audit_failed_and_returns_a_safe_service_error(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        Log::spy();
        $dispatcher = Mockery::mock(BusDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(fn (object $job): bool => $job instanceof RunSeoAuditJob))
            ->andThrow(new Exception('Redis password=secret and internal queue details.'));
        $this->app->instance(BusDispatcher::class, $dispatcher);

        $response = parent::postJson('/api/audits', [
            'url' => 'https://example.com/dispatch-failure',
        ]);

        $response
            ->assertStatus(503)
            ->assertExactJson([
                'message' => 'Audit service is temporarily unavailable.',
            ])
            ->assertDontSee('Redis')
            ->assertDontSee('secret')
            ->assertDontSee('queue details')
            ->assertDontSee('exception')
            ->assertDontSee('trace');

        $audit = Audit::sole();
        $this->assertSame(Audit::STATUS_FAILED, $audit->status);
        $this->assertNull($audit->started_at);
        $this->assertNull($audit->completed_at);
        $this->assertNotNull($audit->failed_at);
        $this->assertSame('Audit dispatch failed.', $audit->failure_reason);
        $this->assertArrayNotHasKey('failure_reason', $audit->toArray());
        Http::assertNothingSent();

        Log::shouldHaveReceived('warning')->once()->with('SEO audit dispatch failed.', [
            'audit_id' => $audit->id,
            'exception' => Exception::class,
        ]);
    }

    public function test_crawler_failures_return_a_safe_json_error(): void
    {
        $requestedUrl = 'https://example.com/page?token=secret123&signature=abc';
        Sanctum::actingAs(User::factory()->create());
        Log::spy();
        $crawler = $this->mock(SeoCrawlerService::class);
        $crawler->shouldReceive('crawl')
            ->once()
            ->with($requestedUrl)
            ->andThrow(new Exception("Sensitive transport details for {$requestedUrl}"));

        $response = $this->processAuditThroughJob([
            'url' => $requestedUrl,
        ]);
        $this->forgetMock(SeoCrawlerService::class);

        $response
            ->assertStatus(502)
            ->assertExactJson([
                'message' => 'Unable to fetch the requested URL.',
            ]);

        $this->assertStringNotContainsString('Sensitive transport details', $response->getContent());
        $this->assertStringNotContainsString('secret123', $response->getContent());
        $this->assertStringNotContainsString('signature=abc', $response->getContent());
        $this->assertStringNotContainsString('token=secret123', $response->getContent());
        $audit = Audit::sole();
        $this->assertSame(Audit::STATUS_FAILED, $audit->status);
        $this->assertSame(RunSeoAuditJob::GENERIC_FAILURE_REASON, $audit->failure_reason);
        Log::shouldHaveReceived('warning')->with('SEO audit attempt failed.', [
            'audit_id' => $audit->id,
            'exception' => Exception::class,
        ])->once();
        Log::shouldHaveReceived('warning')->with('SEO audit job failed.', [
            'audit_id' => $audit->id,
            'exception' => AuditProcessingException::class,
        ])->once();
        Log::shouldHaveReceived('warning')->twice();
    }

    public function test_crawler_fails_safely_without_falling_back_when_dns_pinning_is_unavailable(): void
    {
        $this->app->instance(
            CurlTransportCapabilities::class,
            new CurlTransportCapabilities(
                curlAvailable: true,
                dnsPinningAvailable: false,
            ),
        );
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob([
            'url' => 'https://example.com/page',
        ]);

        $response
            ->assertStatus(502)
            ->assertExactJson([
                'message' => 'Unable to fetch the requested URL.',
            ]);

        $this->assertStringNotContainsString('DNS pinning', $response->getContent());
        Http::assertNothingSent();
        $this->assertDatabaseHas('audits', [
            'status' => Audit::STATUS_FAILED,
            'failure_reason' => RunSeoAuditJob::GENERIC_FAILURE_REASON,
        ]);
    }

    public function test_an_oversized_streamed_redirect_body_is_interrupted_and_rejected(): void
    {
        $progress = (object) ['bytes' => 0];
        $this->replaceHttpFake(fn (Request $request) => $request->url() === 'https://example.com/start'
            ? $this->streamedResponse(
                6_000_000,
                ['Location' => 'https://example.com/page'],
                $progress,
                302,
            )
            : Http::response($this->completeHtml()));
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/start']);

        $response
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Unable to fetch the requested URL.']);
        $this->assertSame(5_000_001, $progress->bytes);
        $this->assertLessThan(6_000_000, $progress->bytes);
        $this->assertDatabaseHas('audits', [
            'status' => Audit::STATUS_FAILED,
            'failure_reason' => RunSeoAuditJob::GENERIC_FAILURE_REASON,
        ]);
    }

    public function test_a_misleading_content_length_does_not_bypass_the_html_limit(): void
    {
        $progress = (object) ['bytes' => 0];
        $this->replaceHttpFake(fn () => $this->streamedResponse(
            6_000_000,
            ['Content-Length' => '100', 'Content-Type' => 'text/html'],
            $progress,
        ));
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Unable to fetch the requested URL.']);

        $this->assertSame(5_000_001, $progress->bytes);
        $this->assertLessThan(6_000_000, $progress->bytes);
    }

    public function test_an_oversized_content_length_is_rejected_before_the_body_is_read(): void
    {
        $progress = (object) ['bytes' => 0];
        $this->replaceHttpFake(fn () => $this->streamedResponse(
            6_000_000,
            ['Content-Length' => '6000000', 'Content-Type' => 'text/html'],
            $progress,
        ));
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Unable to fetch the requested URL.']);

        $this->assertSame(0, $progress->bytes);
    }

    public function test_an_oversized_chunked_html_response_is_rejected(): void
    {
        $progress = (object) ['bytes' => 0];
        $this->replaceHttpFake(fn () => $this->streamedResponse(
            6_000_000,
            ['Transfer-Encoding' => 'chunked', 'Content-Type' => 'text/html'],
            $progress,
        ));
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Unable to fetch the requested URL.']);

        $this->assertSame(5_000_001, $progress->bytes);
    }

    public function test_an_oversized_compressed_html_response_is_rejected_after_decoding(): void
    {
        $compressed = gzencode(str_repeat('x', 6_000_000));
        $this->assertNotFalse($compressed);
        $progress = (object) ['bytes' => 0];
        $this->replaceHttpFake(function () use ($compressed, $progress) {
            $encodedResponse = $this->streamedResponse(
                strlen($compressed),
                [],
                $progress,
                200,
                $compressed,
            )->wait();

            return Create::promiseFor(new Psr7Response(
                200,
                [
                    'Content-Type' => 'text/html',
                    'X-Encoded-Content-Encoding' => 'gzip',
                    'X-Encoded-Content-Length' => (string) strlen($compressed),
                ],
                new InflateStream($encodedResponse->getBody()),
            ));
        });
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertStatus(502)
            ->assertExactJson(['message' => 'Unable to fetch the requested URL.']);

        $this->assertGreaterThan(0, $progress->bytes);
        $this->assertLessThan(strlen($compressed) + 1, $progress->bytes);
    }

    public function test_a_small_streamed_html_response_still_creates_an_audit(): void
    {
        $body = $this->completeHtml();
        $progress = (object) ['bytes' => 0];
        $this->replaceHttpFake(function (Request $request) use ($body, $progress) {
            if ($request->url() === 'https://example.com/page') {
                return $this->streamedResponse(
                    strlen($body),
                    ['Content-Length' => (string) strlen($body), 'Content-Type' => 'text/html'],
                    $progress,
                    200,
                    $body,
                );
            }

            if (str_ends_with($request->url(), '/robots.txt')) {
                return Http::response('User-agent: *');
            }

            if (str_ends_with($request->url(), '/sitemap.xml')) {
                return Http::response('<urlset></urlset>');
            }

            return Http::response('', 200);
        });
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.title', 'Example Page')
            ->assertJsonPath('raw_data.page_size_bytes', strlen($body));

        $this->assertSame(strlen($body), $progress->bytes);
    }

    public function test_oversized_robots_txt_is_interrupted_and_ignored(): void
    {
        $progress = (object) ['bytes' => 0];
        $this->fakeSecondaryResourceStream('/robots.txt', 600_000, [], $progress);
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.robots_txt_found', false)
            ->assertJsonPath('raw_data.robots_txt_status_code', null);

        $this->assertSame(512_001, $progress->bytes);
        $this->assertLessThan(600_000, $progress->bytes);
    }

    public function test_oversized_sitemap_is_interrupted_and_ignored(): void
    {
        $progress = (object) ['bytes' => 0];
        $this->fakeSecondaryResourceStream('/sitemap.xml', 11_000_000, [], $progress);
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.sitemap_xml_found', false)
            ->assertJsonPath('raw_data.sitemap_xml_status_code', null);

        $this->assertSame(10_000_001, $progress->bytes);
        $this->assertLessThan(11_000_000, $progress->bytes);
    }

    public function test_oversized_child_sitemap_is_interrupted_and_skipped(): void
    {
        $progress = (object) ['bytes' => 0];
        $this->replaceHttpFake(function (Request $request) use ($progress) {
            if ($request->url() === 'https://example.com/child.xml') {
                return $this->streamedResponse(11_000_000, [], $progress);
            }

            if (str_ends_with($request->url(), '/robots.txt')) {
                return Http::response('User-agent: *');
            }

            if (str_ends_with($request->url(), '/sitemap.xml')) {
                return Http::response(
                    '<sitemapindex><sitemap><loc>https://example.com/child.xml</loc></sitemap></sitemapindex>',
                );
            }

            return Http::response($this->completeHtml(), 200, ['Content-Type' => 'text/html']);
        });
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.sitemap_xml_is_valid', true)
            ->assertJsonPath('raw_data.sitemap_urls_count', 0);

        $this->assertSame(10_000_001, $progress->bytes);
    }

    public function test_oversized_checked_link_body_is_interrupted_and_marked_broken(): void
    {
        $link = 'https://external.example/large';
        $progress = (object) ['bytes' => 0];
        $this->replaceHttpFake(function (Request $request) use ($link, $progress) {
            if ($request->url() === $link) {
                return $this->streamedResponse(100_000, [], $progress);
            }

            if (str_ends_with($request->url(), '/robots.txt')) {
                return Http::response('User-agent: *');
            }

            if (str_ends_with($request->url(), '/sitemap.xml')) {
                return Http::response('<urlset></urlset>');
            }

            return Http::response(
                $this->htmlWithLinks('<a href="'.$link.'">Large response</a>'),
                200,
                ['Content-Type' => 'text/html'],
            );
        });
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.checked_links_count', 1)
            ->assertJsonPath('raw_data.broken_links_count', 1);

        $this->assertSame(64_001, $progress->bytes);
        $this->assertLessThan(100_000, $progress->bytes);
    }

    public function test_robots_txt_and_sitemap_xml_availability_are_stored_in_raw_data(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.robots_txt_found', true)
            ->assertJsonPath('raw_data.robots_txt_status_code', 200)
            ->assertJsonPath('raw_data.sitemap_xml_found', true)
            ->assertJsonPath('raw_data.sitemap_xml_status_code', 200);

        $audit = Audit::findOrFail($response->json('audit.id'));
        $this->assertTrue($audit->raw_data['robots_txt_found']);
        $this->assertTrue($audit->raw_data['sitemap_xml_found']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.com/robots.txt');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.com/sitemap.xml');
    }

    public function test_robots_txt_directives_and_disallow_rules_are_analyzed(): void
    {
        $this->robotsBody = <<<'ROBOTS'
            User-agent: *
            Disallow: /private
            Disallow: /temporary
            Allow: /private/allowed
            Sitemap: https://example.com/sitemap.xml

            User-agent: ExampleBot
            Disallow: /bot-only
            ROBOTS;
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/private/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.robots_txt_status_code', 200)
            ->assertJsonPath('raw_data.robots_txt_sitemap_urls.0', 'https://example.com/sitemap.xml')
            ->assertJsonPath('raw_data.robots_txt_disallow_rules_count', 2)
            ->assertJsonPath('raw_data.robots_txt_allows_audited_url', false)
            ->assertJsonFragment([
                'title' => 'Audited URL is blocked by robots.txt',
                'category' => 'indexability',
                'severity' => 'critical',
            ]);
    }

    public function test_a_more_specific_robots_allow_rule_keeps_the_url_crawlable(): void
    {
        $this->robotsBody = <<<'ROBOTS'
            User-agent: *
            Disallow: /private
            Allow: /private/allowed
            ROBOTS;
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/private/allowed/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.robots_txt_allows_audited_url', true)
            ->assertJsonMissing(['title' => 'Audited URL is blocked by robots.txt']);
    }

    public function test_valid_sitemap_metrics_and_audited_url_presence_are_stored(): void
    {
        $this->sitemapBody = <<<'XML'
            <?xml version="1.0" encoding="UTF-8"?>
            <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
                <url><loc>https://example.com/page</loc></url>
                <url><loc>https://example.com/another-page</loc></url>
            </urlset>
            XML;
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.sitemap_xml_status_code', 200)
            ->assertJsonPath('raw_data.sitemap_xml_is_valid', true)
            ->assertJsonPath('raw_data.sitemap_urls_count', 2)
            ->assertJsonPath('raw_data.sitemap_contains_audited_url', true)
            ->assertJsonPath('raw_data.sitemap_https_urls_count', 2)
            ->assertJsonPath('raw_data.sitemap_non_https_urls_count', 0)
            ->assertJsonPath('raw_data.sitemap_checked_urls_count', 2)
            ->assertJsonPath('raw_data.sitemap_broken_urls_count', 0)
            ->assertJsonPath('raw_data.sitemap_broken_urls_sample', []);
    }

    public function test_invalid_sitemap_creates_an_important_technical_issue(): void
    {
        $this->sitemapBody = '<urlset><url><loc>https://example.com/page</loc></urlset>';
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.sitemap_xml_found', true)
            ->assertJsonPath('raw_data.sitemap_xml_is_valid', false)
            ->assertJsonPath('audit.technical_score', 80)
            ->assertJsonFragment([
                'title' => 'Sitemap XML is invalid',
                'category' => 'technical',
                'severity' => 'important',
            ]);
    }

    public function test_audited_url_missing_from_valid_sitemap_creates_a_minor_issue(): void
    {
        $this->sitemapBody = <<<'XML'
            <urlset>
                <url><loc>https://example.com/a-different-page</loc></url>
            </urlset>
            XML;
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.sitemap_contains_audited_url', false)
            ->assertJsonFragment([
                'title' => 'Audited URL is missing from sitemap',
                'category' => 'indexability',
                'severity' => 'minor',
            ]);
    }

    public function test_sitemap_non_https_urls_create_an_important_technical_issue(): void
    {
        $this->sitemapBody = <<<'XML'
            <urlset>
                <url><loc>https://example.com/page</loc></url>
                <url><loc>http://example.com/legacy</loc></url>
            </urlset>
            XML;
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.sitemap_https_urls_count', 1)
            ->assertJsonPath('raw_data.sitemap_non_https_urls_count', 1)
            ->assertJsonFragment([
                'title' => 'Sitemap contains non-HTTPS URLs',
                'category' => 'technical',
                'severity' => 'important',
            ]);
    }

    public function test_broken_sitemap_urls_create_an_important_technical_issue(): void
    {
        $this->sitemapBody = <<<'XML'
            <urlset>
                <url><loc>https://example.com/page</loc></url>
                <url><loc>https://example.com/broken-sitemap-entry</loc></url>
            </urlset>
            XML;
        $this->linkStatuses['https://example.com/broken-sitemap-entry'] = 410;
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.sitemap_checked_urls_count', 2)
            ->assertJsonPath('raw_data.sitemap_broken_urls_count', 1)
            ->assertJsonPath(
                'raw_data.sitemap_broken_urls_sample.0',
                'https://example.com/broken-sitemap-entry',
            )
            ->assertJsonFragment([
                'title' => 'Sitemap contains broken URLs',
                'category' => 'technical',
                'severity' => 'important',
            ]);
    }

    public function test_parsed_and_checked_sitemap_urls_are_safely_limited(): void
    {
        $locations = '';
        for ($index = 1; $index <= 130; $index++) {
            $locations .= "<url><loc>https://example.com/sitemap-page-{$index}</loc></url>";
        }
        $this->sitemapBody = "<urlset>{$locations}</urlset>";
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.sitemap_urls_count', 100)
            ->assertJsonPath('raw_data.sitemap_checked_urls_count', 25);
    }

    public function test_missing_robots_txt_creates_an_audit_issue(): void
    {
        $this->robotsStatus = 404;
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.robots_txt_found', false)
            ->assertJsonPath('raw_data.robots_txt_status_code', 404)
            ->assertJsonPath('audit.technical_score', 80)
            ->assertJsonFragment(['title' => 'Missing robots.txt']);
        $this->assertDatabaseHas('audit_issues', ['title' => 'Missing robots.txt']);
    }

    public function test_missing_sitemap_xml_creates_an_audit_issue(): void
    {
        $this->sitemapStatus = 404;
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.sitemap_xml_found', false)
            ->assertJsonPath('raw_data.sitemap_xml_status_code', 404)
            ->assertJsonPath('audit.technical_score', 80)
            ->assertJsonFragment(['title' => 'Missing sitemap.xml']);
        $this->assertDatabaseHas('audit_issues', ['title' => 'Missing sitemap.xml']);
    }

    public function test_html_title_and_meta_description_are_extracted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->processAuditThroughJob(['url' => 'https://example.com']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.title', 'Example Page')
            ->assertJsonPath('raw_data.meta_description', 'Example description')
            ->assertJsonPath('raw_data.h1_count', 1)
            ->assertJsonPath('raw_data.h2_count', 1)
            ->assertJsonPath('raw_data.images_count', 2)
            ->assertJsonPath('raw_data.images_missing_alt_count', 0)
            ->assertJsonPath('raw_data.links_count', 1)
            ->assertJsonPath('raw_data.uses_https', true);
    }

    public function test_on_page_content_v2_fields_are_extracted_and_stored(): void
    {
        $title = 'Professional SEO Audit Guide | Brand';
        $description = 'A practical guide to professional on-page SEO audits, content quality, headings, and accessible image optimization.';
        $this->responseHtml = $this->contentHtml($title, $description, <<<'HTML'
            <h1>Professional SEO Audit Guide</h1>
            <h2>Content Analysis</h2>
            <h3>Visible subsection</h3>
            <p>Useful visible body copy for readers and search engines.</p>
            <script>secret script words must not appear</script>
            <style>.hidden { content: "style words"; }</style>
            <noscript>hidden fallback words</noscript>
            <svg><text>hidden vector words</text></svg>
            HTML);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.title_length', mb_strlen($title))
            ->assertJsonPath('raw_data.meta_description_length', mb_strlen($description))
            ->assertJsonPath('raw_data.h1_texts.0', 'Professional SEO Audit Guide')
            ->assertJsonPath('raw_data.h2_texts.0', 'Content Analysis')
            ->assertJsonPath('raw_data.heading_structure.0', [
                'tag' => 'h1',
                'text' => 'Professional SEO Audit Guide',
            ])
            ->assertJsonPath('raw_data.heading_structure.1', [
                'tag' => 'h2',
                'text' => 'Content Analysis',
            ])
            ->assertJsonPath('raw_data.heading_structure.2', [
                'tag' => 'h3',
                'text' => 'Visible subsection',
            ])
            ->assertJsonPath('raw_data.title_matches_h1', true);

        $sample = $response->json('raw_data.visible_text_sample');
        $this->assertIsString($sample);
        $this->assertStringContainsString('Useful visible body copy', $sample);
        $this->assertStringNotContainsString('secret script words', $sample);
        $this->assertStringNotContainsString('hidden fallback words', $sample);
        $this->assertLessThanOrEqual(500, mb_strlen($sample));
        $this->assertGreaterThan(0, $response->json('raw_data.word_count'));

        $stored = Audit::findOrFail($response->json('audit.id'))->raw_data;
        $this->assertSame($stored['visible_text_sample'], $sample);
        $this->assertSame($stored['heading_structure'], $response->json('raw_data.heading_structure'));
    }

    public function test_short_title_creates_a_minor_issue(): void
    {
        $this->responseHtml = $this->contentHtml(
            'Short title',
            str_repeat('Useful meta description ', 4),
            '<h1>Short title</h1><h2>Section</h2><p>'.str_repeat('content ', 300).'</p>',
        );
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonFragment([
                'title' => 'Page title is too short',
                'category' => 'content',
                'severity' => 'minor',
            ]);
    }

    public function test_long_title_creates_a_minor_issue(): void
    {
        $title = str_repeat('L', 61);
        $this->responseHtml = $this->contentHtml(
            $title,
            str_repeat('Useful meta description ', 4),
            "<h1>{$title}</h1><h2>Section</h2><p>".str_repeat('content ', 300).'</p>',
        );
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonFragment([
                'title' => 'Page title is too long',
                'category' => 'content',
                'severity' => 'minor',
            ]);
    }

    public function test_short_meta_description_creates_a_minor_issue(): void
    {
        $title = 'A descriptive page title for content SEO';
        $this->responseHtml = $this->contentHtml(
            $title,
            'Too short',
            "<h1>{$title}</h1><h2>Section</h2><p>".str_repeat('content ', 300).'</p>',
        );
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonFragment([
                'title' => 'Meta description is too short',
                'category' => 'content',
                'severity' => 'minor',
            ]);
    }

    public function test_long_meta_description_creates_a_minor_issue(): void
    {
        $title = 'A descriptive page title for content SEO';
        $this->responseHtml = $this->contentHtml(
            $title,
            str_repeat('Long description content ', 8),
            "<h1>{$title}</h1><h2>Section</h2><p>".str_repeat('content ', 300).'</p>',
        );
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonFragment([
                'title' => 'Meta description is too long',
                'category' => 'content',
                'severity' => 'minor',
            ]);
    }

    public function test_low_word_count_creates_an_important_issue_and_reduces_content_score(): void
    {
        $title = 'A descriptive page title for content SEO';
        $this->responseHtml = $this->contentHtml(
            $title,
            str_repeat('Useful meta description ', 4),
            "<h1>{$title}</h1><h2>Section</h2><p>Only a few words.</p>",
        );
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.word_count', 12)
            ->assertJsonFragment([
                'title' => 'Low word count',
                'category' => 'content',
                'severity' => 'important',
            ]);
        $this->assertLessThan(100, $response->json('audit.content_score'));
    }

    public function test_skipped_heading_level_and_title_h1_mismatch_create_minor_issues(): void
    {
        $this->responseHtml = $this->contentHtml(
            'A descriptive page title for content SEO',
            str_repeat('Useful meta description ', 4),
            '<h1>A different primary topic</h1><h3>Skipped section</h3><p>'.str_repeat('content ', 300).'</p>',
        );
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.title_matches_h1', false)
            ->assertJsonFragment([
                'title' => 'Page title does not align with H1',
                'category' => 'content',
                'severity' => 'minor',
            ])
            ->assertJsonFragment([
                'title' => 'Heading structure skips levels',
                'category' => 'content',
                'severity' => 'minor',
            ]);
    }

    public function test_high_missing_image_alt_ratio_creates_an_accessibility_issue(): void
    {
        $title = 'A descriptive page title for content SEO';
        $this->responseHtml = $this->contentHtml(
            $title,
            str_repeat('Useful meta description ', 4),
            "<h1>{$title}</h1><h2>Section</h2>".
                '<img src="one.jpg"><img src="two.jpg" alt=""><img src="three.jpg" alt="Useful">'.
                '<p>'.str_repeat('content ', 300).'</p>',
        );
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.images_alt_missing_ratio', 0.6667)
            ->assertJsonFragment([
                'title' => 'High image alt text missing ratio',
                'category' => 'accessibility',
                'severity' => 'important',
            ]);
    }

    public function test_link_seo_v2_classifies_internal_external_and_nofollow_links(): void
    {
        $this->responseHtml = $this->htmlWithLinks(<<<'HTML'
            <a href="/internal">Internal</a>
            <a href="https://example.org/external" rel="ugc NOFOLLOW">External</a>
            HTML);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.links_count', 2)
            ->assertJsonPath('raw_data.internal_links_count', 1)
            ->assertJsonPath('raw_data.external_links_count', 1)
            ->assertJsonPath('raw_data.nofollow_links_count', 1)
            ->assertJsonPath('raw_data.checked_links_count', 2)
            ->assertJsonPath('raw_data.broken_links_count', 0)
            ->assertJsonPath('raw_data.broken_links_sample', []);
    }

    public function test_empty_and_generic_anchor_links_create_minor_issues(): void
    {
        $this->responseHtml = $this->htmlWithLinks(<<<'HTML'
            <a href="/empty">   </a>
            <a href="/generic"><span>Read more</span></a>
            HTML);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.empty_anchor_links_count', 1)
            ->assertJsonPath('raw_data.generic_anchor_links_count', 1)
            ->assertJsonFragment([
                'title' => 'Links with empty anchor text',
                'category' => 'links',
                'severity' => 'minor',
            ])
            ->assertJsonFragment([
                'title' => 'Links with generic anchor text',
                'category' => 'links',
                'severity' => 'minor',
            ]);
    }

    public function test_broken_links_create_an_important_issue_and_reduce_the_links_score(): void
    {
        $this->responseHtml = $this->htmlWithLinks('<a href="/broken">Broken destination</a>');
        $this->linkStatuses['https://example.com/broken'] = 404;
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.checked_links_count', 1)
            ->assertJsonPath('raw_data.broken_links_count', 1)
            ->assertJsonPath('raw_data.broken_links_sample.0', 'https://example.com/broken')
            ->assertJsonPath('audit.links_score', 50)
            ->assertJsonFragment([
                'title' => 'Broken links found',
                'category' => 'links',
                'severity' => 'important',
            ]);
    }

    public function test_unsupported_and_unsafe_links_are_ignored_for_broken_link_checks(): void
    {
        $this->responseHtml = $this->htmlWithLinks(<<<'HTML'
            <a href="mailto:hello@example.com">Email</a>
            <a href="tel:+33123456789">Telephone</a>
            <a href="javascript:void(0)">Script</a>
            <a href="#section">Jump</a>
            <a href="http://127.0.0.1/private">Private service</a>
            HTML);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.links_count', 5)
            ->assertJsonPath('raw_data.checked_links_count', 0)
            ->assertJsonPath('raw_data.broken_links_count', 0);
        Http::assertSentCount(3);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), '127.0.0.1'));
    }

    public function test_checked_links_are_deduplicated_and_limited_to_twenty_five(): void
    {
        $links = '<a href="/same">Same link</a><a href="/same">Same link again</a>';
        for ($index = 1; $index <= 30; $index++) {
            $links .= "<a href=\"/link-{$index}\">Link {$index}</a>";
        }
        $this->responseHtml = $this->htmlWithLinks($links);
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.links_count', 32)
            ->assertJsonPath('raw_data.internal_links_count', 32)
            ->assertJsonPath('raw_data.checked_links_count', 25);
        Http::assertSentCount(37);
    }

    public function test_a_page_without_links_creates_an_important_links_issue(): void
    {
        $this->responseHtml = $this->htmlWithLinks('');
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.links_count', 0)
            ->assertJsonFragment([
                'title' => 'No links found',
                'category' => 'links',
                'severity' => 'important',
            ]);
    }

    public function test_multi_page_crawling_only_follows_same_host_internal_links(): void
    {
        $this->responseHtml = $this->htmlWithLinks(<<<'HTML'
            <a href="/internal">Internal page</a>
            <a href="https://other.example.com/external">External page</a>
            <a href="mailto:hello@example.com">Email</a>
            <a href="tel:+33123456789">Phone</a>
            <a href="javascript:void(0)">Script</a>
            <a href="#section">Fragment</a>
            HTML);
        $this->pageResponses['https://example.com/internal'] = [
            'body' => $this->crawlerPageHtml(
                'Internal SEO Landing Page',
                'A unique meta description for the crawled internal SEO landing page used in crawler tests.',
                '<h1>Internal SEO Landing Page</h1><h2>Section</h2><p>'.str_repeat('internal content ', 300).'</p>',
            ),
            'status' => 200,
        ];
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.crawl_enabled', true)
            ->assertJsonPath('raw_data.crawl_max_pages', 10)
            ->assertJsonPath('raw_data.crawl_max_depth', 2)
            ->assertJsonPath('raw_data.discovered_internal_urls_count', 1)
            ->assertJsonPath('raw_data.crawled_pages_count', 2)
            ->assertJsonPath('raw_data.crawled_pages.1.url', 'https://example.com/internal');

        $crawledUrls = array_column($response->json('raw_data.crawled_pages'), 'url');
        $this->assertNotContains('https://other.example.com/external', $crawledUrls);
    }

    public function test_multi_page_crawling_deduplicates_urls_and_stores_compact_summaries(): void
    {
        $this->responseHtml = $this->htmlWithLinks(<<<'HTML'
            <a href="/same">Same page</a>
            <a href="/same#section">Same page fragment</a>
            <a href="https://example.com/same/">Same page slash</a>
            HTML);
        $this->pageResponses['https://example.com/same'] = [
            'body' => $this->crawlerPageHtml(
                'Same Internal Page Title',
                'A unique meta description for the same internal page used by crawler deduplication tests.',
                '<h1>Same Internal Page</h1><h2>Section</h2><p>'.str_repeat('same content ', 300).'</p>'
                    .'<script type="application/ld+json">{"@type":"Product"}</script>',
            ),
            'status' => 200,
        ];
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.discovered_internal_urls_count', 1)
            ->assertJsonPath('raw_data.crawled_pages_count', 2)
            ->assertJsonPath('raw_data.crawled_pages.1.status_code', 200)
            ->assertJsonPath('raw_data.crawled_pages.1.depth', 1)
            ->assertJsonPath('raw_data.crawled_pages.1.title', 'Same Internal Page Title')
            ->assertJsonPath('raw_data.crawled_pages.1.meta_description', 'A unique meta description for the same internal page used by crawler deduplication tests.')
            ->assertJsonPath('raw_data.crawled_pages.1.h1', 'Same Internal Page')
            ->assertJsonPath('raw_data.crawled_pages.1.is_indexable', true)
            ->assertJsonPath('raw_data.crawled_pages.1.structured_data_found', true)
            ->assertJsonPath('raw_data.crawled_pages.1.schema_types', ['Product']);

        $this->assertGreaterThanOrEqual(300, $response->json('raw_data.crawled_pages.1.word_count'));
        $this->assertIsInt($response->json('raw_data.crawled_pages.1.response_time_ms'));
        $this->assertGreaterThanOrEqual(0, $response->json('raw_data.crawled_pages.1.response_time_ms'));
        $this->assertSame(
            strlen($this->pageResponses['https://example.com/same']['body']),
            $response->json('raw_data.crawled_pages.1.page_size_bytes'),
        );
        $this->assertArrayNotHasKey('visible_text_sample', $response->json('raw_data.crawled_pages.1'));
    }

    public function test_multi_page_crawling_respects_max_pages_limit(): void
    {
        $links = '';
        for ($index = 1; $index <= 15; $index++) {
            $links .= "<a href=\"/crawl-page-{$index}\">Page {$index}</a>";
            $this->pageResponses["https://example.com/crawl-page-{$index}"] = [
                'body' => $this->crawlerPageHtml(
                    "Crawl Page {$index} Unique Title",
                    "A unique meta description for crawled page number {$index} in the max pages test.",
                    "<h1>Crawl Page {$index}</h1><h2>Section</h2><p>".str_repeat("page {$index} content ", 300).'</p>',
                ),
                'status' => 200,
            ];
        }
        $this->responseHtml = $this->htmlWithLinks($links);
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.discovered_internal_urls_count', 15)
            ->assertJsonPath('raw_data.crawled_pages_count', 10);
    }

    public function test_multi_page_crawling_respects_max_depth_limit(): void
    {
        $this->responseHtml = $this->htmlWithLinks('<a href="/level-1">Level 1</a>');
        $this->pageResponses['https://example.com/level-1'] = [
            'body' => $this->crawlerPageHtml(
                'Level One Internal Page',
                'A unique meta description for level one in the multi page depth crawler test.',
                '<h1>Level One</h1><h2>Section</h2><p>'.str_repeat('level one content ', 300).'</p><a href="/level-2">Level 2</a>',
            ),
            'status' => 200,
        ];
        $this->pageResponses['https://example.com/level-2'] = [
            'body' => $this->crawlerPageHtml(
                'Level Two Internal Page',
                'A unique meta description for level two in the multi page depth crawler test.',
                '<h1>Level Two</h1><h2>Section</h2><p>'.str_repeat('level two content ', 300).'</p><a href="/level-3">Level 3</a>',
            ),
            'status' => 200,
        ];
        $this->pageResponses['https://example.com/level-3'] = [
            'body' => $this->crawlerPageHtml(
                'Level Three Internal Page',
                'A unique meta description for level three that should not be crawled at depth three.',
                '<h1>Level Three</h1><h2>Section</h2><p>'.str_repeat('level three content ', 300).'</p>',
            ),
            'status' => 200,
        ];
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.crawled_pages_count', 3)
            ->assertJsonPath('raw_data.crawled_pages.2.depth', 2);

        $this->assertNotContains('https://example.com/level-3', array_column($response->json('raw_data.crawled_pages'), 'url'));
    }

    public function test_multi_page_content_problems_create_issues(): void
    {
        $this->responseHtml = $this->htmlWithLinks(<<<'HTML'
            <a href="/missing-title">Missing title</a>
            <a href="/missing-meta">Missing meta</a>
            <a href="/missing-h1">Missing H1</a>
            <a href="/thin-page">Thin page</a>
            HTML);
        $this->pageResponses['https://example.com/missing-title'] = [
            'body' => $this->crawlerPageHtml(
                null,
                'A unique meta description for an internal page that is missing its title element.',
                '<h1>Missing Title Internal Page</h1><h2>Section</h2><p>'.str_repeat('content ', 300).'</p>',
            ),
            'status' => 200,
        ];
        $this->pageResponses['https://example.com/missing-meta'] = [
            'body' => $this->crawlerPageHtml(
                'Missing Meta Internal Page',
                null,
                '<h1>Missing Meta Internal Page</h1><h2>Section</h2><p>'.str_repeat('content ', 300).'</p>',
            ),
            'status' => 200,
        ];
        $this->pageResponses['https://example.com/missing-h1'] = [
            'body' => $this->crawlerPageHtml(
                'Missing H1 Internal Page',
                'A unique meta description for an internal page that is missing its H1 heading.',
                '<h2>Section</h2><p>'.str_repeat('content ', 300).'</p>',
            ),
            'status' => 200,
        ];
        $this->pageResponses['https://example.com/thin-page'] = [
            'body' => $this->crawlerPageHtml(
                'Thin Internal Page Content',
                'A unique meta description for an internal thin content page in crawler tests.',
                '<h1>Thin Internal Page</h1><h2>Section</h2><p>too little content</p>',
            ),
            'status' => 200,
        ];
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.pages_with_missing_title_count', 1)
            ->assertJsonPath('raw_data.pages_with_missing_meta_description_count', 1)
            ->assertJsonPath('raw_data.pages_with_missing_h1_count', 1)
            ->assertJsonPath('raw_data.pages_with_low_word_count_count', 1)
            ->assertJsonFragment(['title' => 'Crawled pages are missing titles', 'category' => 'content', 'severity' => 'important'])
            ->assertJsonFragment(['title' => 'Crawled pages are missing meta descriptions', 'category' => 'content', 'severity' => 'important'])
            ->assertJsonFragment(['title' => 'Crawled pages are missing H1 headings', 'category' => 'content', 'severity' => 'important'])
            ->assertJsonFragment(['title' => 'Crawled pages have low word count', 'category' => 'content', 'severity' => 'important']);

        $this->assertLessThan(65, $response->json('audit.content_score'));
    }

    public function test_multi_page_noindex_and_http_errors_create_issues(): void
    {
        $this->responseHtml = $this->htmlWithLinks(<<<'HTML'
            <a href="/noindex-page">Noindex</a>
            <a href="/server-error">Server error</a>
            HTML);
        $this->pageResponses['https://example.com/noindex-page'] = [
            'body' => $this->crawlerPageHtml(
                'Noindex Internal Page',
                'A unique meta description for a noindex internal page in crawler tests.',
                '<h1>Noindex Internal Page</h1><h2>Section</h2><p>'.str_repeat('content ', 300).'</p>',
                'noindex, follow',
            ),
            'status' => 200,
        ];
        $this->pageResponses['https://example.com/server-error'] = [
            'body' => '',
            'status' => 500,
        ];
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.pages_with_noindex_count', 1)
            ->assertJsonPath('raw_data.pages_with_http_errors_count', 1)
            ->assertJsonFragment(['title' => 'Crawled pages are marked noindex', 'category' => 'indexability', 'severity' => 'important'])
            ->assertJsonFragment(['title' => 'Crawled pages return HTTP errors', 'category' => 'technical', 'severity' => 'important']);
    }

    public function test_multi_page_duplicate_titles_meta_descriptions_and_h1_are_detected(): void
    {
        $this->responseHtml = $this->htmlWithLinks(<<<'HTML'
            <a href="/duplicate-one">Duplicate one</a>
            <a href="/duplicate-two">Duplicate two</a>
            HTML);
        foreach (['duplicate-one', 'duplicate-two'] as $slug) {
            $this->pageResponses["https://example.com/{$slug}"] = [
                'body' => $this->crawlerPageHtml(
                    'Shared Duplicate Page Title',
                    'A shared duplicate meta description for internal crawler duplicate detection tests.',
                    '<h1>Shared Duplicate H1</h1><h2>Section</h2><p>'.str_repeat("{$slug} content ", 300).'</p>',
                ),
                'status' => 200,
            ];
        }
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.duplicate_titles_count', 1)
            ->assertJsonPath('raw_data.duplicate_meta_descriptions_count', 1)
            ->assertJsonPath('raw_data.duplicate_h1_count', 1)
            ->assertJsonPath('raw_data.duplicate_title_groups.0.value', 'Shared Duplicate Page Title')
            ->assertJsonPath('raw_data.duplicate_title_groups.0.count', 2)
            ->assertJsonCount(2, 'raw_data.duplicate_title_groups.0.urls')
            ->assertJsonPath(
                'raw_data.duplicate_meta_description_groups.0.value',
                'A shared duplicate meta description for internal crawler duplicate detection tests.',
            )
            ->assertJsonPath('raw_data.duplicate_meta_description_groups.0.count', 2)
            ->assertJsonPath('raw_data.duplicate_h1_groups.0.value', 'Shared Duplicate H1')
            ->assertJsonPath('raw_data.duplicate_h1_groups.0.count', 2)
            ->assertJsonFragment(['title' => 'Duplicate page titles found', 'category' => 'content', 'severity' => 'important'])
            ->assertJsonFragment(['title' => 'Duplicate meta descriptions found', 'category' => 'content', 'severity' => 'important'])
            ->assertJsonFragment(['title' => 'Duplicate H1 headings found', 'category' => 'content', 'severity' => 'minor']);
    }

    public function test_duplicate_content_groups_are_compact_and_create_an_issue(): void
    {
        $this->responseHtml = $this->htmlWithLinks(<<<'HTML'
            <a href="/copy-one">Copy one</a>
            <a href="/copy-two">Copy two</a>
            HTML);
        $sharedBody = '<h1>Shared Content Heading</h1><h2>Section</h2><p>'.str_repeat('shared substantive page content ', 150).'</p>';
        $this->pageResponses['https://example.com/copy-one'] = [
            'body' => $this->contentHtml(
                'First Unique Page Title For Duplicate Content',
                'A unique meta description for the first page in the duplicate content detection test.',
                $sharedBody,
                '/copy-one',
            ),
            'status' => 200,
        ];
        $this->pageResponses['https://example.com/copy-two'] = [
            'body' => $this->contentHtml(
                'Second Unique Page Title For Duplicate Content',
                'A unique meta description for the second page in the duplicate content detection test.',
                $sharedBody,
                '/copy-two',
            ),
            'status' => 200,
        ];
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.duplicate_content_count', 1)
            ->assertJsonPath('raw_data.duplicate_content_groups.0.count', 2)
            ->assertJsonCount(2, 'raw_data.duplicate_content_groups.0.urls')
            ->assertJsonFragment([
                'title' => 'Duplicate page content found',
                'category' => 'content',
                'severity' => 'important',
            ]);

        $group = $response->json('raw_data.duplicate_content_groups.0');
        $this->assertSame(16, strlen($group['fingerprint']));
        $this->assertArrayNotHasKey('text', $group);
        $this->assertArrayNotHasKey('visible_text_sample', $group);
    }

    public function test_thin_content_pages_are_counted_sampled_and_create_an_issue(): void
    {
        $this->responseHtml = $this->contentHtml(
            'Site Quality Crawl Starting Page Title',
            'A descriptive meta description for the healthy starting page in the thin content crawler test.',
            '<h1>Site Quality Crawl</h1><h2>Section</h2><p>'.str_repeat('healthy main content ', 150).'</p><a href="/thin-only">Thin page</a>',
        );
        $this->pageResponses['https://example.com/thin-only'] = [
            'body' => $this->contentHtml(
                'Thin Internal Content Page Title',
                'A descriptive meta description for the intentionally thin internal page in this test.',
                '<h1>Thin Page</h1><h2>Section</h2><p>Only a few words.</p>',
                '/thin-only',
            ),
            'status' => 200,
        ];
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.thin_content_pages_count', 1)
            ->assertJsonPath('raw_data.thin_content_pages_sample.0.url', 'https://example.com/thin-only')
            ->assertJsonPath('raw_data.thin_content_pages_sample.0.word_count', 7)
            ->assertJsonFragment([
                'title' => 'Thin content pages found',
                'category' => 'content',
                'severity' => 'important',
            ]);
    }

    public function test_canonical_conflicts_are_detected_and_create_an_issue(): void
    {
        $this->responseHtml = $this->contentHtml(
            'Canonical Conflict Crawl Starting Page',
            'A descriptive meta description for a crawl that contains an internal canonical conflict.',
            '<h1>Canonical Conflict Crawl</h1><h2>Section</h2><p>'.str_repeat('healthy main content ', 150).'</p><a href="/canonical-conflict">Conflict</a>',
        );
        $this->pageResponses['https://example.com/canonical-conflict'] = [
            'body' => $this->contentHtml(
                'Internal Page With Canonical Conflict',
                'A descriptive meta description for an internal page whose canonical points externally.',
                '<h1>Canonical Conflict</h1><h2>Section</h2><p>'.str_repeat('unique conflict content ', 150).'</p>',
                'https://external.example.org/preferred',
            ),
            'status' => 200,
        ];
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.canonical_conflicts_count', 1)
            ->assertJsonPath('raw_data.site_quality_warnings_count', 1)
            ->assertJsonPath('raw_data.canonical_conflicts_sample.0.url', 'https://example.com/canonical-conflict')
            ->assertJsonPath('raw_data.canonical_conflicts_sample.0.canonical_url', 'https://external.example.org/preferred')
            ->assertJsonFragment([
                'title' => 'Canonical conflicts found',
                'category' => 'indexability',
                'severity' => 'important',
            ]);
    }

    public function test_sitemap_orphan_urls_are_counted_and_sampled(): void
    {
        $this->sitemapBody = <<<'XML'
            <urlset>
                <url><loc>https://example.com/page</loc></url>
                <url><loc>https://example.com/orphan-page</loc></url>
            </urlset>
            XML;
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.sitemap_urls_sample', [
                'https://example.com/page',
                'https://example.com/orphan-page',
            ])
            ->assertJsonPath('raw_data.sitemap_orphan_urls_count', 1)
            ->assertJsonPath('raw_data.sitemap_orphan_urls_sample', ['https://example.com/orphan-page'])
            ->assertJsonFragment([
                'title' => 'Sitemap orphan URLs found',
                'category' => 'indexability',
                'severity' => 'minor',
            ]);
    }

    public function test_missing_title_creates_an_audit_issue(): void
    {
        $this->responseHtml = '<html lang="en"><head><link rel="canonical" href="/"><meta name="viewport" content="width=device-width"><meta name="description" content="Present"></head><body><h1>Heading</h1><h2>Section</h2></body></html>';
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com']);

        $response
            ->assertOk()
            ->assertJsonPath('audit.content_score', 50)
            ->assertJsonFragment(['title' => 'Missing page title']);
        $this->assertDatabaseHas('audit_issues', ['title' => 'Missing page title']);
    }

    public function test_missing_meta_description_creates_an_audit_issue(): void
    {
        $this->responseHtml = '<html lang="en"><head><link rel="canonical" href="/"><meta name="viewport" content="width=device-width"><title>Present</title></head><body><h1>Heading</h1><h2>Section</h2></body></html>';
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com']);

        $response
            ->assertOk()
            ->assertJsonPath('audit.content_score', 50)
            ->assertJsonFragment(['title' => 'Missing meta description']);
        $this->assertDatabaseHas('audit_issues', ['title' => 'Missing meta description']);
    }

    public function test_non_https_url_lowers_the_technical_score(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'http://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('audit.technical_score', 60)
            ->assertJsonFragment(['title' => 'Page does not use HTTPS']);
    }

    public function test_global_score_is_the_rounded_weighted_average_of_category_scores(): void
    {
        $this->responseHtml = '<html lang="en"><head><link rel="canonical" href="/page"><meta name="viewport" content="width=device-width"><meta name="description" content="Present"><script type="application/ld+json">{"@type":"WebPage"}</script></head><body><h1>Heading</h1><h2>Section</h2></body></html>';
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('audit.technical_score', 100)
            ->assertJsonPath('audit.content_score', 50)
            ->assertJsonPath('audit.links_score', 70)
            ->assertJsonPath('audit.performance_score', 100)
            ->assertJsonPath('audit.global_score', 78);
    }

    public function test_all_calculated_scores_remain_between_zero_and_one_hundred(): void
    {
        $this->responseHtml = '<html><body>'.str_repeat('<img src="image.jpg">', 30).'</body></html>';
        $this->robotsStatus = 404;
        $this->sitemapStatus = 404;
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'http://example.com/page']);
        $response->assertOk();

        foreach (['global_score', 'technical_score', 'content_score', 'links_score', 'performance_score'] as $score) {
            $value = $response->json("audit.{$score}");
            $this->assertIsInt($value);
            $this->assertGreaterThanOrEqual(0, $value);
            $this->assertLessThanOrEqual(100, $value);
        }
    }

    public function test_images_without_alt_text_create_an_audit_issue(): void
    {
        $this->responseHtml = '<html><head><title>Present</title><meta name="description" content="Present"></head><body><h1>Heading</h1><img src="one.jpg"><img src="two.jpg" alt=""></body></html>';
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.images_missing_alt_count', 2)
            ->assertJsonFragment(['title' => 'Images missing alt text']);
        $this->assertDatabaseHas('audit_issues', [
            'title' => 'Images missing alt text',
            'description' => '2 image(s) are missing alt text.',
        ]);
    }

    public function test_json_ld_objects_arrays_and_graph_types_are_detected(): void
    {
        $this->responseHtml = $this->contentHtml(
            'Structured Data Analysis Page Title',
            'A descriptive meta description for the structured data JSON-LD analysis test page.',
            <<<'HTML'
                <h1>Structured Data Analysis</h1><h2>Details</h2>
                <script type="application/ld+json">
                    {"@context":"https://schema.org","@type":"Organization"}
                </script>
                <script type="application/ld+json">
                    [
                        {"@type":"WebSite"},
                        {"@graph":[{"@type":"BreadcrumbList"},{"@type":["Article","Person"]}]}
                    ]
                </script>
                HTML,
        );
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.structured_data_found', true)
            ->assertJsonPath('raw_data.structured_data_formats', ['json_ld'])
            ->assertJsonPath('raw_data.json_ld_count', 2)
            ->assertJsonPath('raw_data.schema_types', [
                'Organization',
                'WebSite',
                'BreadcrumbList',
                'Article',
                'Person',
            ])
            ->assertJsonPath('raw_data.important_schema_types_found', [
                'Organization',
                'WebSite',
                'BreadcrumbList',
                'Article',
                'Person',
            ])
            ->assertJsonPath('raw_data.structured_data_errors_count', 0)
            ->assertJsonPath('raw_data.structured_data_errors_sample', []);
    }

    public function test_invalid_json_ld_creates_an_important_structured_data_issue(): void
    {
        $this->responseHtml = $this->contentHtml(
            'Invalid Structured Data Page Title',
            'A descriptive meta description for a page containing an invalid JSON-LD data block.',
            '<h1>Invalid Structured Data</h1><h2>Details</h2>'
                .str_repeat('<script type="application/ld+json">{"@type":</script>', 7),
        );
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.structured_data_found', true)
            ->assertJsonPath('raw_data.json_ld_count', 7)
            ->assertJsonPath('raw_data.structured_data_errors_count', 7)
            ->assertJsonCount(5, 'raw_data.structured_data_errors_sample')
            ->assertJsonFragment([
                'title' => 'Invalid JSON-LD found',
                'category' => 'structured_data',
                'severity' => 'important',
            ]);
        $this->assertLessThan(100, $response->json('audit.technical_score'));
    }

    public function test_microdata_and_rdfa_types_are_detected(): void
    {
        $this->responseHtml = $this->contentHtml(
            'Microdata and RDFa Analysis Page',
            'A descriptive meta description for the combined Microdata and RDFa structured data test.',
            <<<'HTML'
                <h1>Structured Data Formats</h1><h2>Details</h2>
                <div itemscope itemtype="https://schema.org/Product"><span itemprop="name">Product</span></div>
                <div vocab="https://schema.org/" typeof="Person"><span property="name">Person</span></div>
                HTML,
        );
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.structured_data_found', true)
            ->assertJsonPath('raw_data.structured_data_formats', ['microdata', 'rdfa'])
            ->assertJsonPath('raw_data.microdata_found', true)
            ->assertJsonPath('raw_data.rdfa_found', true)
            ->assertJsonPath('raw_data.schema_types', ['Product', 'Person']);
    }

    public function test_no_structured_data_creates_a_minor_issue(): void
    {
        $this->responseHtml = $this->contentHtml(
            'Page Without Structured Data',
            'A descriptive meta description for a page that intentionally has no structured data markup.',
            '<h1>No Structured Data</h1><h2>Details</h2>',
        );
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.structured_data_found', false)
            ->assertJsonPath('raw_data.structured_data_formats', [])
            ->assertJsonPath('raw_data.schema_types', [])
            ->assertJsonFragment([
                'title' => 'No structured data found',
                'category' => 'structured_data',
                'severity' => 'minor',
            ]);
        $this->assertLessThan(100, $response->json('audit.technical_score'));
    }

    public function test_homepage_recommends_missing_organization_and_website_schema(): void
    {
        $this->responseHtml = $this->contentHtml(
            'Homepage Without Recommended Schema',
            'A descriptive homepage meta description without Organization or WebSite structured data.',
            '<h1>Homepage</h1><h2>Welcome</h2>',
        );
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/'])
            ->assertOk()
            ->assertJsonPath('raw_data.recommended_schema_types_missing', ['Organization', 'WebSite'])
            ->assertJsonFragment([
                'title' => 'Recommended schema types are missing',
                'category' => 'structured_data',
                'severity' => 'minor',
            ]);
    }

    public function test_breadcrumb_navigation_without_breadcrumb_schema_creates_an_issue(): void
    {
        $this->responseHtml = $this->contentHtml(
            'Page With Breadcrumb Navigation',
            'A descriptive meta description for a page with visible breadcrumb navigation markup.',
            '<nav aria-label="Breadcrumb"><a href="/">Home</a> / Page</nav><h1>Page</h1><h2>Details</h2>',
        );
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.recommended_schema_types_missing', ['BreadcrumbList'])
            ->assertJsonFragment([
                'title' => 'Breadcrumb navigation lacks BreadcrumbList schema',
                'category' => 'structured_data',
                'severity' => 'minor',
            ]);
    }

    public function test_article_like_content_recommends_article_schema(): void
    {
        $this->responseHtml = $this->contentHtml(
            'Article Without Structured Data',
            'A descriptive meta description for an article page that does not yet include Article schema.',
            '<article><h1>SEO Guide</h1><h2>Details</h2><p>Article content.</p></article>',
        );
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/blog/seo-guide'])
            ->assertOk()
            ->assertJsonPath('raw_data.recommended_schema_types_missing', ['Article']);
    }

    public function test_technical_seo_v2_fields_are_extracted_and_stored(): void
    {
        $this->responseHtml = <<<'HTML'
            <!doctype html>
            <html lang="fr">
                <head>
                    <title>Technical SEO</title>
                    <meta name="description" content="Technical SEO checks">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <link rel="canonical" href="/technical">
                </head>
                <body>
                    <h1>One</h1><h2>Two</h2><h3>Three</h3>
                    <h4>Four</h4><h5>Five</h5><h6>Six</h6>
                    <a href="/next">Next</a>
                </body>
            </html>
            HTML;
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/technical']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.http_status_code', 200)
            ->assertJsonPath('raw_data.final_url', 'https://example.com/technical')
            ->assertJsonPath('raw_data.redirect_count', 0)
            ->assertJsonPath('raw_data.canonical_url', 'https://example.com/technical')
            ->assertJsonPath('raw_data.canonical_matches_final_url', true)
            ->assertJsonPath('raw_data.html_lang', 'fr')
            ->assertJsonPath('raw_data.viewport_found', true)
            ->assertJsonPath('raw_data.h1_count', 1)
            ->assertJsonPath('raw_data.h2_count', 1)
            ->assertJsonPath('raw_data.h3_count', 1)
            ->assertJsonPath('raw_data.h4_count', 1)
            ->assertJsonPath('raw_data.h5_count', 1)
            ->assertJsonPath('raw_data.h6_count', 1)
            ->assertJsonPath('raw_data.page_size_bytes', strlen($this->responseHtml));

        $this->assertIsInt($response->json('raw_data.response_time_ms'));
        $this->assertGreaterThanOrEqual(0, $response->json('raw_data.response_time_ms'));
        $stored = Audit::findOrFail($response->json('audit.id'))->raw_data;
        $this->assertSame(strlen($this->responseHtml), $stored['page_size_bytes']);
        $this->assertArrayHasKey('response_time_ms', $stored);
    }

    public function test_performance_response_metadata_is_extracted_and_stored(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.content_type', 'text/html; charset=UTF-8')
            ->assertJsonPath('raw_data.content_encoding', 'gzip')
            ->assertJsonPath('raw_data.compression_enabled', true)
            ->assertJsonPath('raw_data.cache_control', 'public, max-age=300')
            ->assertJsonPath('raw_data.cache_headers_present', true)
            ->assertJsonPath('raw_data.server_header', 'example-server')
            ->assertJsonPath('raw_data.is_html_response', true)
            ->assertJsonPath('raw_data.performance_warnings_count', 0)
            ->assertJsonPath('raw_data.html_size_kb', round(strlen($this->responseHtml) / 1024, 2));

        $stored = Audit::findOrFail($response->json('audit.id'))->raw_data;
        $this->assertSame('gzip', $stored['content_encoding']);
        $this->assertTrue($stored['compression_enabled']);
        $this->assertTrue($stored['cache_headers_present']);
    }

    public function test_slow_response_creates_an_important_performance_issue(): void
    {
        $this->responseDelayMicroseconds = 2_050_000;
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonFragment([
                'title' => 'Page response is slow',
                'category' => 'performance',
                'severity' => 'important',
            ]);
        $this->assertGreaterThan(2000, $response->json('raw_data.response_time_ms'));
        $this->assertLessThan(100, $response->json('audit.performance_score'));
    }

    public function test_very_slow_response_creates_a_critical_performance_issue(): void
    {
        $this->responseDelayMicroseconds = 5_050_000;
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonFragment([
                'title' => 'Page response is very slow',
                'category' => 'performance',
                'severity' => 'critical',
            ])
            ->assertJsonMissing(['title' => 'Page response is slow']);
        $this->assertGreaterThan(5000, $response->json('raw_data.response_time_ms'));
    }

    public function test_large_page_creates_an_important_performance_issue(): void
    {
        $this->responseHtml .= str_repeat(' ', 1_000_001);
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonFragment([
                'title' => 'HTML page payload is large',
                'category' => 'performance',
                'severity' => 'important',
            ])
            ->assertJsonPath('raw_data.performance_warnings_count', 1);
    }

    public function test_very_large_page_creates_a_critical_performance_issue(): void
    {
        $this->responseHtml .= str_repeat(' ', 3_000_001);
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonFragment([
                'title' => 'HTML page payload is very large',
                'category' => 'performance',
                'severity' => 'critical',
            ])
            ->assertJsonMissing(['title' => 'HTML page payload is large']);
    }

    public function test_missing_html_compression_creates_an_important_performance_issue(): void
    {
        unset($this->responseHeaders['Content-Encoding']);
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.content_encoding', null)
            ->assertJsonPath('raw_data.compression_enabled', false)
            ->assertJsonFragment([
                'title' => 'HTML response compression is missing',
                'category' => 'performance',
                'severity' => 'important',
            ]);
    }

    public function test_missing_cache_headers_creates_a_minor_performance_issue(): void
    {
        unset($this->responseHeaders['Cache-Control']);
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.cache_control', null)
            ->assertJsonPath('raw_data.cache_headers_present', false)
            ->assertJsonFragment([
                'title' => 'Cache headers are missing',
                'category' => 'performance',
                'severity' => 'minor',
            ]);
    }

    public function test_non_html_response_creates_an_important_technical_issue(): void
    {
        $this->responseHeaders['Content-Type'] = 'application/json';
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.content_type', 'application/json')
            ->assertJsonPath('raw_data.is_html_response', false)
            ->assertJsonFragment([
                'title' => 'Audited page is not an HTML response',
                'category' => 'technical',
                'severity' => 'important',
            ]);
    }

    public function test_missing_canonical_viewport_and_html_lang_create_issues(): void
    {
        $this->responseHtml = '<html><head><title>Page</title><meta name="description" content="Description"></head><body><h1>Heading</h1><h2>Section</h2></body></html>';
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.canonical_url', null)
            ->assertJsonPath('raw_data.viewport_found', false)
            ->assertJsonPath('raw_data.html_lang', null)
            ->assertJsonFragment(['title' => 'Missing canonical tag', 'category' => 'indexability', 'severity' => 'important'])
            ->assertJsonFragment(['title' => 'Missing meta viewport', 'category' => 'technical', 'severity' => 'important'])
            ->assertJsonFragment(['title' => 'Missing HTML lang attribute', 'category' => 'accessibility', 'severity' => 'minor']);
    }

    public function test_meta_robots_noindex_creates_a_critical_issue(): void
    {
        $this->responseHtml = '<html lang="en"><head><title>Page</title><meta name="description" content="Description"><meta name="viewport" content="width=device-width"><meta name="robots" content="noindex, follow"><link rel="canonical" href="/page"></head><body><h1>Heading</h1><h2>Section</h2></body></html>';
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.meta_robots', 'noindex, follow')
            ->assertJsonPath('raw_data.is_indexable', false)
            ->assertJsonFragment(['title' => 'Page is marked noindex', 'category' => 'indexability', 'severity' => 'critical']);
    }

    public function test_non_200_final_response_is_audited_as_a_critical_issue(): void
    {
        $this->pageStatus = 404;
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/page']);

        $response
            ->assertOk()
            ->assertJsonPath('raw_data.http_status_code', 404)
            ->assertJsonPath('raw_data.is_indexable', false)
            ->assertJsonFragment(['title' => 'Page does not return HTTP 200', 'category' => 'technical', 'severity' => 'critical']);
    }

    public function test_redirect_chain_and_final_url_are_recorded(): void
    {
        $this->redirects = [
            'https://example.com/start' => ['status' => 301, 'location' => '/middle'],
            'https://example.com/middle' => ['status' => 302, 'location' => 'https://example.com/page'],
        ];
        Sanctum::actingAs(User::factory()->create());

        $response = $this->processAuditThroughJob(['url' => 'https://example.com/start']);

        $response
            ->assertOk()
            ->assertJsonPath('audit.requested_url', 'https://example.com/start')
            ->assertJsonPath('audit.final_url', 'https://example.com/page')
            ->assertJsonPath('raw_data.final_url', 'https://example.com/page')
            ->assertJsonPath('raw_data.redirect_count', 2)
            ->assertJsonPath('raw_data.canonical_matches_final_url', true)
            ->assertJsonFragment(['title' => 'Page has a redirect chain', 'category' => 'technical', 'severity' => 'minor']);
    }

    public function test_unsafe_urls_are_rejected_without_making_http_requests(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach (['http://localhost', 'http://127.0.0.1'] as $url) {
            parent::postJson('/api/audits', ['url' => $url])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('url');
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('audits', 0);
    }

    public function test_invalid_audit_urls_are_rejected_before_crawling(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach ([[], ['url' => 'not-a-url'], ['url' => 'ftp://example.com']] as $payload) {
            parent::postJson('/api/audits', $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('url');
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('domains', 0);
        $this->assertDatabaseCount('audits', 0);
    }

    public function test_a_redirect_to_a_private_address_is_blocked(): void
    {
        $this->redirects = [
            'https://example.com/start' => [
                'status' => 302,
                'location' => 'http://127.0.0.1/private',
            ],
        ];
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/start'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        Http::assertSentCount(1);
        Http::assertNotSent(
            fn (Request $request) => $request->url() === 'http://127.0.0.1/private',
        );
        $this->assertDatabaseHas('audits', [
            'status' => Audit::STATUS_FAILED,
            'failure_reason' => RunSeoAuditJob::GENERIC_FAILURE_REASON,
        ]);
    }

    #[DataProvider('blockedRedirectProvider')]
    public function test_redirects_to_special_addresses_or_ports_are_blocked(string $destination): void
    {
        $this->redirects = [
            'https://example.com/start' => [
                'status' => 302,
                'location' => $destination,
            ],
        ];
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/start'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => $request->url() === $destination);
        $this->assertDatabaseHas('audits', [
            'status' => Audit::STATUS_FAILED,
            'failure_reason' => RunSeoAuditJob::GENERIC_FAILURE_REASON,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function blockedRedirectProvider(): array
    {
        return [
            'shared address space' => ['http://100.64.0.1/private'],
            'benchmark network' => ['http://198.18.0.1/private'],
            'non-standard port' => ['https://example.com:8443/private'],
            'IPv6 loopback' => ['http://[::1]/private'],
            'IPv6 private' => ['https://[fc00::1]/private'],
            'IPv6 link-local' => ['http://[fe80::1]/private'],
            'URL credentials' => ['https://user:password@example.com/private'],
        ];
    }

    public function test_a_redirect_to_an_unresolved_hostname_is_blocked(): void
    {
        $destination = 'https://unresolved.example/private';
        $this->dnsAnswers['unresolved.example'] = [];
        $this->redirects = [
            'https://example.com/start' => [
                'status' => 302,
                'location' => $destination,
            ],
        ];
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/start'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request) => $request->url() === $destination);
    }

    public function test_a_secondary_resource_redirect_to_a_special_address_is_not_followed(): void
    {
        $destination = 'http://100.64.0.1/robots.txt';
        $this->redirects = [
            'https://example.com/robots.txt' => [
                'status' => 302,
                'location' => $destination,
            ],
        ];
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.robots_txt_found', false);

        Http::assertNotSent(fn (Request $request) => $request->url() === $destination);
    }

    public function test_a_secondary_link_redirect_to_a_special_address_is_not_followed(): void
    {
        $link = 'https://external.example/link';
        $destination = 'http://198.18.0.1/private';
        $this->responseHtml = $this->htmlWithLinks(
            '<a href="'.$link.'">External link</a>',
        );
        $this->redirects = [
            $link => [
                'status' => 302,
                'location' => $destination,
            ],
        ];
        Sanctum::actingAs(User::factory()->create());

        $this->processAuditThroughJob(['url' => 'https://example.com/page'])
            ->assertOk()
            ->assertJsonPath('raw_data.checked_links_count', 0);

        Http::assertSent(fn (Request $request) => $request->url() === $link);
        Http::assertNotSent(fn (Request $request) => $request->url() === $destination);
    }

    public function test_creating_an_audit_reuses_the_users_existing_domain(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $firstResponse = parent::postJson('/api/audits', ['url' => 'https://example.com/first'])
            ->assertAccepted()
            ->assertJsonPath('audit.requested_url', 'https://example.com/first');
        $secondResponse = parent::postJson('/api/audits', ['url' => 'https://example.com/second'])
            ->assertAccepted()
            ->assertJsonPath('audit.requested_url', 'https://example.com/second');

        $this->assertDatabaseCount('domains', 1);
        $this->assertDatabaseCount('audits', 2);
        $this->assertSame(
            Audit::findOrFail($firstResponse->json('audit.id'))->domain_id,
            Audit::findOrFail($secondResponse->json('audit.id'))->domain_id,
        );
        $this->assertDatabaseHas('audits', [
            'id' => $firstResponse->json('audit.id'),
            'requested_url' => 'https://example.com/first',
        ]);
        $this->assertDatabaseHas('audits', [
            'id' => $secondResponse->json('audit.id'),
            'requested_url' => 'https://example.com/second',
        ]);
    }

    public function test_an_authenticated_user_can_list_only_their_audits(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $startedAt = now()->startOfSecond()->subMinute();
        $ownAudit = $this->createAuditFor($user, 'own.example.com', [
            'requested_url' => 'https://own.example.com/requested',
            'final_url' => 'https://own.example.com/final',
            'status' => Audit::STATUS_RUNNING,
            'started_at' => $startedAt,
            'raw_data' => [
                'large_nested_crawl_data' => str_repeat('private-detail-', 100),
            ],
            'failure_reason' => 'Internal failure details.',
        ]);
        $otherAudit = $this->createAuditFor($otherUser, 'other.example.com');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/audits');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'audits')
            ->assertJsonPath('audits.0.id', $ownAudit->id)
            ->assertJsonPath('audits.0.domain_id', $ownAudit->domain_id)
            ->assertJsonPath('audits.0.requested_url', 'https://own.example.com/requested')
            ->assertJsonPath('audits.0.final_url', 'https://own.example.com/final')
            ->assertJsonPath('audits.0.status', Audit::STATUS_RUNNING)
            ->assertJsonPath('audits.0.started_at', $startedAt->toJSON())
            ->assertJsonPath('audits.0.completed_at', null)
            ->assertJsonPath('audits.0.failed_at', null)
            ->assertJsonMissingPath('audits.0.raw_data')
            ->assertJsonMissingPath('audits.0.failure_reason')
            ->assertJsonMissingPath('audits.0.domain')
            ->assertJsonMissingPath('audits.0.issues')
            ->assertJsonMissingPath('audits.0.ai_recommendations')
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.per_page', 20)
            ->assertJsonPath('pagination.total', 1)
            ->assertJsonStructure([
                'pagination' => [
                    'current_page',
                    'last_page',
                    'per_page',
                    'total',
                    'from',
                    'to',
                    'first_page_url',
                    'last_page_url',
                    'previous_page_url',
                    'next_page_url',
                ],
            ])
            ->assertJsonMissing(['id' => $otherAudit->id]);

        $this->assertEqualsCanonicalizing([
            'id',
            'domain_id',
            'requested_url',
            'final_url',
            'status',
            'global_score',
            'technical_score',
            'content_score',
            'links_score',
            'performance_score',
            'created_at',
            'updated_at',
            'started_at',
            'completed_at',
            'failed_at',
        ], array_keys($response->json('audits.0')));
    }

    public function test_audit_index_returns_every_async_status_as_a_summary(): void
    {
        $user = User::factory()->create();
        $audits = collect([
            Audit::STATUS_PENDING,
            Audit::STATUS_RUNNING,
            Audit::STATUS_COMPLETED,
            Audit::STATUS_FAILED,
        ])->mapWithKeys(function (string $status) use ($user): array {
            $audit = $this->createAuditFor($user, "{$status}.example.com", [
                'requested_url' => "https://{$status}.example.com/page",
                'status' => $status,
                'started_at' => $status === Audit::STATUS_PENDING ? null : now()->subMinutes(2),
                'completed_at' => $status === Audit::STATUS_COMPLETED ? now()->subMinute() : null,
                'failed_at' => $status === Audit::STATUS_FAILED ? now()->subMinute() : null,
                'failure_reason' => $status === Audit::STATUS_FAILED ? 'Sensitive failure detail.' : null,
                'raw_data' => ['status_private_data' => $status],
            ]);

            return [$audit->id => $status];
        });
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/audits');

        $response
            ->assertOk()
            ->assertJsonCount(4, 'audits')
            ->assertJsonPath('pagination.total', 4);

        $summaries = collect($response->json('audits'))->keyBy('id');
        foreach ($audits as $auditId => $status) {
            $this->assertSame($status, $summaries[$auditId]['status']);
            $this->assertArrayNotHasKey('raw_data', $summaries[$auditId]);
            $this->assertArrayNotHasKey('failure_reason', $summaries[$auditId]);
        }
    }

    public function test_audit_index_is_paginated_twenty_per_page(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        foreach (range(1, 25) as $auditNumber) {
            $this->createAuditFor($user, "own-{$auditNumber}.example.com");
        }

        $otherAudit = $this->createAuditFor($otherUser, 'other.example.com');
        Sanctum::actingAs($user);

        $firstPage = $this->getJson('/api/audits');

        $firstPage
            ->assertOk()
            ->assertJsonCount(20, 'audits')
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonPath('pagination.per_page', 20)
            ->assertJsonPath('pagination.total', 25)
            ->assertJsonPath('pagination.from', 1)
            ->assertJsonPath('pagination.to', 20)
            ->assertJsonMissing(['id' => $otherAudit->id]);

        $secondPage = $this->getJson('/api/audits?page=2');

        $secondPage
            ->assertOk()
            ->assertJsonCount(5, 'audits')
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.per_page', 20)
            ->assertJsonPath('pagination.total', 25)
            ->assertJsonPath('pagination.from', 21)
            ->assertJsonPath('pagination.to', 25)
            ->assertJsonMissing(['id' => $otherAudit->id]);
    }

    public function test_an_authenticated_user_can_view_their_own_audit(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user, 'own.example.com', [
            'requested_url' => 'https://own.example.com/requested',
            'final_url' => 'https://own.example.com/final',
            'status' => Audit::STATUS_COMPLETED,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'raw_data' => [
                'title' => 'Detailed audit title',
                'crawled_pages' => [
                    ['url' => 'https://own.example.com/internal'],
                ],
            ],
        ]);
        $issue = $audit->issues()->create([
            'category' => 'content',
            'title' => 'Detailed audit issue',
            'severity' => 'important',
            'description' => 'Detailed evidence.',
            'recommendation' => 'Detailed recommendation.',
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/audits/{$audit->id}")
            ->assertOk()
            ->assertJsonPath('audit.id', $audit->id)
            ->assertJsonPath('audit.domain.user_id', $user->id)
            ->assertJsonPath('audit.status', Audit::STATUS_COMPLETED)
            ->assertJsonPath('audit.requested_url', 'https://own.example.com/requested')
            ->assertJsonPath('audit.final_url', 'https://own.example.com/final')
            ->assertJsonPath('audit.raw_data.title', 'Detailed audit title')
            ->assertJsonPath('audit.raw_data.crawled_pages.0.url', 'https://own.example.com/internal')
            ->assertJsonPath('audit.issues.0.id', $issue->id)
            ->assertJsonPath('audit.issues.0.title', 'Detailed audit issue');
    }

    public function test_an_authenticated_user_cannot_view_another_users_audit(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherAudit = $this->createAuditFor($otherUser, 'other.example.com');
        Sanctum::actingAs($user);

        $this->getJson("/api/audits/{$otherAudit->id}")->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAuditFor(User $user, string $domainName, array $attributes = []): Audit
    {
        $domain = Domain::create([
            'user_id' => $user->id,
            'domain_name' => $domainName,
            'url' => "https://{$domainName}",
        ]);

        return $domain->audits()->create([
            'global_score' => 0,
            'technical_score' => 0,
            'content_score' => 0,
            'links_score' => 0,
            'performance_score' => 0,
            'raw_data' => null,
            ...$attributes,
        ]);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function streamedResponse(
        int $totalBytes,
        array $headers,
        object $progress,
        int $status = 200,
        string $contents = 'x',
    ): PromiseInterface {
        $remainingBytes = $totalBytes;
        $offset = 0;
        $useExactContents = strlen($contents) === $totalBytes;
        $stream = new PumpStream(function (int $requestedBytes) use (
            &$remainingBytes,
            &$offset,
            $progress,
            $contents,
            $useExactContents,
        ): ?string {
            if ($remainingBytes === 0) {
                return null;
            }

            $bytes = min($requestedBytes, $remainingBytes);
            $chunk = $useExactContents
                ? substr($contents, $offset, $bytes)
                : str_repeat($contents, (int) ceil($bytes / strlen($contents)));
            $chunk = substr($chunk, 0, $bytes);
            $remainingBytes -= $bytes;
            $offset += $bytes;
            $progress->bytes += $bytes;

            return $chunk;
        });

        return Create::promiseFor(new Psr7Response($status, $headers, $stream));
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function fakeSecondaryResourceStream(
        string $path,
        int $totalBytes,
        array $headers,
        object $progress,
    ): void {
        $this->replaceHttpFake(function (Request $request) use ($path, $totalBytes, $headers, $progress) {
            if (str_ends_with($request->url(), $path)) {
                return $this->streamedResponse($totalBytes, $headers, $progress);
            }

            if (str_ends_with($request->url(), '/robots.txt')) {
                return Http::response('User-agent: *');
            }

            if (str_ends_with($request->url(), '/sitemap.xml')) {
                return Http::response('<urlset></urlset>');
            }

            return Http::response($this->completeHtml(), 200, ['Content-Type' => 'text/html']);
        });
    }

    private function replaceHttpFake(callable $callback): void
    {
        $factory = new HttpFactory($this->app['events']);
        Http::swap($factory);
        $factory->fake($callback);
    }

    private function completeHtml(): string
    {
        return <<<'HTML'
            <!doctype html>
            <html lang="en">
                <head>
                    <title>Example Page</title>
                    <meta name="description" content="Example description">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <link rel="canonical" href="/page">
                    <script type="application/ld+json">
                        {"@context":"https://schema.org","@type":"Organization","name":"AuditSEO"}
                    </script>
                </head>
                <body>
                    <h1>Main heading</h1>
                    <h2>Secondary heading</h2>
                    <img src="one.jpg" alt="First image">
                    <img src="two.jpg" alt="Second image">
                    <a href="/about">About</a>
                </body>
            </html>
            HTML;
    }

    private function htmlWithLinks(string $links): string
    {
        return <<<HTML
            <!doctype html>
            <html lang="en">
                <head>
                    <title>Link analysis</title>
                    <meta name="description" content="Link analysis checks">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <link rel="canonical" href="/page">
                </head>
                <body>
                    <h1>Main heading</h1>
                    <h2>Secondary heading</h2>
                    {$links}
                </body>
            </html>
            HTML;
    }

    private function contentHtml(string $title, string $description, string $body, string $canonical = '/page'): string
    {
        return <<<HTML
            <!doctype html>
            <html lang="en">
                <head>
                    <title>{$title}</title>
                    <meta name="description" content="{$description}">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <link rel="canonical" href="{$canonical}">
                </head>
                <body>{$body}</body>
            </html>
            HTML;
    }

    private function crawlerPageHtml(?string $title, ?string $description, string $body, ?string $robots = null): string
    {
        $titleTag = $title !== null ? "<title>{$title}</title>" : '';
        $descriptionTag = $description !== null ? "<meta name=\"description\" content=\"{$description}\">" : '';
        $robotsTag = $robots !== null ? "<meta name=\"robots\" content=\"{$robots}\">" : '';

        return <<<HTML
            <!doctype html>
            <html lang="en">
                <head>
                    {$titleTag}
                    {$descriptionTag}
                    {$robotsTag}
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <link rel="canonical" href="/page">
                </head>
                <body>{$body}</body>
            </html>
            HTML;
    }
}
