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

    private int $pageStatus;

    private string $robotsBody;

    private string $sitemapBody;

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->responseHtml = $this->completeHtml();
        $this->robotsStatus = 200;
        $this->sitemapStatus = 200;
        $this->pageStatus = 200;
        $this->robotsBody = 'User-agent: *';
        $this->sitemapBody = '<urlset></urlset>';
        $this->redirects = [];
        $this->linkStatuses = [];
        $this->pageResponses = [
            'https://example.com/about' => [
                'body' => $this->contentHtml(
                    'About AuditSEO Platform Overview',
                    'A helpful about page with a sufficiently descriptive and unique meta description for crawler tests.',
                    '<h1>About AuditSEO Platform</h1><h2>Overview</h2><p>'.str_repeat('about content ', 300).'</p>',
                ),
                'status' => 200,
            ],
        ];

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

            return Http::response($this->responseHtml, $this->pageStatus, ['Content-Type' => 'text/html']);
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
            ->assertJsonPath('audit.global_score', 91)
            ->assertJsonPath('audit.technical_score', 100)
            ->assertJsonPath('audit.content_score', 65)
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
            'global_score' => 91,
            'technical_score' => 100,
            'content_score' => 65,
            'links_score' => 100,
            'performance_score' => 100,
        ]);
        $this->assertSame('Example Page', Audit::findOrFail($response->json('audit.id'))->raw_data['title']);
        Http::assertSentCount(5);
    }

    public function test_robots_txt_and_sitemap_xml_availability_are_stored_in_raw_data(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/private/page']);

        $response
            ->assertCreated()
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

        $this->postJson('/api/audits', ['url' => 'https://example.com/private/allowed/page'])
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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

        $this->postJson('/api/audits', ['url' => 'https://example.com/page'])
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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

        $this->postJson('/api/audits', ['url' => 'https://example.com/page'])
            ->assertCreated()
            ->assertJsonPath('raw_data.sitemap_urls_count', 100)
            ->assertJsonPath('raw_data.sitemap_checked_urls_count', 25);
    }

    public function test_missing_robots_txt_creates_an_audit_issue(): void
    {
        $this->robotsStatus = 404;
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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

        $this->postJson('/api/audits', ['url' => 'https://example.com/page'])
            ->assertCreated()
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

        $this->postJson('/api/audits', ['url' => 'https://example.com/page'])
            ->assertCreated()
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

        $this->postJson('/api/audits', ['url' => 'https://example.com/page'])
            ->assertCreated()
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

        $this->postJson('/api/audits', ['url' => 'https://example.com/page'])
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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

        $this->postJson('/api/audits', ['url' => 'https://example.com/page'])
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
            ->assertJsonPath('raw_data.checked_links_count', 1)
            ->assertJsonPath('raw_data.broken_links_count', 1)
            ->assertJsonPath('raw_data.broken_links_sample.0', 'https://example.com/broken')
            ->assertJsonPath('audit.links_score', 85)
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
            ->assertJsonPath('raw_data.links_count', 32)
            ->assertJsonPath('raw_data.internal_links_count', 32)
            ->assertJsonPath('raw_data.checked_links_count', 25);
        Http::assertSentCount(37);
    }

    public function test_a_page_without_links_creates_an_important_links_issue(): void
    {
        $this->responseHtml = $this->htmlWithLinks('');
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/audits', ['url' => 'https://example.com/page'])
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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
                '<h1>Same Internal Page</h1><h2>Section</h2><p>'.str_repeat('same content ', 300).'</p>',
            ),
            'status' => 200,
        ];
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
            ->assertJsonPath('raw_data.discovered_internal_urls_count', 1)
            ->assertJsonPath('raw_data.crawled_pages_count', 2)
            ->assertJsonPath('raw_data.crawled_pages.1.status_code', 200)
            ->assertJsonPath('raw_data.crawled_pages.1.depth', 1)
            ->assertJsonPath('raw_data.crawled_pages.1.title', 'Same Internal Page Title')
            ->assertJsonPath('raw_data.crawled_pages.1.meta_description', 'A unique meta description for the same internal page used by crawler deduplication tests.')
            ->assertJsonPath('raw_data.crawled_pages.1.h1', 'Same Internal Page')
            ->assertJsonPath('raw_data.crawled_pages.1.is_indexable', true);

        $this->assertGreaterThanOrEqual(300, $response->json('raw_data.crawled_pages.1.word_count'));
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

        $this->postJson('/api/audits', ['url' => 'https://example.com/page'])
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
            ->assertJsonPath('raw_data.duplicate_titles_count', 1)
            ->assertJsonPath('raw_data.duplicate_meta_descriptions_count', 1)
            ->assertJsonPath('raw_data.duplicate_h1_count', 1)
            ->assertJsonFragment(['title' => 'Duplicate page titles found', 'category' => 'content', 'severity' => 'important'])
            ->assertJsonFragment(['title' => 'Duplicate meta descriptions found', 'category' => 'content', 'severity' => 'important'])
            ->assertJsonFragment(['title' => 'Duplicate H1 headings found', 'category' => 'content', 'severity' => 'minor']);
    }

    public function test_missing_title_creates_an_audit_issue(): void
    {
        $this->responseHtml = '<html lang="en"><head><link rel="canonical" href="/"><meta name="viewport" content="width=device-width"><meta name="description" content="Present"></head><body><h1>Heading</h1><h2>Section</h2></body></html>';
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com']);

        $response
            ->assertCreated()
            ->assertJsonPath('audit.content_score', 50)
            ->assertJsonFragment(['title' => 'Missing page title']);
        $this->assertDatabaseHas('audit_issues', ['title' => 'Missing page title']);
    }

    public function test_missing_meta_description_creates_an_audit_issue(): void
    {
        $this->responseHtml = '<html lang="en"><head><link rel="canonical" href="/"><meta name="viewport" content="width=device-width"><title>Present</title></head><body><h1>Heading</h1><h2>Section</h2></body></html>';
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com']);

        $response
            ->assertCreated()
            ->assertJsonPath('audit.content_score', 50)
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
        $this->responseHtml = '<html lang="en"><head><link rel="canonical" href="/page"><meta name="viewport" content="width=device-width"><meta name="description" content="Present"></head><body><h1>Heading</h1><h2>Section</h2></body></html>';
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
            ->assertJsonPath('audit.technical_score', 100)
            ->assertJsonPath('audit.content_score', 50)
            ->assertJsonPath('audit.links_score', 70)
            ->assertJsonPath('audit.performance_score', 100)
            ->assertJsonPath('audit.global_score', 80);
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/technical']);

        $response
            ->assertCreated()
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

    public function test_missing_canonical_viewport_and_html_lang_create_issues(): void
    {
        $this->responseHtml = '<html><head><title>Page</title><meta name="description" content="Description"></head><body><h1>Heading</h1><h2>Section</h2></body></html>';
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
            ->assertJsonPath('raw_data.meta_robots', 'noindex, follow')
            ->assertJsonPath('raw_data.is_indexable', false)
            ->assertJsonFragment(['title' => 'Page is marked noindex', 'category' => 'indexability', 'severity' => 'critical']);
    }

    public function test_non_200_final_response_is_audited_as_a_critical_issue(): void
    {
        $this->pageStatus = 404;
        Sanctum::actingAs(User::factory()->create());

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/page']);

        $response
            ->assertCreated()
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

        $response = $this->postJson('/api/audits', ['url' => 'https://example.com/start']);

        $response
            ->assertCreated()
            ->assertJsonPath('raw_data.final_url', 'https://example.com/page')
            ->assertJsonPath('raw_data.redirect_count', 2)
            ->assertJsonPath('raw_data.canonical_matches_final_url', true)
            ->assertJsonFragment(['title' => 'Page has a redirect chain', 'category' => 'technical', 'severity' => 'minor']);
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
            <html lang="en">
                <head>
                    <title>Example Page</title>
                    <meta name="description" content="Example description">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <link rel="canonical" href="/page">
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

    private function contentHtml(string $title, string $description, string $body): string
    {
        return <<<HTML
            <!doctype html>
            <html lang="en">
                <head>
                    <title>{$title}</title>
                    <meta name="description" content="{$description}">
                    <meta name="viewport" content="width=device-width, initial-scale=1">
                    <link rel="canonical" href="/page">
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
