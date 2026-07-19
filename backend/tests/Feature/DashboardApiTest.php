<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_users_cannot_access_dashboard(): void
    {
        $this->getJson('/api/dashboard')->assertUnauthorized();
    }

    public function test_an_authenticated_user_can_retrieve_dashboard_statistics(): void
    {
        $user = User::factory()->create();
        $olderAudit = $this->createAuditFor($user, 'older.example.com', 70);
        $latestAudit = $this->createAuditFor($user, 'latest.example.com', 81);

        $olderAudit->issues()->createMany([
            $this->issue('Missing title'),
            $this->issue('Missing description'),
        ]);
        $latestAudit->issues()->create($this->issue('Missing sitemap'));

        $olderAudit->aiRecommendations()->create($this->recommendation('First recommendation'));
        $latestAudit->aiRecommendations()->createMany([
            $this->recommendation('Second recommendation'),
            $this->recommendation('Third recommendation'),
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('total_audits', 2)
            ->assertJsonPath('average_global_score', 76)
            ->assertJsonPath('total_issues', 3)
            ->assertJsonPath('total_ai_recommendations', 3)
            ->assertJsonPath('latest_audit.id', $latestAudit->id)
            ->assertJsonPath('latest_audit.domain.user_id', $user->id)
            ->assertJsonPath('latest_audit.domain.domain_name', 'latest.example.com');
    }

    public function test_dashboard_only_includes_the_authenticated_users_data(): void
    {
        $user = User::factory()->create();
        $ownAudit = $this->createAuditFor($user, 'own.example.com', 60);
        $ownAudit->issues()->create($this->issue('Own issue'));
        $ownAudit->aiRecommendations()->create($this->recommendation('Own recommendation'));

        $otherUser = User::factory()->create();
        $otherAudit = $this->createAuditFor($otherUser, 'other.example.com', 100);
        $otherAudit->issues()->createMany([
            $this->issue('Other issue one'),
            $this->issue('Other issue two'),
        ]);
        $otherAudit->aiRecommendations()->createMany([
            $this->recommendation('Other recommendation one'),
            $this->recommendation('Other recommendation two'),
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('total_audits', 1)
            ->assertJsonPath('average_global_score', 60)
            ->assertJsonPath('total_issues', 1)
            ->assertJsonPath('total_ai_recommendations', 1)
            ->assertJsonPath('latest_audit.id', $ownAudit->id)
            ->assertJsonMissing(['domain_name' => 'other.example.com']);
    }

    public function test_dashboard_returns_zero_and_null_values_when_user_has_no_audits(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertExactJson([
                'total_audits' => 0,
                'average_global_score' => 0,
                'total_issues' => 0,
                'total_ai_recommendations' => 0,
                'latest_audit' => null,
            ]);
    }

    private function createAuditFor(User $user, string $domainName, int $globalScore): Audit
    {
        $domain = Domain::create([
            'user_id' => $user->id,
            'domain_name' => $domainName,
            'url' => "https://{$domainName}",
        ]);

        return $domain->audits()->create([
            'global_score' => $globalScore,
            'technical_score' => $globalScore,
            'content_score' => $globalScore,
            'links_score' => $globalScore,
            'performance_score' => $globalScore,
            'raw_data' => null,
        ]);
    }

    /** @return array<string, string> */
    private function issue(string $title): array
    {
        return [
            'category' => 'technical',
            'title' => $title,
            'severity' => 'important',
            'description' => 'Issue description.',
            'recommendation' => 'Fix the issue.',
        ];
    }

    /** @return array<string, string> */
    private function recommendation(string $text): array
    {
        return [
            'provider' => 'test-provider',
            'prompt_summary' => 'Test prompt summary.',
            'generated_text' => $text,
        ];
    }
}
