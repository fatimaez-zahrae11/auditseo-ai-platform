<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAuditRequest;
use App\Models\Audit;
use App\Models\Domain;
use App\Services\Seo\SeoCrawlerService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AuditController extends Controller
{
    public function store(StoreAuditRequest $request, SeoCrawlerService $crawler): JsonResponse
    {
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

        $audit = $domain->audits()->create([
            'global_score' => 0,
            'technical_score' => 0,
            'content_score' => 0,
            'links_score' => 0,
            'performance_score' => 0,
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

    # index() & show() ces 2 fcts gardent la protection IDOR:
    # retourner seulement les audits de l’utilisateur connecte
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

    # meme logique ; donc si user A essaie de voir laudit de l'utilisateur B ? laravel return 404 error C la protection IDOR 
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
     * @param array<string, bool|int|string|null> $data
     */
    # cette fonction transforme les donnees extraite ne problemes SEO 
    private function createIssues(Audit $audit, array $data): void
    {
        $issues = [];

        if ($data['title'] === null) {
            $issues[] = $this->issue('content', 'Missing page title', 'important', 'Add a descriptive title element.');
        }

        if ($data['meta_description'] === null) {
            $issues[] = $this->issue('content', 'Missing meta description', 'important', 'Add a concise meta description.');
        }

        if ($data['h1_count'] === 0) {
            $issues[] = $this->issue('content', 'Missing H1 heading', 'important', 'Add one clear H1 heading.');
        } elseif ($data['h1_count'] > 1) {
            $issues[] = $this->issue('content', 'Multiple H1 headings', 'minor', 'Use one primary H1 heading.');
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
}
