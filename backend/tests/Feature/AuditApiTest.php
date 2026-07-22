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

    /**
     * @var array<string, int>
     */
    private array $linkStatuses;

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
        $this->redirects = [];
        $this->linkStatuses = [];

        Http::fake(function (Request $request) {
            if (isset($this->redirects[$request->url()])) {
                $redirect = $this->redirects[$request->url()];

                return Http::response('', $redirect['status'], ['Location' => $redirect['location']]);
            }

            if (str_ends_with($request->url(), '/robots.txt')) {
                return Http::response('User-agent: *', $this->robotsStatus);
            }

            if (str_ends_with($request->url(), '/sitemap.xml')) {
                return Http::response('<urlset></urlset>', $this->sitemapStatus);
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
        Http::assertSentCount(4);
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
        Http::assertSentCount(28);
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
}
