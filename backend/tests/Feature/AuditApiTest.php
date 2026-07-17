<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditApiTest extends TestCase
{
    use RefreshDatabase;

    private string $responseHtml;

    private int $robotsStatus;

    private int $sitemapStatus;

    protected function setUp(): void
    {
        parent::setUp();

        $this->responseHtml = $this->completeHtml();
        $this->robotsStatus = 200;
        $this->sitemapStatus = 200;

        Http::fake(function (Request $request) {
            if (str_ends_with($request->url(), '/robots.txt')) {
                return Http::response('User-agent: *', $this->robotsStatus);
            }

            if (str_ends_with($request->url(), '/sitemap.xml')) {
                return Http::response('<urlset></urlset>', $this->sitemapStatus);
            }

            return Http::response($this->responseHtml, 200, ['Content-Type' => 'text/html']);
        });
    }

    public function test_unauthenticated_users_cannot_access_audit_routes(): void
    {
        $this->postJson('/api/audits', ['url' => 'https://example.com'])->assertUnauthorized();
        $this->getJson('/api/audits')->assertUnauthorized();
        $this->getJson('/api/audits/1')->assertUnauthorized();
    }

    public function test_an_authenticated_user_can_create_an_audit(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/audits', [
            'url' => 'https://example.com/page',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('audit.domain.user_id', $user->id)
            ->assertJsonPath('audit.domain.domain_name', 'example.com')
            ->assertJsonPath('audit.global_score', 100)
            ->assertJsonPath('audit.technical_score', 100)
            ->assertJsonPath('audit.content_score', 100)
            ->assertJsonPath('audit.links_score', 100)
            ->assertJsonPath('audit.performance_score', 100)
            ->assertJsonPath('raw_data.title', 'Example Page')
            ->assertJsonPath('raw_data.meta_description', 'Example description')
            ->assertJsonPath('raw_data.robots_txt_found', true)
            ->assertJsonPath('raw_data.sitemap_xml_found', true);

        $this->assertDatabaseHas('domains', [
            'user_id' => $user->id,
            'domain_name' => 'example.com',
        ]);
        $this->assertDatabaseHas('audits', [
            'domain_id' => $response->json('audit.domain_id'),
            'global_score' => 100,
            'technical_score' => 100,
            'content_score' => 100,
            'links_score' => 100,
            'performance_score' => 100,
        ]);
        $this->assertSame('Example Page', Audit::findOrFail($response->json('audit.id'))->raw_data['title']);
        Http::assertSentCount(3);
    }

    public function test_robots_txt_and_sitemap_xml_availability_are_stored_in_raw_data(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
            ->assertJsonPath('raw_data.robots_txt_found', true)
            ->assertJsonPath('raw_data.sitemap_xml_found', true);

        $audit = Audit::findOrFail($response->json('audit.id'));
        $this->assertTrue($audit->raw_data['robots_txt_found']);
        $this->assertTrue($audit->raw_data['sitemap_xml_found']);
        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.com/robots.txt');
        Http::assertSent(fn (Request $request) => $request->url() === 'https://example.com/sitemap.xml');
    }

    public function test_missing_robots_txt_creates_an_audit_issue(): void
    {
        $this->robotsStatus = 404;
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
            ->assertJsonPath('raw_data.robots_txt_found', false)
            ->assertJsonPath('audit.technical_score', 80)
            ->assertJsonFragment(['title' => 'Missing robots.txt']);
        $this->assertDatabaseHas('audit_issues', ['title' => 'Missing robots.txt']);
    }

    public function test_missing_sitemap_xml_creates_an_audit_issue(): void
    {
        $this->sitemapStatus = 404;
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
            ->assertJsonPath('raw_data.sitemap_xml_found', false)
            ->assertJsonPath('audit.technical_score', 80)
            ->assertJsonFragment(['title' => 'Missing sitemap.xml']);
        $this->assertDatabaseHas('audit_issues', ['title' => 'Missing sitemap.xml']);
    }

    public function test_html_title_and_meta_description_are_extracted(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com']);

        $response
            ->assertCreated()
            ->assertJsonPath('raw_data.title', 'Example Page')
            ->assertJsonPath('raw_data.meta_description', 'Example description')
            ->assertJsonPath('raw_data.h1_count', 1)
            ->assertJsonPath('raw_data.h2_count', 1)
            ->assertJsonPath('raw_data.images_count', 2)
            ->assertJsonPath('raw_data.images_missing_alt_count', 0)
            ->assertJsonPath('raw_data.links_count', 1)
            ->assertJsonPath('raw_data.uses_https', true);
    }

    public function test_missing_title_creates_an_audit_issue(): void
    {
        $this->responseHtml = '<html><head><meta name="description" content="Present"></head><body><h1>Heading</h1></body></html>';
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com']);

        $response
            ->assertCreated()
            ->assertJsonPath('audit.content_score', 75)
            ->assertJsonFragment(['title' => 'Missing page title']);
        $this->assertDatabaseHas('audit_issues', ['title' => 'Missing page title']);
    }

    public function test_missing_meta_description_creates_an_audit_issue(): void
    {
        $this->responseHtml = '<html><head><title>Present</title></head><body><h1>Heading</h1></body></html>';
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com']);

        $response
            ->assertCreated()
            ->assertJsonPath('audit.content_score', 80)
            ->assertJsonFragment(['title' => 'Missing meta description']);
        $this->assertDatabaseHas('audit_issues', ['title' => 'Missing meta description']);
    }

    public function test_non_https_url_lowers_the_technical_score(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/audits', ['url' => 'http://example.com/page']);

        $response
            ->assertCreated()
            ->assertJsonPath('audit.technical_score', 60)
            ->assertJsonFragment(['title' => 'Page does not use HTTPS']);
    }

    public function test_global_score_is_the_rounded_average_of_category_scores(): void
    {
        $this->responseHtml = '<html><head><meta name="description" content="Present"></head><body><h1>Heading</h1></body></html>';
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
            ->assertJsonPath('audit.technical_score', 100)
            ->assertJsonPath('audit.content_score', 75)
            ->assertJsonPath('audit.links_score', 70)
            ->assertJsonPath('audit.performance_score', 100)
            ->assertJsonPath('audit.global_score', 86);
    }

    public function test_all_calculated_scores_remain_between_zero_and_one_hundred(): void
    {
        $this->responseHtml = '<html><body>'.str_repeat('<img src="image.jpg">', 30).'</body></html>';
        $this->robotsStatus = 404;
        $this->sitemapStatus = 404;
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/audits', ['url' => 'http://example.com/page']);
        $response->assertCreated();

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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com']);

        $response
            ->assertCreated()
            ->assertJsonPath('raw_data.images_missing_alt_count', 2)
            ->assertJsonFragment(['title' => 'Images missing alt text']);
        $this->assertDatabaseHas('audit_issues', [
            'title' => 'Images missing alt text',
            'description' => '2 image(s) are missing alt text.',
        ]);
    }

    public function test_unsafe_urls_are_rejected_without_making_http_requests(): void
    {
        Sanctum::actingAs(User::factory()->create());

        foreach (['http://localhost', 'http://127.0.0.1'] as $url) {
            $this->postJson('/api/audits', ['url' => $url])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('url');
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('audits', 0);
    }

    public function test_creating_an_audit_reuses_the_users_existing_domain(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/audits', ['url' => 'https://example.com/first'])->assertCreated();
        $this->postJson('/api/audits', ['url' => 'https://example.com/second'])->assertCreated();

        $this->assertDatabaseCount('domains', 1);
        $this->assertDatabaseCount('audits', 2);
    }

    public function test_an_authenticated_user_can_list_only_their_audits(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownAudit = $this->createAuditFor($user, 'own.example.com');
        $otherAudit = $this->createAuditFor($otherUser, 'other.example.com');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/audits');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'audits')
            ->assertJsonPath('audits.0.id', $ownAudit->id)
            ->assertJsonMissing(['id' => $otherAudit->id]);
    }

    public function test_an_authenticated_user_can_view_their_own_audit(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user, 'own.example.com');
        Sanctum::actingAs($user);

        $this->getJson("/api/audits/{$audit->id}")
            ->assertOk()
            ->assertJsonPath('audit.id', $audit->id)
            ->assertJsonPath('audit.domain.user_id', $user->id);
    }

    public function test_an_authenticated_user_cannot_view_another_users_audit(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherAudit = $this->createAuditFor($otherUser, 'other.example.com');
        Sanctum::actingAs($user);

        $this->getJson("/api/audits/{$otherAudit->id}")->assertNotFound();
    }

    private function createAuditFor(User $user, string $domainName): Audit
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
        ]);
    }

    private function completeHtml(): string
    {
        return <<<'HTML'
            <!doctype html>
            <html>
                <head>
                    <title>Example Page</title>
                    <meta name="description" content="Example description">
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
}
