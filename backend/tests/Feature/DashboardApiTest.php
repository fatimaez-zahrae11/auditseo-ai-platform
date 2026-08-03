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
            ->assertJsonPath('completed_audits', 2)
            ->assertJsonPath('pending_audits', 0)
            ->assertJsonPath('running_audits', 0)
            ->assertJsonPath('failed_audits', 0)
            ->assertJsonPath('average_global_score', 76)
            ->assertJsonPath('total_issues', 3)
            ->assertJsonPath('total_ai_recommendations', 3)
            ->assertJsonPath('latest_audit.id', $latestAudit->id)
            ->assertJsonPath('latest_audit.domain.user_id', $user->id)
            ->assertJsonPath('latest_audit.domain.domain_name', 'latest.example.com')
            ->assertJsonPath('latest_completed_audit.id', $latestAudit->id)
            ->assertJsonPath('latest_completed_audit.domain.user_id', $user->id);
    }

    public function test_async_audit_statuses_do_not_distort_completed_score_average(): void
    {
        $user = User::factory()->create();
        $this->createAuditFor($user, 'completed-old.example.com', 80);
        $this->createAuditFor(
            $user,
            'pending.example.com',
            0,
            Audit::STATUS_PENDING,
        );
        $latestCompletedAudit = $this->createAuditFor($user, 'completed-latest.example.com', 60);
        $this->createAuditFor($user, 'running.example.com', 100, Audit::STATUS_RUNNING);
        $latestAudit = $this->createAuditFor($user, 'failed.example.com', 20, Audit::STATUS_FAILED);
        Sanctum::actingAs($user);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('total_audits', 5)
            ->assertJsonPath('completed_audits', 2)
            ->assertJsonPath('pending_audits', 1)
            ->assertJsonPath('running_audits', 1)
            ->assertJsonPath('failed_audits', 1)
            ->assertJsonPath('average_global_score', 70)
            ->assertJsonPath('latest_audit.id', $latestAudit->id)
            ->assertJsonPath('latest_audit.status', Audit::STATUS_FAILED)
            ->assertJsonPath('latest_completed_audit.id', $latestCompletedAudit->id)
            ->assertJsonPath('latest_completed_audit.status', Audit::STATUS_COMPLETED);
    }

    public function test_dashboard_uses_zero_average_when_the_user_has_no_completed_audits(): void
    {
        $user = User::factory()->create();
        $this->createAuditFor($user, 'pending.example.com', 100, Audit::STATUS_PENDING);
        $this->createAuditFor($user, 'running.example.com', 100, Audit::STATUS_RUNNING);
        $latestAudit = $this->createAuditFor($user, 'failed.example.com', 100, Audit::STATUS_FAILED);
        Sanctum::actingAs($user);

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('total_audits', 3)
            ->assertJsonPath('completed_audits', 0)
            ->assertJsonPath('pending_audits', 1)
            ->assertJsonPath('running_audits', 1)
            ->assertJsonPath('failed_audits', 1)
            ->assertJsonPath('average_global_score', 0)
            ->assertJsonPath('latest_audit.id', $latestAudit->id)
            ->assertJsonPath('latest_completed_audit', null);
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
            ->assertJsonPath('completed_audits', 1)
            ->assertJsonPath('pending_audits', 0)
            ->assertJsonPath('running_audits', 0)
            ->assertJsonPath('failed_audits', 0)
            ->assertJsonPath('average_global_score', 60)
            ->assertJsonPath('total_issues', 1)
            ->assertJsonPath('total_ai_recommendations', 1)
            ->assertJsonPath('latest_audit.id', $ownAudit->id)
            ->assertJsonPath('latest_completed_audit.id', $ownAudit->id)
            ->assertJsonMissing(['domain_name' => 'other.example.com']);
    }

    public function test_dashboard_returns_zero_and_null_values_when_user_has_no_audits(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertExactJson([
                'total_audits' => 0,
                'completed_audits' => 0,
                'pending_audits' => 0,
                'running_audits' => 0,
                'failed_audits' => 0,
                'average_global_score' => 0,
                'total_issues' => 0,
                'total_ai_recommendations' => 0,
                'latest_audit' => null,
                'latest_completed_audit' => null,
            ]);
    }

    private function createAuditFor(
        User $user,
        string $domainName,
        int $globalScore,
        string $status = Audit::STATUS_COMPLETED,
    ): Audit {
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
            'status' => $status,
            'started_at' => $status === Audit::STATUS_PENDING ? null : now()->subMinute(),
            'completed_at' => $status === Audit::STATUS_COMPLETED ? now() : null,
            'failed_at' => $status === Audit::STATUS_FAILED ? now() : null,
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
