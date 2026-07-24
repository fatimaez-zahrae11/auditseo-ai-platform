<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAuditRequest;
use App\Models\Audit;
use App\Models\Domain;
use App\Services\Seo\SeoCrawlerService;
use App\Services\Seo\SeoScoringService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class AuditController extends Controller
{
    private const AUDIT_EXECUTION_TIME_LIMIT_SECONDS = 180;

    public function store(
        StoreAuditRequest $request,
        SeoCrawlerService $crawler,
        SeoScoringService $scoring,
    ): JsonResponse {
        $url = $request->validated('url');
        $domainName = strtolower((string) parse_url($url, PHP_URL_HOST));

        $domain = Domain::firstOrCreate(
            [
                'user_id' => $request->user()->id,
                'domain_name' => $domainName,
            ],
            ['url' => $url],
        );

        $this->extendExecutionTimeForAudit();

        try {
            $rawData = $crawler->crawl($url);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'Unable to fetch the requested URL.',
            ], 502);
        }

        $scores = $scoring->calculate($rawData);

        $audit = $domain->audits()->create([
            ...$scores,
            'raw_data' => $rawData,
        ]);

        $this->createIssues($audit, $rawData);
        $audit->load(['domain', 'issues']);

        return response()->json([
            'message' => 'Audit created successfully.',
            'audit' => $audit,
            'domain' => $audit->domain,
            'issues' => $audit->issues,
            'raw_data' => $audit->raw_data,
        ], 201);
    }

    private function extendExecutionTimeForAudit(): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(self::AUDIT_EXECUTION_TIME_LIMIT_SECONDS);
        }

        if (function_exists('ini_set')) {
            @ini_set('max_execution_time', (string) self::AUDIT_EXECUTION_TIME_LIMIT_SECONDS);
        }
    }

    // index() & show() ces 2 fcts gardent la protection IDOR:
    // retourner seulement les audits de l’utilisateur connecte
    public function index(Request $request): JsonResponse
    {
        $audits = Audit::query()
            ->with('domain')
            ->whereHas('domain', fn ($query) => $query->where('user_id', $request->user()->id))
            ->latest()
            ->get();

        return response()->json([
            'audits' => $audits,
        ]);
    }

    // meme logique ; donc si user A essaie de voir laudit de l'utilisateur B ? laravel return 404 error C la protection IDOR
    public function show(Request $request, int $id): JsonResponse
    {
        $audit = Audit::query()
            ->with(['domain', 'issues'])
            ->whereHas('domain', fn ($query) => $query->where('user_id', $request->user()->id))
            ->findOrFail($id);

        return response()->json([
            'audit' => $audit,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    // cette fonction transforme les donnees extraite ne problemes SEO
    private function createIssues(Audit $audit, array $data): void
    {
        $issues = [];

        if ($data['title'] === null) {
            $issues[] = $this->issue('content', 'Missing page title', 'important', 'Add a descriptive title element.');
        } elseif ($data['title_length'] < 30) {
            $issues[] = $this->issue(
                'content',
                'Page title is too short',
                'minor',
                'Expand the page title to between 30 and 60 characters.',
                "The page title is {$data['title_length']} characters long.",
            );
        } elseif ($data['title_length'] > 60) {
            $issues[] = $this->issue(
                'content',
                'Page title is too long',
                'minor',
                'Shorten the page title to between 30 and 60 characters.',
                "The page title is {$data['title_length']} characters long.",
            );
        }

        if ($data['meta_description'] === null) {
            $issues[] = $this->issue('content', 'Missing meta description', 'important', 'Add a concise meta description.');
        } elseif ($data['meta_description_length'] < 70) {
            $issues[] = $this->issue(
                'content',
                'Meta description is too short',
                'minor',
                'Expand the meta description to between 70 and 160 characters.',
                "The meta description is {$data['meta_description_length']} characters long.",
            );
        } elseif ($data['meta_description_length'] > 160) {
            $issues[] = $this->issue(
                'content',
                'Meta description is too long',
                'minor',
                'Shorten the meta description to between 70 and 160 characters.',
                "The meta description is {$data['meta_description_length']} characters long.",
            );
        }

        if ($data['word_count'] < 300) {
            $issues[] = $this->issue(
                'content',
                'Low word count',
                'important',
                'Add useful, original content that fully addresses the page topic.',
                "The page contains {$data['word_count']} visible words; at least 300 are recommended.",
            );
        }

        if ($data['h1_count'] === 0) {
            $issues[] = $this->issue('content', 'Missing H1 heading', 'important', 'Add one clear H1 heading.');
        } elseif ($data['h1_count'] > 1) {
            $issues[] = $this->issue('content', 'Multiple H1 headings', 'minor', 'Use one primary H1 heading.');
        }

        if ($data['h2_count'] === 0) {
            $issues[] = $this->issue('content', 'Missing H2 heading', 'minor', 'Add descriptive H2 headings to structure the page content.');
        }

        if ($data['title'] !== null && $data['h1_count'] > 0 && ! $data['title_matches_h1']) {
            $issues[] = $this->issue(
                'content',
                'Page title does not align with H1',
                'minor',
                'Align the page title and primary H1 around the same topic and search intent.',
            );
        }

        if ($this->headingStructureSkipsLevels($data['heading_structure'])) {
            $issues[] = $this->issue(
                'content',
                'Heading structure skips levels',
                'minor',
                'Use heading levels in a logical sequence without skipping intermediate levels.',
            );
        }

        if ($data['images_missing_alt_count'] > 0) {
            $issues[] = $this->issue(
                'content',
                'Images missing alt text',
                'important',
                'Add meaningful alt text to informative images.',
                "{$data['images_missing_alt_count']} image(s) are missing alt text.",
            );
        }

        if ($data['images_alt_missing_ratio'] > 0.3) {
            $missingPercentage = (int) round($data['images_alt_missing_ratio'] * 100);
            $issues[] = $this->issue(
                'accessibility',
                'High image alt text missing ratio',
                'important',
                'Add meaningful alt text to informative images and empty alt attributes to decorative images.',
                "{$missingPercentage}% of images are missing alt text.",
            );
        }

        if (! ($data['structured_data_found'] ?? false)) {
            $issues[] = $this->issue(
                'structured_data',
                'No structured data found',
                'minor',
                'Add relevant Schema.org structured data using valid JSON-LD where appropriate.',
            );
        }

        if (($data['structured_data_errors_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'structured_data',
                'Invalid JSON-LD found',
                'important',
                'Correct invalid JSON-LD syntax and ensure each block contains an object or array.',
                "{$data['structured_data_errors_count']} invalid JSON-LD block(s) were found.",
            );
        }

        $missingSchemaTypes = $data['recommended_schema_types_missing'] ?? [];
        if ($missingSchemaTypes !== []) {
            $issues[] = $this->issue(
                'structured_data',
                'Recommended schema types are missing',
                'minor',
                'Add the recommended Schema.org types when they accurately describe the page.',
                'Missing recommended types: '.implode(', ', $missingSchemaTypes).'.',
            );
        }

        if (in_array('BreadcrumbList', $missingSchemaTypes, true)) {
            $issues[] = $this->issue(
                'structured_data',
                'Breadcrumb navigation lacks BreadcrumbList schema',
                'minor',
                'Describe the visible breadcrumb trail with BreadcrumbList structured data.',
            );
        }

        if (! $data['uses_https']) {
            $issues[] = $this->issue('technical', 'Page does not use HTTPS', 'critical', 'Serve the page over HTTPS.');
        }

        if (! $data['robots_txt_found']) {
            $issues[] = $this->issue(
                'technical',
                'Missing robots.txt',
                'important',
                'Add a robots.txt file at the root of the website.',
            );
        }

        if (! $data['sitemap_xml_found']) {
            $issues[] = $this->issue(
                'technical',
                'Missing sitemap.xml',
                'important',
                'Add a sitemap.xml file at the root of the website.',
            );
        }

        if (! ($data['robots_txt_allows_audited_url'] ?? true)) {
            $issues[] = $this->issue(
                'indexability',
                'Audited URL is blocked by robots.txt',
                'critical',
                'Update the User-agent: * rules if this URL should be available to search engines.',
            );
        }

        if (($data['sitemap_xml_found'] ?? false) && ! ($data['sitemap_xml_is_valid'] ?? false)) {
            $issues[] = $this->issue(
                'technical',
                'Sitemap XML is invalid',
                'important',
                'Fix the sitemap XML structure so search engines can parse it.',
            );
        }

        if (($data['sitemap_xml_is_valid'] ?? false) && ! ($data['sitemap_contains_audited_url'] ?? false)) {
            $issues[] = $this->issue(
                'indexability',
                'Audited URL is missing from sitemap',
                'minor',
                'Add the canonical audited URL to the XML sitemap if it should be indexed.',
            );
        }

        if (($data['sitemap_non_https_urls_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'technical',
                'Sitemap contains non-HTTPS URLs',
                'important',
                'Replace non-HTTPS sitemap entries with their canonical HTTPS URLs.',
                "{$data['sitemap_non_https_urls_count']} sitemap URL(s) do not use HTTPS.",
            );
        }

        if (($data['sitemap_broken_urls_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'technical',
                'Sitemap contains broken URLs',
                'important',
                'Remove or correct sitemap URLs that return HTTP errors.',
                "{$data['sitemap_broken_urls_count']} checked sitemap URL(s) are broken.",
            );
        }

        if (($data['pages_with_http_errors_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'technical',
                'Crawled pages return HTTP errors',
                'important',
                'Fix internal pages that return HTTP error status codes.',
                "{$data['pages_with_http_errors_count']} crawled internal page(s) return HTTP errors.",
            );
        }

        if (($data['pages_with_missing_title_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'content',
                'Crawled pages are missing titles',
                'important',
                'Add unique descriptive title elements to every important internal page.',
                "{$data['pages_with_missing_title_count']} crawled internal page(s) are missing title elements.",
            );
        }

        if (($data['pages_with_missing_meta_description_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'content',
                'Crawled pages are missing meta descriptions',
                'important',
                'Add concise meta descriptions to every important internal page.',
                "{$data['pages_with_missing_meta_description_count']} crawled internal page(s) are missing meta descriptions.",
            );
        }

        if (($data['pages_with_missing_h1_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'content',
                'Crawled pages are missing H1 headings',
                'important',
                'Add one clear H1 heading to every important internal page.',
                "{$data['pages_with_missing_h1_count']} crawled internal page(s) are missing H1 headings.",
            );
        }

        if (($data['pages_with_noindex_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'indexability',
                'Crawled pages are marked noindex',
                'important',
                'Remove noindex directives from internal pages that should appear in search results.',
                "{$data['pages_with_noindex_count']} crawled internal page(s) are marked noindex.",
            );
        }

        if (($data['pages_with_low_word_count_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'content',
                'Crawled pages have low word count',
                'important',
                'Expand thin internal pages with useful, original content.',
                "{$data['pages_with_low_word_count_count']} crawled internal page(s) have fewer than 300 visible words.",
            );
        }

        if (($data['duplicate_title_groups'] ?? []) !== [] || ($data['duplicate_titles_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'content',
                'Duplicate page titles found',
                'important',
                'Give each crawled page a unique title aligned with its search intent.',
                "{$data['duplicate_titles_count']} duplicate page title occurrence(s) were found.",
            );
        }

        if (($data['duplicate_meta_description_groups'] ?? []) !== [] || ($data['duplicate_meta_descriptions_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'content',
                'Duplicate meta descriptions found',
                'important',
                'Write unique meta descriptions for each crawled page.',
                "{$data['duplicate_meta_descriptions_count']} duplicate meta description occurrence(s) were found.",
            );
        }

        if (($data['duplicate_h1_groups'] ?? []) !== [] || ($data['duplicate_h1_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'content',
                'Duplicate H1 headings found',
                'minor',
                'Use unique H1 headings that clearly describe each crawled page.',
                "{$data['duplicate_h1_count']} duplicate H1 occurrence(s) were found.",
            );
        }

        if (($data['duplicate_content_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'content',
                'Duplicate page content found',
                'important',
                'Consolidate duplicate pages or make each page substantially unique and useful.',
                "{$data['duplicate_content_count']} duplicate content occurrence(s) were found.",
            );
        }

        if (($data['thin_content_pages_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'content',
                'Thin content pages found',
                'important',
                'Expand thin pages with useful, original information or consolidate pages that do not warrant separate URLs.',
                "{$data['thin_content_pages_count']} crawled page(s) contain fewer than 300 visible words.",
            );
        }

        if (($data['canonical_conflicts_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'indexability',
                'Canonical conflicts found',
                'important',
                'Use consistent self-referencing canonicals unless a page intentionally consolidates into another canonical URL.',
                "{$data['canonical_conflicts_count']} crawled page(s) have conflicting canonical signals.",
            );
        }

        if (($data['sitemap_orphan_urls_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'indexability',
                'Sitemap orphan URLs found',
                'minor',
                'Add relevant internal links to sitemap URLs that should be discoverable, or remove obsolete entries.',
                "{$data['sitemap_orphan_urls_count']} sitemap URL(s) were not discovered by the internal crawl.",
            );
        }

        if ($data['http_status_code'] !== 200) {
            $issues[] = $this->issue(
                'technical',
                'Page does not return HTTP 200',
                'critical',
                'Make the final page return an HTTP 200 status code.',
                "The final response returned HTTP {$data['http_status_code']}.",
            );
        }

        if ($data['redirect_count'] > 1) {
            $issues[] = $this->issue(
                'technical',
                'Page has a redirect chain',
                'minor',
                'Link directly to the final URL and reduce the redirect chain to one hop or fewer.',
                "The page redirected {$data['redirect_count']} times.",
            );
        }

        if (($data['response_time_ms'] ?? 0) > 5000) {
            $issues[] = $this->issue(
                'performance',
                'Page response is very slow',
                'critical',
                'Reduce server response time by profiling backend work, database queries, and upstream dependencies.',
                "The audited page responded in {$data['response_time_ms']} ms.",
            );
        } elseif (($data['response_time_ms'] ?? 0) > 2000) {
            $issues[] = $this->issue(
                'performance',
                'Page response is slow',
                'important',
                'Improve server response time with backend optimization, caching, and faster infrastructure where appropriate.',
                "The audited page responded in {$data['response_time_ms']} ms.",
            );
        }

        if (($data['page_size_bytes'] ?? 0) > 3_000_000) {
            $issues[] = $this->issue(
                'performance',
                'HTML page payload is very large',
                'critical',
                'Substantially reduce the HTML payload by removing unnecessary markup and embedded content.',
                "The HTML response is {$data['page_size_bytes']} bytes.",
            );
        } elseif (($data['page_size_bytes'] ?? 0) > 1_000_000) {
            $issues[] = $this->issue(
                'performance',
                'HTML page payload is large',
                'important',
                'Reduce unnecessary markup and embedded content in the HTML response.',
                "The HTML response is {$data['page_size_bytes']} bytes.",
            );
        }

        if (($data['is_html_response'] ?? false) && ! ($data['compression_enabled'] ?? false)) {
            $issues[] = $this->issue(
                'performance',
                'HTML response compression is missing',
                'important',
                'Enable Brotli or gzip compression for HTML responses.',
            );
        }

        if (array_key_exists('cache_headers_present', $data) && ! $data['cache_headers_present']) {
            $issues[] = $this->issue(
                'performance',
                'Cache headers are missing',
                'minor',
                'Send an appropriate Cache-Control, Expires, or ETag header for the audited page.',
            );
        }

        if (array_key_exists('is_html_response', $data) && ! $data['is_html_response']) {
            $contentType = $data['content_type'] ?? 'not provided';
            $issues[] = $this->issue(
                'technical',
                'Audited page is not an HTML response',
                'important',
                'Serve the audited page with an HTML Content-Type such as text/html.',
                "The response Content-Type is {$contentType}.",
            );
        }

        if ($data['canonical_url'] === null) {
            $issues[] = $this->issue('indexability', 'Missing canonical tag', 'important', 'Add a self-referencing canonical link to the page head.');
        } elseif (! $data['canonical_matches_final_url']) {
            $issues[] = $this->issue('indexability', 'Canonical URL does not match final URL', 'important', 'Use the preferred final URL in the canonical link.');
        }

        $metaRobots = strtolower((string) ($data['meta_robots'] ?? ''));
        if (preg_match('/(?:^|[\s,])noindex(?:[\s,]|$)/', $metaRobots)) {
            $issues[] = $this->issue('indexability', 'Page is marked noindex', 'critical', 'Remove the noindex directive if this page should appear in search results.');
        }
        if (preg_match('/(?:^|[\s,])nofollow(?:[\s,]|$)/', $metaRobots)) {
            $issues[] = $this->issue('indexability', 'Page is marked nofollow', 'important', 'Remove the nofollow directive if search engines should follow links on this page.');
        }

        if ($data['html_lang'] === null) {
            $issues[] = $this->issue('accessibility', 'Missing HTML lang attribute', 'minor', 'Set the page language on the html element.');
        }

        if (! $data['viewport_found']) {
            $issues[] = $this->issue('technical', 'Missing meta viewport', 'important', 'Add a responsive meta viewport tag to the page head.');
        }

        if (($data['broken_links_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'links',
                'Broken links found',
                'important',
                'Update or remove links that return an error.',
                "{$data['broken_links_count']} checked link(s) are broken.",
            );
        }

        if (($data['empty_anchor_links_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'links',
                'Links with empty anchor text',
                'minor',
                'Add descriptive anchor text to every link.',
                "{$data['empty_anchor_links_count']} link(s) have empty anchor text.",
            );
        }

        if (($data['generic_anchor_links_count'] ?? 0) > 0) {
            $issues[] = $this->issue(
                'links',
                'Links with generic anchor text',
                'minor',
                'Replace generic phrases with descriptive anchor text.',
                "{$data['generic_anchor_links_count']} link(s) have generic anchor text.",
            );
        }

        if ($data['links_count'] === 0) {
            $issues[] = $this->issue(
                'links',
                'No links found',
                'important',
                'Add relevant internal links to help users and search engines navigate the site.',
            );
        }

        if ($issues !== []) {
            $audit->issues()->createMany($issues);
        }
    }

    /**
     * @return array<string, string|null>
     */
    private function issue(
        string $category,
        string $title,
        string $severity,
        string $recommendation,
        ?string $description = null,
    ): array {
        return compact('category', 'title', 'severity', 'description', 'recommendation');
    }

    /**
     * @param  array<int, array{tag: string, text: string}>  $headings
     */
    private function headingStructureSkipsLevels(array $headings): bool
    {
        $previousLevel = null;

        foreach ($headings as $heading) {
            $level = (int) substr($heading['tag'], 1);
            if ($previousLevel !== null && $level > $previousLevel + 1) {
                return true;
            }

            $previousLevel = $level;
        }

        return false;
    }
}
