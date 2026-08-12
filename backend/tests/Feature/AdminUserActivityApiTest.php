<?php

namespace Tests\Feature;

use App\Models\AccessLog;
use App\Models\AiRecommendation;
use App\Models\Audit;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserActivityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_view_user_activity(): void
    {
        $user = User::factory()->create();

        $this->getJson("/api/admin/users/{$user->id}/activity")
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_non_admin_cannot_view_user_activity(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        Sanctum::actingAs($actor);

        $this->getJson("/api/admin/users/{$target->id}/activity")
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Forbidden',
            ]);
    }

    public function test_admin_can_view_safe_user_activity_summary(): void
    {
        $this->travelTo('2026-08-12 12:00:00');
        $admin = $this->createAdmin();
        $user = User::factory()->create(['email' => 'active-user@example.com']);

        foreach (range(1, 12) as $index) {
            $this->createAccessLog($user, [
                'route' => "/api/recent-{$index}",
                'ip_address' => $index === 1 ? '203.0.113.10' : null,
                'created_at' => now()->subHours($index),
            ]);
        }

        $this->createAccessLog($user, [
            'route' => '/api/three-days-ago',
            'ip_address' => '203.0.113.20',
            'created_at' => now()->subDays(3),
        ]);
        $this->createAccessLog($user, [
            'route' => '/api/old',
            'created_at' => now()->subDays(8),
        ]);

        $completedAudit = $this->createAuditFor($user, Audit::STATUS_COMPLETED);
        $this->createAuditFor($user, Audit::STATUS_FAILED);
        $this->createAuditFor($user, Audit::STATUS_PENDING);
        AiRecommendation::create([
            'audit_id' => $completedAudit->id,
            'generated_text' => 'Safe recommendation',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/admin/users/{$user->id}/activity");

        $response
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', 'active-user@example.com')
            ->assertJsonPath('last_ip', '203.0.113.10')
            ->assertJsonPath('request_count_last_24h', 12)
            ->assertJsonPath('request_count_last_7d', 13)
            ->assertJsonCount(10, 'recent_routes')
            ->assertJsonPath('recent_routes.0.route', '/api/recent-1')
            ->assertJsonPath('audits_count', 3)
            ->assertJsonPath('completed_audits_count', 1)
            ->assertJsonPath('failed_audits_count', 1)
            ->assertJsonPath('recommendations_count', 1)
            ->assertJsonMissingPath('recent_routes.0.ip_address')
            ->assertJsonMissingPath('recent_routes.0.user_agent')
            ->assertJsonMissingPath('recent_routes.0.request_body')
            ->assertJsonMissingPath('recent_routes.0.headers');

        $this->assertSame(
            now()->subHour()->toJSON(),
            $response->json('last_seen_at'),
        );
    }

    public function test_activity_summary_is_empty_for_user_without_access_logs(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/users/{$user->id}/activity")
            ->assertOk()
            ->assertJsonPath('last_seen_at', null)
            ->assertJsonPath('last_ip', null)
            ->assertJsonPath('request_count_last_24h', 0)
            ->assertJsonPath('request_count_last_7d', 0)
            ->assertJsonCount(0, 'recent_routes');
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
    private function createAccessLog(User $user, array $attributes): AccessLog
    {
        return AccessLog::create([
            'user_id' => $user->id,
            'ip_address' => $attributes['ip_address'] ?? null,
            'method' => $attributes['method'] ?? 'GET',
            'route' => $attributes['route'],
            'status_code' => $attributes['status_code'] ?? 200,
            'user_agent' => null,
            'created_at' => $attributes['created_at'],
        ]);
    }

    private function createAuditFor(User $user, string $status): Audit
    {
        $domain = Domain::create([
            'user_id' => $user->id,
            'domain_name' => fake()->unique()->domainName(),
            'url' => fake()->url(),
        ]);

        return $domain->audits()->create([
            'status' => $status,
            'requested_url' => $domain->url,
        ]);
    }
}
