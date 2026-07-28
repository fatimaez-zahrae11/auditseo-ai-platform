<?php

namespace Tests\Feature;

use App\Http\Requests\StoreAuditRequest;
use App\Models\User;
use App\Security\CurlTransportCapabilities;
use App\Security\DnsResolver;
use App\Security\PublicUrlPolicy;
use App\Services\Seo\BoundedResponseStream;
use App\Services\Seo\PinnedCurlHandler;
use App\Services\Seo\SeoCrawlerService;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Monolog\Handler\TestHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

class StoreAuditRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $resolver = Mockery::mock(DnsResolver::class);
        $resolver->shouldReceive('resolve')
            ->byDefault()
            ->andReturn(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']);
        $this->app->instance(DnsResolver::class, $resolver);
    }

    #[DataProvider('unsafeUrlProvider')]
    public function test_unsafe_urls_are_rejected_before_the_crawler_runs(string $url): void
    {
        $crawler = Mockery::mock(SeoCrawlerService::class);
        $crawler->shouldNotReceive('crawl');
        $this->app->instance(SeoCrawlerService::class, $crawler);
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/audits', ['url' => $url])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');

        $this->assertDatabaseCount('domains', 0);
        $this->assertDatabaseCount('audits', 0);
    }

    #[DataProvider('urlUserInformationProvider')]
    public function test_url_user_information_is_rejected_without_persistence_or_logging(
        string $url,
        string $username,
        ?string $password,
    ): void {
        $crawler = Mockery::mock(SeoCrawlerService::class);
        $crawler->shouldNotReceive('crawl');
        $this->app->instance(SeoCrawlerService::class, $crawler);
        Sanctum::actingAs(User::factory()->create());

        $logHandler = new TestHandler;
        $logger = Log::channel()->getLogger();
        $logger->pushHandler($logHandler);

        try {
            $response = $this->postJson('/api/audits', ['url' => $url]);
        } finally {
            $logger->popHandler();
        }

        $response
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'The given data was invalid.',
                'errors' => [
                    'url' => [PublicUrlPolicy::VALIDATION_MESSAGE],
                ],
            ]);
        $this->assertStringNotContainsString($username, $response->getContent());
        if ($password !== null) {
            $this->assertStringNotContainsString($password, $response->getContent());
        }

        $loggedData = array_map(
            fn ($record): array => [
                'message' => $record->message,
                'context' => $record->context,
                'extra' => $record->extra,
            ],
            $logHandler->getRecords(),
        );
        $serializedLogs = json_encode($loggedData, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($username, $serializedLogs);
        if ($password !== null) {
            $this->assertStringNotContainsString($password, $serializedLogs);
        }

        $this->assertDatabaseCount('domains', 0);
        $this->assertDatabaseCount('audits', 0);
    }

    /**
     * @return array<string, array{string, string, string|null}>
     */
    public static function urlUserInformationProvider(): array
    {
        return [
            'username only' => [
                'https://sec11-user@example.com/audit',
                'sec11-user',
                null,
            ],
            'username and password' => [
                'https://sec11-user:SEC11-secret@example.com/audit',
                'sec11-user',
                'SEC11-secret',
            ],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeUrlProvider(): array
    {
        return [
            'localhost' => ['http://localhost/'],
            'IPv4 loopback' => ['http://127.0.0.1:5432/'],
            'private IPv4' => ['http://10.0.0.5/admin'],
            'link-local metadata service' => ['http://169.254.169.254/latest/meta-data/'],
            'shared address space' => ['http://100.64.0.1/'],
            'benchmark network' => ['http://198.18.0.1/'],
            'IPv6 loopback' => ['http://[::1]/'],
            'private IPv6' => ['https://[fc00::1]/'],
            'link-local IPv6' => ['http://[fe80::1]/'],
            'non-standard port' => ['https://example.com:8443/'],
            'URL credentials' => ['https://user:password@example.com/'],
            'file scheme' => ['file:///etc/passwd'],
            'FTP scheme' => ['ftp://example.com/file'],
        ];
    }

    public function test_a_hostname_without_a_or_aaaa_records_is_rejected(): void
    {
        $resolver = Mockery::mock(DnsResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->with('unresolved.example')
            ->andReturn([]);
        $this->app->instance(DnsResolver::class, $resolver);

        $request = new StoreAuditRequest;
        $validator = Validator::make(
            ['url' => 'https://unresolved.example/'],
            $request->rules(),
            $request->messages(),
        );

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('url', $validator->errors()->toArray());
    }

    public function test_a_hostname_is_rejected_if_any_resolved_address_is_not_public(): void
    {
        $resolver = Mockery::mock(DnsResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->with('mixed.example')
            ->andReturn(['93.184.216.34', 'fe80::1']);
        $this->app->instance(DnsResolver::class, $resolver);

        $request = new StoreAuditRequest;
        $validator = Validator::make(
            ['url' => 'https://mixed.example/'],
            $request->rules(),
            $request->messages(),
        );

        $this->assertFalse($validator->passes());
        $this->assertArrayHasKey('url', $validator->errors()->toArray());
    }

    public function test_a_public_https_url_without_credentials_passes_request_validation(): void
    {
        $request = new StoreAuditRequest;
        $validator = Validator::make(
            ['url' => 'https://example.com/'],
            $request->rules(),
            $request->messages(),
        );

        $this->assertTrue($validator->passes(), $validator->errors()->toJson());
    }

    public function test_a_validated_hostname_is_pinned_to_the_checked_dns_answer(): void
    {
        if (! defined('CURLOPT_RESOLVE')) {
            $this->markTestSkipped('The cURL extension is required for DNS pinning.');
        }

        $policy = app(PublicUrlPolicy::class);
        $target = $policy->validate('https://example.com/');
        $options = $policy->connectionOptions($target);

        $this->assertSame(
            ['example.com:443:93.184.216.34'],
            $options['curl'][constant('CURLOPT_RESOLVE')],
        );
    }

    public function test_crawler_requests_use_the_pinned_curl_handler_without_stream_mode(): void
    {
        if (! defined('CURLOPT_RESOLVE')) {
            $this->markTestSkipped('The cURL extension is required for DNS pinning.');
        }

        $policy = app(PublicUrlPolicy::class);
        $target = $policy->validate('https://example.com/');
        $crawler = app(SeoCrawlerService::class);
        $crawlerReflection = new ReflectionClass($crawler);
        $httpClient = $crawlerReflection->getMethod('httpClient')->invoke(
            $crawler,
            $target,
            5_000_000,
        );

        $this->assertInstanceOf(PendingRequest::class, $httpClient);
        $this->assertArrayNotHasKey('stream', $httpClient->getOptions());
        $this->assertSame(
            ['example.com:443:93.184.216.34'],
            $httpClient->getOptions()['curl'][constant('CURLOPT_RESOLVE')],
        );

        $pendingRequestReflection = new ReflectionClass($httpClient);
        $handler = $pendingRequestReflection->getProperty('handler')->getValue($httpClient);

        $this->assertInstanceOf(PinnedCurlHandler::class, $handler);

        $pinnedHandlerReflection = new ReflectionClass($handler);
        $this->assertInstanceOf(
            CurlHandler::class,
            $pinnedHandlerReflection->getProperty('handler')->getValue($handler),
        );
    }

    public function test_dns_pinning_unavailability_fails_closed(): void
    {
        $resolver = Mockery::mock(DnsResolver::class);
        $resolver->shouldReceive('resolve')
            ->once()
            ->with('example.com')
            ->andReturn(['93.184.216.34']);
        $policy = new PublicUrlPolicy(
            $resolver,
            new CurlTransportCapabilities(
                curlAvailable: true,
                dnsPinningAvailable: false,
            ),
        );
        $target = $policy->validate('https://example.com/');

        $this->expectException(RuntimeException::class);
        $policy->connectionOptions($target);
    }

    public function test_bounded_curl_sink_rejects_bytes_beyond_its_limit(): void
    {
        $stream = new BoundedResponseStream(5);

        $this->assertSame(5, $stream->write('12345'));

        try {
            $stream->write('6');
            $this->fail('The bounded response stream accepted an oversized write.');
        } catch (RuntimeException) {
            $this->assertSame(5, $stream->getSize());
        } finally {
            $stream->close();
        }
    }
}
