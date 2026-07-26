<?php

namespace Tests\Feature;

use App\Http\Requests\StoreAuditRequest;
use App\Models\User;
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
            'IPv6 loopback' => ['http://[::1]/'],
            'private IPv6' => ['https://[fc00::1]/'],
            'non-standard port' => ['https://example.com:8443/'],
            'file scheme' => ['file:///etc/passwd'],
            'FTP scheme' => ['ftp://example.com/file'],
        ];
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
}
