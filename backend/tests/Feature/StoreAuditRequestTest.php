<?php

namespace Tests\Feature;

use App\Http\Requests\StoreAuditRequest;
use App\Models\User;
use App\Security\DnsResolver;
use App\Security\PublicUrlPolicy;
use App\Services\Seo\SeoCrawlerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_a_public_https_url_passes_request_validation(): void
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
}
