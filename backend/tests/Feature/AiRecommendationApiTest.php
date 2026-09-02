<?php

namespace Tests\Feature;

use App\Models\ActionLog;
use App\Models\ApiUsageLog;
use App\Models\Audit;
use App\Models\Domain;
use App\Models\User;
use App\Services\Ai\BoundedAiResponseStream;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\InflateStream;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class AiRecommendationApiTest extends TestCase
{
    use RefreshDatabase;

    private const AI_URL = 'https://ai.example.test/v1/chat/completions';

    private const AI_MODEL = 'test-seo-model';

    private const AI_KEY = 'testing-secret-value';

    private int $aiStatus;

    /** @var array<string, mixed> */
    private array $aiResponse;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('services.ai', [
            'provider' => 'test-provider',
            'base_url' => 'https://ai.example.test',
            'allowed_hosts' => ['ai.example.test'],
            'chat_endpoint' => '/v1/chat/completions',
            'model' => self::AI_MODEL,
            'api_key' => self::AI_KEY,
            'max_output_tokens' => 512,
            'max_response_bytes' => 1_048_576,
            'max_generated_text_chars' => 20_000,
        ]);

        $this->aiStatus = 200;
        $this->aiResponse = [
            'choices' => [
                ['message' => ['content' => 'Improve the title and add descriptive alt text.']],
            ],
        ];

        Http::fake(fn () => Http::response($this->aiResponse, $this->aiStatus));
    }

    public function test_unauthenticated_users_cannot_generate_recommendations(): void
    {
        $audit = $this->createAuditFor(User::factory()->create());

        $this->postJson("/api/audits/{$audit->id}/recommendations")->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_unauthenticated_users_cannot_retrieve_recommendations(): void
    {
        $audit = $this->createAuditFor(User::factory()->create());

        $this->getJson("/api/audits/{$audit->id}/recommendations")->assertUnauthorized();

        Http::assertNothingSent();
    }

    public function test_an_authenticated_user_can_retrieve_their_stored_recommendations_newest_first(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        $olderRecommendation = $audit->aiRecommendations()->create([
            'provider' => 'test-provider',
            'prompt_summary' => 'Older recommendation',
            'generated_text' => 'Update the title.',
        ]);
        $newerRecommendation = $audit->aiRecommendations()->create([
            'provider' => 'test-provider',
            'prompt_summary' => 'Newer recommendation',
            'generated_text' => 'Improve internal links.',
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/audits/{$audit->id}/recommendations")
            ->assertOk()
            ->assertJsonCount(2, 'recommendations')
            ->assertJsonPath('recommendations.0.id', $newerRecommendation->id)
            ->assertJsonPath('recommendations.1.id', $olderRecommendation->id)
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.last_page', 1)
            ->assertJsonPath('pagination.per_page', 20)
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonPath('pagination.from', 1)
            ->assertJsonPath('pagination.to', 2)
            ->assertJsonPath('pagination.next_page_url', null)
            ->assertJsonPath('pagination.previous_page_url', null)
            ->assertDontSee(self::AI_KEY);
    }

    public function test_recommendation_history_enforces_the_default_page_size(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        $recommendationIds = $this->createRecommendations($audit, 25);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/audits/{$audit->id}/recommendations");

        $response
            ->assertOk()
            ->assertJsonCount(20, 'recommendations')
            ->assertJsonPath('recommendations.0.id', $recommendationIds[24])
            ->assertJsonPath('recommendations.19.id', $recommendationIds[5])
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonPath('pagination.per_page', 20)
            ->assertJsonPath('pagination.total', 25)
            ->assertJsonPath('pagination.from', 1)
            ->assertJsonPath('pagination.to', 20);
        $this->assertNotNull($response->json('pagination.next_page_url'));
        $this->assertNull($response->json('pagination.previous_page_url'));
    }

    public function test_recommendation_history_page_parameter_preserves_newest_first_ordering(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        $recommendationIds = $this->createRecommendations($audit, 25);
        Sanctum::actingAs($user);

        $response = $this->getJson("/api/audits/{$audit->id}/recommendations?page=2");

        $response
            ->assertOk()
            ->assertJsonCount(5, 'recommendations')
            ->assertJsonPath('recommendations.0.id', $recommendationIds[4])
            ->assertJsonPath('recommendations.4.id', $recommendationIds[0])
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonPath('pagination.per_page', 20)
            ->assertJsonPath('pagination.total', 25)
            ->assertJsonPath('pagination.from', 21)
            ->assertJsonPath('pagination.to', 25)
            ->assertJsonPath('pagination.next_page_url', null);
        $this->assertNotNull($response->json('pagination.previous_page_url'));
    }

    public function test_recommendation_history_enforces_the_maximum_page_size(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        $this->createRecommendations($audit, 55);
        Sanctum::actingAs($user);

        $this->getJson("/api/audits/{$audit->id}/recommendations?per_page=500")
            ->assertOk()
            ->assertJsonCount(50, 'recommendations')
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.last_page', 2)
            ->assertJsonPath('pagination.per_page', 50)
            ->assertJsonPath('pagination.total', 55)
            ->assertJsonPath('pagination.from', 1)
            ->assertJsonPath('pagination.to', 50);
    }

    public function test_empty_recommendation_history_returns_pagination_metadata(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $this->getJson("/api/audits/{$audit->id}/recommendations")
            ->assertOk()
            ->assertJsonCount(0, 'recommendations')
            ->assertJsonPath('pagination.current_page', 1)
            ->assertJsonPath('pagination.last_page', 1)
            ->assertJsonPath('pagination.per_page', 20)
            ->assertJsonPath('pagination.total', 0)
            ->assertJsonPath('pagination.from', null)
            ->assertJsonPath('pagination.to', null)
            ->assertJsonPath('pagination.next_page_url', null)
            ->assertJsonPath('pagination.previous_page_url', null);
    }

    public function test_a_user_cannot_retrieve_recommendations_for_another_users_audit(): void
    {
        $user = User::factory()->create();
        $otherAudit = $this->createAuditFor(User::factory()->create());
        $otherAudit->aiRecommendations()->create([
            'provider' => 'test-provider',
            'prompt_summary' => 'Private recommendation',
            'generated_text' => 'Private recommendation text.',
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/audits/{$otherAudit->id}/recommendations")->assertNotFound();

        Http::assertNothingSent();
    }

    public function test_retrieving_recommendations_does_not_call_the_external_ai_api(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        $audit->aiRecommendations()->create([
            'provider' => 'test-provider',
            'prompt_summary' => 'Stored recommendation',
            'generated_text' => 'Use the stored recommendation.',
        ]);
        Sanctum::actingAs($user);

        $this->getJson("/api/audits/{$audit->id}/recommendations")->assertOk();

        Http::assertNothingSent();
    }

    public function test_an_authenticated_user_can_generate_and_store_a_recommendation_for_their_audit(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/audits/{$audit->id}/recommendations");

        $response
            ->assertCreated()
            ->assertJsonPath('message', 'AI recommendation generated successfully.')
            ->assertJsonPath('recommendation.audit_id', $audit->id)
            ->assertJsonPath('recommendation.provider', 'test-provider')
            ->assertJsonPath(
                'recommendation.generated_text',
                'Improve the title and add descriptive alt text.',
            );

        $this->assertDatabaseHas('ai_recommendations', [
            'audit_id' => $audit->id,
            'provider' => 'test-provider',
            'generated_text' => 'Improve the title and add descriptive alt text.',
        ]);
        $this->assertDatabaseHas('api_usage_logs', [
            'user_id' => $user->id,
            'provider' => 'test-provider',
            'status' => 'success',
            'status_code' => 200,
            'error_message' => null,
        ]);
        $this->assertDatabaseHas('action_logs', [
            'actor_user_id' => $user->id,
            'actor_role' => User::ROLE_USER,
            'action' => ActionLog::ACTION_RECOMMENDATION_REQUESTED,
            'entity_type' => 'audit',
            'entity_id' => $audit->id,
            'status' => ActionLog::STATUS_SUCCESS,
        ]);
        $this->assertNotEmpty($response->json('recommendation.prompt_summary'));
    }

    public function test_generated_text_is_stored_and_returned_as_an_untrusted_plain_string(): void
    {
        $generatedText = '<script>alert("untrusted")</script> **Review this recommendation.**';
        $this->aiResponse = [
            'choices' => [
                ['message' => ['content' => $generatedText]],
            ],
        ];
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/audits/{$audit->id}/recommendations");

        $response
            ->assertCreated()
            ->assertJsonPath('recommendation.generated_text', $generatedText);
        $this->assertIsString($response->json('recommendation.generated_text'));
        $this->assertDatabaseHas('ai_recommendations', [
            'audit_id' => $audit->id,
            'generated_text' => $generatedText,
        ]);
    }

    public function test_pending_audit_cannot_generate_a_recommendation_or_call_the_provider(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user, [
            'status' => Audit::STATUS_PENDING,
            'started_at' => null,
            'completed_at' => null,
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$audit->id}/recommendations")
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'AI recommendations are only available after the audit is completed.',
            ]);

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_recommendations', 0);
        $this->assertDatabaseCount('api_usage_logs', 0);
        $this->assertDatabaseHas('action_logs', [
            'actor_user_id' => $user->id,
            'action' => ActionLog::ACTION_RECOMMENDATION_REQUESTED,
            'entity_type' => 'audit',
            'entity_id' => $audit->id,
            'status' => ActionLog::STATUS_FAILURE,
        ]);
    }

    public function test_running_audit_cannot_generate_a_recommendation_or_call_the_provider(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user, [
            'status' => Audit::STATUS_RUNNING,
            'completed_at' => null,
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$audit->id}/recommendations")
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'AI recommendations are only available after the audit is completed.',
            ]);

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_recommendations', 0);
        $this->assertDatabaseCount('api_usage_logs', 0);
    }

    public function test_failed_audit_cannot_generate_a_recommendation_or_expose_failure_details(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user, [
            'status' => Audit::STATUS_FAILED,
            'completed_at' => null,
            'failed_at' => now(),
            'failure_reason' => 'Sensitive crawler exception details.',
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$audit->id}/recommendations")
            ->assertStatus(409)
            ->assertExactJson([
                'message' => 'AI recommendations are only available after the audit is completed.',
            ])
            ->assertDontSee('Sensitive crawler exception details')
            ->assertDontSee(self::AI_KEY);

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_recommendations', 0);
        $this->assertDatabaseCount('api_usage_logs', 0);
    }

    public function test_valid_https_allowed_provider_host_generates_a_recommendation(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$audit->id}/recommendations")
            ->assertCreated()
            ->assertJsonPath(
                'recommendation.generated_text',
                'Improve the title and add descriptive alt text.',
            );

        Http::assertSent(fn (Request $request) => $request->url() === self::AI_URL);
    }

    public function test_http_provider_url_is_rejected_without_making_a_request(): void
    {
        Config::set('services.ai.base_url', 'http://ai.example.test');
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$audit->id}/recommendations")
            ->assertStatus(502)
            ->assertExactJson(['message' => 'AI recommendation service is unavailable.'])
            ->assertDontSee('http://ai.example.test')
            ->assertDontSee(self::AI_KEY);

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_recommendations', 0);
    }

    public function test_unexpected_provider_host_is_rejected_by_exact_match(): void
    {
        Config::set('services.ai.base_url', 'https://sub.ai.example.test');
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$audit->id}/recommendations")
            ->assertStatus(502)
            ->assertExactJson(['message' => 'AI recommendation service is unavailable.'])
            ->assertDontSee('sub.ai.example.test')
            ->assertDontSee(self::AI_KEY);

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_recommendations', 0);
    }

    public function test_missing_malformed_or_wildcard_provider_configuration_is_rejected(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        foreach ([
            ['', ['ai.example.test']],
            ['not-a-provider-url', ['ai.example.test']],
            ['https://ai.example.test', []],
            ['https://ai.example.test', ['*.example.test']],
        ] as [$baseUrl, $allowedHosts]) {
            Config::set('services.ai.base_url', $baseUrl);
            Config::set('services.ai.allowed_hosts', $allowedHosts);

            $this->postJson("/api/audits/{$audit->id}/recommendations")
                ->assertStatus(502)
                ->assertExactJson(['message' => 'AI recommendation service is unavailable.']);

            $this->travel(6)->minutes();
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_recommendations', 0);
    }

    public function test_ai_provider_redirect_is_not_followed_and_fails_generically(): void
    {
        $redirectUrl = 'https://unexpected-provider.example.test/internal';
        $rawProviderResponse = 'raw-provider-response-marker';
        $this->replaceHttpFake(fn () => Http::response(
            $rawProviderResponse,
            302,
            ['Location' => $redirectUrl],
        ));
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        $audit->update([
            'raw_data' => [
                ...$audit->raw_data,
                'title' => 'private-prompt-marker',
            ],
        ]);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/audits/{$audit->id}/recommendations");

        $response
            ->assertStatus(502)
            ->assertExactJson(['message' => 'AI recommendation service is unavailable.'])
            ->assertDontSee(self::AI_URL)
            ->assertDontSee('ai.example.test')
            ->assertDontSee(self::AI_KEY)
            ->assertDontSee('private-prompt-marker')
            ->assertDontSee($rawProviderResponse)
            ->assertDontSee('trace')
            ->assertDontSee('stack');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->url() === self::AI_URL);
        Http::assertNotSent(fn (Request $request) => $request->url() === $redirectUrl);
        $this->assertDatabaseCount('ai_recommendations', 0);
        $this->assertSafeFailedUsageLog($user, 302);

        $serializedLog = json_encode(
            ApiUsageLog::query()->sole()->getAttributes(),
            JSON_THROW_ON_ERROR,
        );
        $this->assertStringNotContainsString(self::AI_URL, $serializedLog);
        $this->assertStringNotContainsString(self::AI_KEY, $serializedLog);
        $this->assertStringNotContainsString('private-prompt-marker', $serializedLog);
        $this->assertStringNotContainsString($rawProviderResponse, $serializedLog);
    }

    public function test_ai_request_uses_the_configured_endpoint_model_and_minimized_audit_data(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$audit->id}/recommendations")->assertCreated();

        Http::assertSent(function (Request $request) {
            $messages = $request->data()['messages'] ?? [];
            $userPrompt = $messages[1]['content'] ?? '';

            return $request->url() === self::AI_URL
                && $request['model'] === self::AI_MODEL
                && $request['max_tokens'] === 512
                && $request->hasHeader('Authorization', 'Bearer '.self::AI_KEY)
                && str_contains($userPrompt, '"scores"')
                && str_contains($userPrompt, '"seo_signals"')
                && str_contains($userPrompt, '"title_present": false')
                && str_contains($userPrompt, '"h1_count": 1')
                && str_contains($userPrompt, '"issues"')
                && ! str_contains($userPrompt, '"raw_data"');
        });
    }

    public function test_ai_prompt_allowlists_signals_and_sanitizes_all_included_urls(): void
    {
        $sensitiveUrl = 'https://example.com/page?token=secret123&signature=abc#private';
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user, [
            'requested_url' => 'https://example.com/requested-private?token=secret123#private',
            'final_url' => $sensitiveUrl,
            'raw_data' => [
                'title' => 'Attacker-controlled title marker',
                'title_length' => 32,
                'meta_description' => 'Attacker-controlled description marker',
                'meta_description_length' => 120,
                'word_count' => 450,
                'h1_count' => 1,
                'title_matches_h1' => true,
                'images_count' => 10,
                'images_missing_alt_count' => 2,
                'images_alt_missing_ratio' => 0.2,
                'internal_links_count' => 14,
                'external_links_count' => 3,
                'broken_links_count' => 1,
                'broken_links_sample' => [
                    'https://example.com/broken?token=secret123&signature=abc#private',
                ],
                'http_status_code' => 200,
                'uses_https' => true,
                'is_indexable' => true,
                'canonical_url' => 'https://example.com/canonical?token=secret123#private',
                'canonical_matches_final_url' => false,
                'robots_txt_found' => true,
                'robots_txt_allows_audited_url' => true,
                'sitemap_xml_found' => true,
                'sitemap_xml_is_valid' => true,
                'sitemap_contains_audited_url' => true,
                'sitemap_urls_sample' => [
                    'https://example.com/sitemap-page?signature=abc#private',
                ],
                'structured_data_found' => false,
                'structured_data_errors_count' => 0,
                'response_time_ms' => 325,
                'page_size_bytes' => 42_000,
                'compression_enabled' => true,
                'cache_headers_present' => false,
                'is_html_response' => true,
                'crawled_pages_count' => 1,
                'crawled_pages' => [[
                    'url' => 'https://example.com/crawled?token=secret123#private',
                    'status_code' => 200,
                    'word_count' => 300,
                    'is_indexable' => true,
                    'response_time_ms' => 250,
                    'structured_data_found' => false,
                    'canonical_url' => 'https://example.com/crawled-canonical?signature=abc#private',
                    'title' => 'Arbitrary crawled page title marker',
                    'meta_description' => 'Arbitrary crawled page content marker',
                ]],
                'raw_html' => '<html>Arbitrary raw HTML marker</html>',
                'visible_text_sample' => 'Arbitrary raw page content marker token=secret123',
                'request_headers' => ['Authorization' => 'Bearer secret123'],
                'response_headers' => ['Set-Cookie' => 'auth=secret123'],
                'cookies' => ['session' => 'secret123'],
                'crawler_debug' => 'signature=abc',
            ],
        ]);
        $audit->issues()->firstOrFail()->update([
            'title' => 'Broken issue URL https://example.com/issue?token=secret123&signature=abc#private secret=issueSecret email=user@example.com',
            'description' => 'Arbitrary issue description marker.',
            'recommendation' => 'Arbitrary issue recommendation marker.',
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$audit->id}/recommendations")->assertCreated();

        $prompt = null;
        Http::assertSent(function (Request $request) use (&$prompt): bool {
            if ($request->url() !== self::AI_URL) {
                return false;
            }

            $prompt = (string) ($request->data()['messages'][1]['content'] ?? '');

            return true;
        });

        $this->assertIsString($prompt);
        $this->assertStringContainsString('"url": "https://example.com/page"', $prompt);
        $this->assertStringContainsString('"title_length": 32', $prompt);
        $this->assertStringContainsString('"meta_description_length": 120', $prompt);
        $this->assertStringContainsString('"images"', $prompt);
        $this->assertStringContainsString('"internal": 14', $prompt);
        $this->assertStringContainsString('"robots_txt_found": true', $prompt);
        $this->assertStringContainsString('"structured_data"', $prompt);
        $this->assertStringContainsString('"response_time_ms": 325', $prompt);
        $this->assertStringContainsString('https://example.com/canonical', $prompt);
        $this->assertStringContainsString('https://example.com/broken', $prompt);
        $this->assertStringContainsString('https://example.com/sitemap-page', $prompt);
        $this->assertStringContainsString('https://example.com/crawled', $prompt);
        $this->assertStringContainsString('https://example.com/crawled-canonical', $prompt);
        $this->assertStringContainsString('Broken issue URL https://example.com/issue', $prompt);
        $this->assertStringContainsString('secret=[REDACTED]', $prompt);
        $this->assertStringContainsString('email=[REDACTED]', $prompt);

        foreach ([
            'secret123',
            'signature=abc',
            'token=secret123',
            'issueSecret',
            'user@example.com',
            'requested-private',
            '"raw_data"',
            'raw_html',
            'visible_text_sample',
            'request_headers',
            'response_headers',
            'cookies',
            'crawler_debug',
            'Arbitrary raw HTML marker',
            'Arbitrary raw page content marker',
            'Arbitrary crawled page title marker',
            'Arbitrary crawled page content marker',
            'Arbitrary issue description marker',
            'Arbitrary issue recommendation marker',
        ] as $excludedValue) {
            $this->assertStringNotContainsString($excludedValue, $prompt);
        }
    }

    public function test_ai_prompt_uses_the_audit_final_url_when_available(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user, [
            'requested_url' => 'https://example.com/requested-path?token=secret123#private',
            'final_url' => 'https://example.com/final-path?token=secret123&signature=abc#private',
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$audit->id}/recommendations")->assertCreated();

        Http::assertSent(function (Request $request): bool {
            $userPrompt = $request->data()['messages'][1]['content'] ?? '';

            return str_contains($userPrompt, '"url": "https://example.com/final-path"')
                && ! str_contains($userPrompt, 'requested-path')
                && ! str_contains($userPrompt, 'secret123')
                && ! str_contains($userPrompt, 'signature=abc')
                && ! str_contains($userPrompt, 'token=secret123');
        });
    }

    public function test_ai_prompt_falls_back_to_the_audit_requested_url_when_final_url_is_null(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user, [
            'requested_url' => 'https://example.com/requested-only-path?token=secret123&signature=abc#private',
            'final_url' => null,
        ]);
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$audit->id}/recommendations")->assertCreated();

        Http::assertSent(function (Request $request): bool {
            $userPrompt = $request->data()['messages'][1]['content'] ?? '';

            return str_contains($userPrompt, '"url": "https://example.com/requested-only-path"')
                && ! str_contains($userPrompt, 'secret123')
                && ! str_contains($userPrompt, 'signature=abc')
                && ! str_contains($userPrompt, 'token=secret123');
        });
    }

    public function test_oversized_ai_content_length_is_rejected_before_body_buffering(): void
    {
        Config::set('services.ai.max_response_bytes', 128);
        $progress = (object) ['bytes' => 0];
        $this->replaceHttpFake(fn () => $this->streamedResponse(
            256,
            ['Content-Length' => '256', 'Content-Type' => 'application/json'],
            $progress,
        ));
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$audit->id}/recommendations")
            ->assertStatus(502)
            ->assertExactJson(['message' => 'AI recommendation service is unavailable.']);

        $this->assertSame(0, $progress->bytes);
        $this->assertDatabaseCount('ai_recommendations', 0);
        $this->assertSafeFailedUsageLog($user, 200);
    }

    public function test_misleading_content_length_does_not_bypass_ai_response_limit(): void
    {
        Config::set('services.ai.max_response_bytes', 128);
        $progress = (object) ['bytes' => 0];
        $this->replaceHttpFake(fn () => $this->streamedResponse(
            256,
            ['Content-Length' => '10', 'Content-Type' => 'application/json'],
            $progress,
            contents: 'provider-internal-diagnostics',
        ));
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/audits/{$audit->id}/recommendations");

        $response
            ->assertStatus(502)
            ->assertExactJson(['message' => 'AI recommendation service is unavailable.'])
            ->assertDontSee('provider-internal-diagnostics');

        $this->assertSame(129, $progress->bytes);
        $this->assertDatabaseCount('ai_recommendations', 0);
        $this->assertSafeFailedUsageLog($user, 200);
    }

    public function test_missing_content_length_does_not_bypass_ai_response_limit(): void
    {
        Config::set('services.ai.max_response_bytes', 128);
        $progress = (object) ['bytes' => 0];
        $this->replaceHttpFake(fn () => $this->streamedResponse(
            256,
            ['Transfer-Encoding' => 'chunked', 'Content-Type' => 'application/json'],
            $progress,
        ));
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$audit->id}/recommendations")
            ->assertStatus(502)
            ->assertExactJson(['message' => 'AI recommendation service is unavailable.']);

        $this->assertSame(129, $progress->bytes);
        $this->assertDatabaseCount('ai_recommendations', 0);
        $this->assertSafeFailedUsageLog($user, 200);
    }

    public function test_oversized_compressed_ai_response_is_rejected_after_decoding(): void
    {
        Config::set('services.ai.max_response_bytes', 128);
        $compressed = gzencode(str_repeat('x', 256));
        $this->assertNotFalse($compressed);
        $progress = (object) ['bytes' => 0];
        $this->replaceHttpFake(function () use ($compressed, $progress) {
            $encodedResponse = $this->streamedResponse(
                strlen($compressed),
                [],
                $progress,
                contents: $compressed,
            )->wait();

            return Create::promiseFor(new Psr7Response(
                200,
                [
                    'Content-Type' => 'application/json',
                    'X-Encoded-Content-Encoding' => 'gzip',
                    'X-Encoded-Content-Length' => (string) strlen($compressed),
                ],
                new InflateStream($encodedResponse->getBody()),
            ));
        });
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$audit->id}/recommendations")
            ->assertStatus(502)
            ->assertExactJson(['message' => 'AI recommendation service is unavailable.']);

        $this->assertGreaterThan(0, $progress->bytes);
        $this->assertDatabaseCount('ai_recommendations', 0);
        $this->assertSafeFailedUsageLog($user, 200);
    }

    public function test_excessively_large_generated_text_is_not_persisted(): void
    {
        Config::set('services.ai.max_generated_text_chars', 20);
        $this->aiResponse = [
            'choices' => [
                ['message' => ['content' => str_repeat('x', 21)]],
            ],
        ];
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$audit->id}/recommendations")
            ->assertStatus(502)
            ->assertExactJson(['message' => 'AI recommendation service is unavailable.']);

        $this->assertDatabaseCount('ai_recommendations', 0);
        $this->assertDatabaseHas('api_usage_logs', [
            'user_id' => $user->id,
            'provider' => 'test-provider',
            'status' => 'failed',
            'status_code' => 200,
            'error_message' => 'External AI response was invalid.',
        ]);
    }

    public function test_invalid_ai_json_is_handled_without_exposing_provider_data(): void
    {
        $this->replaceHttpFake(fn () => Http::response(
            '{"choices":[{"message":{"content":"provider-internal-data"}}',
            200,
            ['Content-Type' => 'application/json'],
        ));
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/audits/{$audit->id}/recommendations");

        $response
            ->assertStatus(502)
            ->assertExactJson(['message' => 'AI recommendation service is unavailable.'])
            ->assertDontSee('provider-internal-data')
            ->assertDontSee(self::AI_KEY);

        $this->assertDatabaseCount('ai_recommendations', 0);
        $this->assertDatabaseHas('api_usage_logs', [
            'user_id' => $user->id,
            'provider' => 'test-provider',
            'status' => 'failed',
            'status_code' => 200,
            'error_message' => 'External AI response was invalid.',
        ]);
    }

    public function test_bounded_ai_curl_sink_rejects_oversized_writes(): void
    {
        $stream = new BoundedAiResponseStream(5);

        $this->assertSame(5, $stream->write('12345'));

        try {
            $stream->write('6');
            $this->fail('The bounded AI response stream accepted an oversized write.');
        } catch (RuntimeException) {
            $this->assertSame(5, $stream->getSize());
        } finally {
            $stream->close();
        }
    }

    public function test_a_user_cannot_generate_a_recommendation_for_another_users_audit(): void
    {
        $user = User::factory()->create();
        $otherAudit = $this->createAuditFor(User::factory()->create());
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$otherAudit->id}/recommendations")->assertNotFound();

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_recommendations', 0);
    }

    public function test_external_ai_failure_is_handled_without_exposing_sensitive_data(): void
    {
        $this->aiStatus = 500;
        $this->aiResponse = [
            'error' => 'Upstream failure containing sensitive diagnostics.',
        ];
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/audits/{$audit->id}/recommendations");

        $response
            ->assertStatus(502)
            ->assertExactJson(['message' => 'AI recommendation service is unavailable.'])
            ->assertDontSee(self::AI_KEY)
            ->assertDontSee('sensitive diagnostics');
        $this->assertDatabaseCount('ai_recommendations', 0);
        $this->assertDatabaseHas('api_usage_logs', [
            'user_id' => $user->id,
            'provider' => 'test-provider',
            'status' => 'failed',
            'status_code' => 500,
            'error_message' => 'External AI request failed.',
        ]);
        $this->assertDatabaseMissing('api_usage_logs', [
            'error_message' => 'Upstream failure containing sensitive diagnostics.',
        ]);
    }

    public function test_an_invalid_ai_response_is_handled_and_logged(): void
    {
        $this->aiResponse = ['choices' => []];
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$audit->id}/recommendations")
            ->assertStatus(502)
            ->assertExactJson(['message' => 'AI recommendation service is unavailable.']);

        $this->assertDatabaseCount('ai_recommendations', 0);
        $this->assertDatabaseHas('api_usage_logs', [
            'user_id' => $user->id,
            'provider' => 'test-provider',
            'status' => 'failed',
            'status_code' => 200,
            'error_message' => 'External AI response was invalid.',
        ]);
    }

    public function test_an_ai_connection_failure_is_handled_and_logged_safely(): void
    {
        Http::fake(fn () => throw new ConnectionException('Sensitive connection details'));
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $response = $this->postJson("/api/audits/{$audit->id}/recommendations");

        $response
            ->assertStatus(502)
            ->assertExactJson(['message' => 'AI recommendation service is unavailable.'])
            ->assertDontSee('Sensitive connection details')
            ->assertDontSee(self::AI_KEY);
        $this->assertDatabaseCount('ai_recommendations', 0);
        $this->assertDatabaseHas('api_usage_logs', [
            'user_id' => $user->id,
            'provider' => 'test-provider',
            'status' => 'failed',
            'status_code' => null,
            'error_message' => 'External AI request failed.',
        ]);
    }

    public function test_recommendation_generation_is_rate_limited(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$audit->id}/recommendations")
            ->assertCreated();

        $this->postJson("/api/audits/{$audit->id}/recommendations")
            ->assertTooManyRequests();

        Http::assertSentCount(1);
        $this->assertDatabaseCount('ai_recommendations', 1);
        $this->assertDatabaseCount('api_usage_logs', 1);
    }

    public function test_recommendation_generation_rejects_a_second_concurrent_request_for_the_user(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);
        $lock = Cache::lock('ai_recommendation:user:'.$user->id.':lock', 180);
        $this->assertTrue($lock->get());

        try {
            $this->postJson("/api/audits/{$audit->id}/recommendations")
                ->assertTooManyRequests()
                ->assertExactJson([
                    'message' => 'AI recommendation generation is already in progress. Please try again shortly.',
                ]);
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
        $this->assertDatabaseCount('ai_recommendations', 0);
        $this->assertDatabaseCount('api_usage_logs', 0);
    }

    public function test_api_key_is_not_exposed_in_a_successful_json_response(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$audit->id}/recommendations")
            ->assertCreated()
            ->assertDontSee(self::AI_KEY);
    }

    public function test_api_key_is_not_stored_in_api_usage_logs(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        Sanctum::actingAs($user);

        $this->postJson("/api/audits/{$audit->id}/recommendations")->assertCreated();

        $usageLog = ApiUsageLog::query()->sole();

        $this->assertStringNotContainsString(
            self::AI_KEY,
            json_encode($usageLog->getAttributes(), JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAuditFor(User $user, array $attributes = []): Audit
    {
        $domain = Domain::create([
            'user_id' => $user->id,
            'domain_name' => 'example.com',
            'url' => 'https://example.com',
        ]);

        $audit = $domain->audits()->create([
            'global_score' => 70,
            'technical_score' => 80,
            'content_score' => 60,
            'links_score' => 70,
            'performance_score' => 70,
            'requested_url' => 'https://example.com/requested-page',
            'final_url' => 'https://example.com/final-page',
            'status' => Audit::STATUS_COMPLETED,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'raw_data' => [
                'title' => null,
                'meta_description' => 'Example description',
                'h1_count' => 1,
                'links_count' => 0,
            ],
            ...$attributes,
        ]);

        $audit->issues()->create([
            'category' => 'content',
            'title' => 'Missing page title',
            'severity' => 'important',
            'description' => 'The page has no title.',
            'recommendation' => 'Add a descriptive title.',
        ]);

        return $audit;
    }

    /**
     * @return list<int>
     */
    private function createRecommendations(Audit $audit, int $count): array
    {
        $ids = [];

        foreach (range(1, $count) as $number) {
            $ids[] = $audit->aiRecommendations()->create([
                'provider' => 'test-provider',
                'prompt_summary' => "Recommendation {$number}",
                'generated_text' => "Generated recommendation {$number}.",
            ])->id;
        }

        return $ids;
    }

    private function assertSafeFailedUsageLog(User $user, ?int $statusCode): void
    {
        $this->assertDatabaseHas('api_usage_logs', [
            'user_id' => $user->id,
            'provider' => 'test-provider',
            'status' => 'failed',
            'status_code' => $statusCode,
            'error_message' => 'External AI request failed.',
        ]);

        $usageLog = ApiUsageLog::query()->sole();
        $serializedLog = json_encode($usageLog->getAttributes(), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::AI_KEY, $serializedLog);
        $this->assertStringNotContainsString('provider-internal-diagnostics', $serializedLog);
    }

    private function replaceHttpFake(callable $callback): void
    {
        $factory = new HttpFactory($this->app['events']);
        Http::swap($factory);
        $factory->fake($callback);
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
}
