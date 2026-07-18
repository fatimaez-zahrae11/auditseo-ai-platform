<?php

namespace Tests\Feature;

use App\Models\ApiUsageLog;
use App\Models\Audit;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
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
            'chat_endpoint' => '/v1/chat/completions',
            'model' => self::AI_MODEL,
            'api_key' => self::AI_KEY,
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
        $this->assertNotEmpty($response->json('recommendation.prompt_summary'));
    }

    public function test_ai_request_uses_the_configured_endpoint_model_and_audit_data(): void
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
                && $request->hasHeader('Authorization', 'Bearer '.self::AI_KEY)
                && str_contains($userPrompt, '"scores"')
                && str_contains($userPrompt, '"raw_data"')
                && str_contains($userPrompt, '"issues"');
        });
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

    private function createAuditFor(User $user): Audit
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
            'raw_data' => [
                'title' => null,
                'meta_description' => 'Example description',
                'h1_count' => 1,
                'links_count' => 0,
            ],
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
}
