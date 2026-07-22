<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAuditRequest;
use App\Models\Audit;
use App\Models\Domain;
use App\Services\Seo\SeoCrawlerService;
use App\Services\Seo\SeoScoringService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AuditController extends Controller
{
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

        try {
            $rawData = $crawler->crawl($url);
        } catch (ConnectionException|RuntimeException $exception) {
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
