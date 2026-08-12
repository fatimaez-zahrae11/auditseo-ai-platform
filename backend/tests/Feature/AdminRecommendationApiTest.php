<?php

namespace Tests\Feature;

use App\Models\AiRecommendation;
use App\Models\Audit;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminRecommendationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_admin_recommendations(): void
    {
        $this->getJson('/api/admin/recommendations')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_authenticated_non_admin_user_cannot_access_admin_recommendations(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/recommendations')
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Forbidden',
            ]);
    }

    public function test_inactive_admin_cannot_access_admin_recommendations(): void
    {
        $admin = $this->createAdmin();
        $admin->is_active = false;
        $admin->save();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/recommendations')
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Account disabled',
            ]);
    }

    public function test_admin_can_list_recommendations_across_users_with_owner_and_audit_data(): void
    {
        $admin = $this->createAdmin();
        $firstUser = User::factory()->create(['email' => 'first@example.com']);
        $secondUser = User::factory()->create(['email' => 'second@example.com']);
        $firstAudit = $this->createAuditFor($firstUser, [
            'requested_url' => 'https://first.example/requested',
            'final_url' => 'https://first.example/final',
        ]);
        $secondAudit = $this->createAuditFor($secondUser, [
            'requested_url' => 'https://second.example/requested',
            'final_url' => null,
        ]);
        $firstRecommendation = $this->createRecommendation($firstAudit, 'First recommendation');
        $secondRecommendation = $this->createRecommendation($secondAudit, 'Second recommendation');
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/recommendations');

        $response
            ->assertOk()
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonFragment([
                'id' => $firstUser->id,
                'email' => 'first@example.com',
            ])
            ->assertJsonFragment([
                'id' => $secondUser->id,
                'email' => 'second@example.com',
            ])
            ->assertJsonFragment([
                'requested_url' => 'https://first.example/requested',
                'final_url' => 'https://first.example/final',
            ]);

        $this->assertEqualsCanonicalizing(
            [$firstRecommendation->id, $secondRecommendation->id],
            collect($response->json('recommendations'))->pluck('id')->all(),
        );
    }

    public function test_generated_text_is_returned_only_as_a_three_hundred_character_preview(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        $prefix = str_repeat('é', 300);
        $secretSuffix = 'FULL-TEXT-SECRET-BEYOND-PREVIEW';
        $fullText = $prefix.$secretSuffix;
        $this->createRecommendation($audit, $fullText);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/recommendations');
        $preview = $response->json('recommendations.0.generated_text_preview');

        $response
            ->assertOk()
            ->assertJsonMissingPath('recommendations.0.generated_text');
        $this->assertSame(300, mb_strlen($preview));
        $this->assertSame($prefix, $preview);
        $this->assertStringNotContainsString($secretSuffix, $response->getContent());
        $this->assertStringNotContainsString($fullText, $response->getContent());
    }

    public function test_user_id_and_audit_id_filters_work(): void
    {
        $admin = $this->createAdmin();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstAudit = $this->createAuditFor($firstUser);
        $secondAudit = $this->createAuditFor($secondUser);
        $firstRecommendation = $this->createRecommendation($firstAudit, 'First');
        $secondRecommendation = $this->createRecommendation($secondAudit, 'Second');
        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/recommendations?user_id={$firstUser->id}")
            ->assertOk()
            ->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.id', $firstRecommendation->id);

        $this->getJson("/api/admin/recommendations?audit_id={$secondAudit->id}")
            ->assertOk()
            ->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.id', $secondRecommendation->id);
    }

    public function test_created_from_and_created_to_filters_are_inclusive(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);
        $oldRecommendation = $this->createRecommendation(
            $audit,
            'Old',
            '2026-01-01 12:00:00',
        );
        $includedRecommendation = $this->createRecommendation(
            $audit,
            'Included',
            '2026-01-15 12:00:00',
        );
        $newRecommendation = $this->createRecommendation(
            $audit,
            'New',
            '2026-02-01 12:00:00',
        );
        Sanctum::actingAs($admin);

        $this->getJson(
            '/api/admin/recommendations?created_from=2026-01-15&created_to=2026-01-15',
        )
            ->assertOk()
            ->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.id', $includedRecommendation->id)
            ->assertJsonMissing(['id' => $oldRecommendation->id])
            ->assertJsonMissing(['id' => $newRecommendation->id]);
    }

    public function test_search_works_for_user_email_and_audit_urls(): void
    {
        $admin = $this->createAdmin();
        $emailUser = User::factory()->create(['email' => 'unique-owner@example.com']);
        $urlUser = User::factory()->create();
        $emailAudit = $this->createAuditFor($emailUser, [
            'requested_url' => 'https://ordinary.example/page',
        ]);
        $urlAudit = $this->createAuditFor($urlUser, [
            'requested_url' => 'https://search.example/special-path',
            'final_url' => 'https://search.example/final-destination',
        ]);
        $unrelatedAudit = $this->createAuditFor($urlUser, [
            'requested_url' => 'https://unrelated.example/page',
        ]);
        $emailRecommendation = $this->createRecommendation($emailAudit, 'Email match');
        $urlRecommendation = $this->createRecommendation($urlAudit, 'URL match');
        $this->createRecommendation($unrelatedAudit, 'Unrelated');
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/recommendations?search=UNIQUE-OWNER')
            ->assertOk()
            ->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.id', $emailRecommendation->id);

        $this->getJson('/api/admin/recommendations?search=FINAL-DESTINATION')
            ->assertOk()
            ->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.id', $urlRecommendation->id);
    }

    public function test_recommendations_are_paginated_with_a_safe_per_page_cap(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user);

        foreach (range(1, 5) as $index) {
            $this->createRecommendation($audit, "Recommendation {$index}");
        }

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/recommendations?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'recommendations')
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total', 5)
            ->assertJsonPath('pagination.last_page', 3);

        $this->getJson('/api/admin/recommendations?per_page=500')
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 100);
    }

    public function test_query_count_does_not_grow_per_recommendation_audit_or_user(): void
    {
        $admin = $this->createAdmin();
        $firstUser = User::factory()->create();
        $firstAudit = $this->createAuditFor($firstUser);
        $this->createRecommendation($firstAudit, 'First');
        Sanctum::actingAs($admin);

        $capturing = false;
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$capturing, &$queries): void {
            if ($capturing) {
                $queries[] = $query->sql;
            }
        });

        $capturing = true;
        $this->getJson('/api/admin/recommendations')->assertOk();
        $capturing = false;
        $singleRecommendationQueryCount = count($queries);

        foreach (range(1, 10) as $index) {
            $user = User::factory()->create();
            $audit = $this->createAuditFor($user, [
                'requested_url' => "https://query-count-{$index}.example/page",
            ]);
            $this->createRecommendation($audit, "Recommendation {$index}");
        }

        $queries = [];
        $capturing = true;
        $this->getJson('/api/admin/recommendations')->assertOk();
        $capturing = false;

        $this->assertSame($singleRecommendationQueryCount, count($queries));
        $this->assertLessThanOrEqual(6, count($queries));
    }

    public function test_admin_response_does_not_expose_sensitive_ai_or_audit_data(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user, [
            'raw_data' => ['api_key' => 'RAW-DATA-SECRET'],
            'failure_reason' => 'STACK-TRACE-SECRET',
        ]);
        $this->createRecommendation($audit, 'Safe preview', null, [
            'provider' => 'PROVIDER-SECRET',
            'prompt_summary' => 'PROMPT-SECRET',
        ]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/recommendations');
        $content = $response->assertOk()->getContent();

        $response
            ->assertJsonMissingPath('recommendations.0.provider')
            ->assertJsonMissingPath('recommendations.0.prompt_summary')
            ->assertJsonMissingPath('recommendations.0.audit.raw_data')
            ->assertJsonMissingPath('recommendations.0.audit.failure_reason');
        $this->assertStringNotContainsString('PROVIDER-SECRET', $content);
        $this->assertStringNotContainsString('PROMPT-SECRET', $content);
        $this->assertStringNotContainsString('RAW-DATA-SECRET', $content);
        $this->assertStringNotContainsString('STACK-TRACE-SECRET', $content);
    }

    public function test_user_facing_recommendations_remain_scoped_to_authenticated_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownAudit = $this->createAuditFor($user);
        $otherAudit = $this->createAuditFor($otherUser);
        $ownRecommendation = $this->createRecommendation($ownAudit, 'Owned recommendation');
        $this->createRecommendation($otherAudit, 'Other recommendation');
        Sanctum::actingAs($user);

        $this->getJson("/api/audits/{$ownAudit->id}/recommendations")
            ->assertOk()
            ->assertJsonCount(1, 'recommendations')
            ->assertJsonPath('recommendations.0.id', $ownRecommendation->id);

        $this->getJson("/api/audits/{$otherAudit->id}/recommendations")
            ->assertNotFound();
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->role = User::ROLE_ADMIN;
        $admin->save();

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAuditFor(User $user, array $attributes = []): Audit
    {
        $domain = Domain::create([
            'user_id' => $user->id,
            'domain_name' => fake()->unique()->domainName(),
            'url' => fake()->url(),
        ]);

        return $domain->audits()->create([
            'status' => Audit::STATUS_COMPLETED,
            'requested_url' => $attributes['requested_url'] ?? $domain->url,
            'final_url' => $attributes['final_url'] ?? null,
            'raw_data' => $attributes['raw_data'] ?? null,
            'failure_reason' => $attributes['failure_reason'] ?? null,
            'completed_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createRecommendation(
        Audit $audit,
        string $generatedText,
        ?string $createdAt = null,
        array $attributes = [],
    ): AiRecommendation {
        $recommendation = AiRecommendation::create([
            'audit_id' => $audit->id,
            'provider' => $attributes['provider'] ?? 'test-provider',
            'prompt_summary' => $attributes['prompt_summary'] ?? 'Stored prompt summary',
            'generated_text' => $generatedText,
        ]);

        if ($createdAt !== null) {
            $recommendation->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();
        }

        return $recommendation->refresh();
    }
}
