<?php

namespace Tests\Feature;

use App\Models\AiRecommendation;
use App\Models\Audit;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminUserApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_requests_cannot_access_admin_user_routes(): void
    {
        $user = User::factory()->create();

        foreach ($this->adminRequests($user) as [$method, $uri, $data]) {
            $this->json($method, $uri, $data)
                ->assertUnauthorized()
                ->assertExactJson([
                    'message' => 'Unauthenticated.',
                ]);
        }
    }

    public function test_non_admin_requests_are_forbidden_on_all_admin_user_routes(): void
    {
        $actor = User::factory()->create();
        $target = User::factory()->create();
        Sanctum::actingAs($actor);

        foreach ($this->adminRequests($target) as [$method, $uri, $data]) {
            $this->json($method, $uri, $data)
                ->assertForbidden()
                ->assertExactJson([
                    'message' => 'Forbidden',
                ]);
        }
    }

    public function test_admin_can_list_users_without_sensitive_fields(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/users');

        $response
            ->assertOk()
            ->assertJsonPath('pagination.total', 2)
            ->assertJsonFragment([
                'id' => $user->id,
                'email' => $user->email,
                'role' => User::ROLE_USER,
                'is_active' => true,
            ])
            ->assertJsonMissingPath('users.0.password')
            ->assertJsonMissingPath('users.0.remember_token')
            ->assertJsonMissingPath('users.0.blocked_at')
            ->assertJsonMissingPath('users.0.blocked_reason')
            ->assertJsonMissingPath('users.0.blocked_by')
            ->assertJsonMissingPath('users.0.tokens');

        $this->assertEqualsCanonicalizing([
            'id',
            'name',
            'email',
            'role',
            'is_active',
            'email_verified_at',
            'created_at',
            'audits_count',
            'completed_audits_count',
            'failed_audits_count',
            'recommendations_count',
        ], array_keys($response->json('users.0')));
    }

    public function test_admin_user_list_is_paginated_with_a_safe_per_page_maximum(): void
    {
        $admin = $this->createAdmin();
        User::factory()->count(5)->create();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/users?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'users')
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total', 6)
            ->assertJsonPath('pagination.last_page', 3);

        $this->getJson('/api/admin/users?per_page=500')
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 100);
    }

    public function test_admin_user_list_returns_accurate_audit_and_recommendation_counts(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $completedAudit = $this->createAuditFor($user, Audit::STATUS_COMPLETED);
        $failedAudit = $this->createAuditFor($user, Audit::STATUS_FAILED);
        $this->createAuditFor($user, Audit::STATUS_PENDING);
        $otherAudit = $this->createAuditFor($otherUser, Audit::STATUS_COMPLETED);

        AiRecommendation::create([
            'audit_id' => $completedAudit->id,
            'generated_text' => 'First recommendation',
        ]);
        AiRecommendation::create([
            'audit_id' => $failedAudit->id,
            'generated_text' => 'Second recommendation',
        ]);
        AiRecommendation::create([
            'audit_id' => $otherAudit->id,
            'generated_text' => 'Other user recommendation',
        ]);

        Sanctum::actingAs($admin);

        $listedUser = collect($this->getJson('/api/admin/users')->assertOk()->json('users'))
            ->firstWhere('id', $user->id);

        $this->assertSame(3, $listedUser['audits_count']);
        $this->assertSame(1, $listedUser['completed_audits_count']);
        $this->assertSame(1, $listedUser['failed_audits_count']);
        $this->assertSame(2, $listedUser['recommendations_count']);
    }

    public function test_admin_can_create_a_regular_user_and_verification_is_sent(): void
    {
        Notification::fake();
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/admin/users', [
            'name' => 'Created User',
            'email' => ' Created@Example.COM ',
            'password' => 'Password1',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('user.name', 'Created User')
            ->assertJsonPath('user.email', 'created@example.com')
            ->assertJsonPath('user.role', User::ROLE_USER)
            ->assertJsonPath('user.is_active', true)
            ->assertJsonMissingPath('user.password')
            ->assertJsonMissingPath('token');

        $user = User::where('email', 'created@example.com')->sole();

        $this->assertTrue(Hash::check('Password1', $user->password));
        $this->assertFalse($user->hasVerifiedEmail());
        Notification::assertSentTo($user, VerifyEmail::class);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_cannot_create_an_admin_even_when_role_is_submitted(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->createAdmin());

        $this->postJson('/api/admin/users', [
            'name' => 'Injected Admin',
            'email' => 'injected@example.com',
            'password' => 'Password1',
            'role' => User::ROLE_ADMIN,
        ])
            ->assertCreated()
            ->assertJsonPath('user.role', User::ROLE_USER);

        $this->assertDatabaseHas('users', [
            'email' => 'injected@example.com',
            'role' => User::ROLE_USER,
        ]);
        $this->assertDatabaseMissing('users', [
            'email' => 'injected@example.com',
            'role' => User::ROLE_ADMIN,
        ]);
    }

    public function test_admin_can_deactivate_a_regular_user_and_revoke_all_tokens(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        $firstToken = $user->createToken('first-token');
        $secondToken = $user->createToken('second-token');
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$user->id}/deactivate", [
            'blocked_reason' => 'Policy violation',
        ])
            ->assertOk()
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.is_active', false)
            ->assertJsonMissingPath('user.blocked_reason');

        $user->refresh();

        $this->assertFalse($user->is_active);
        $this->assertNotNull($user->blocked_at);
        $this->assertSame('Policy violation', $user->blocked_reason);
        $this->assertSame($admin->id, $user->blocked_by);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $firstToken->accessToken->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $secondToken->accessToken->id]);
    }

    public function test_admin_cannot_deactivate_their_own_account(): void
    {
        $admin = $this->createAdmin();
        $this->createAdmin();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$admin->id}/deactivate")
            ->assertUnprocessable();

        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_admin_cannot_deactivate_the_last_active_admin(): void
    {
        $admin = $this->createAdmin();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$admin->id}/deactivate")
            ->assertUnprocessable();

        $this->assertTrue($admin->fresh()->is_active);
        $this->assertSame(User::ROLE_ADMIN, $admin->fresh()->role);
    }

    public function test_admin_can_reactivate_a_user_without_changing_role_or_issuing_tokens(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        $user->forceFill([
            'is_active' => false,
            'blocked_at' => now(),
            'blocked_reason' => 'Temporary block',
            'blocked_by' => $admin->id,
        ])->save();
        Sanctum::actingAs($admin);

        $this->patchJson("/api/admin/users/{$user->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('user.is_active', true)
            ->assertJsonPath('user.role', User::ROLE_USER);

        $user->refresh();

        $this->assertTrue($user->is_active);
        $this->assertNull($user->blocked_at);
        $this->assertNull($user->blocked_reason);
        $this->assertNull($user->blocked_by);
        $this->assertSame(User::ROLE_USER, $user->role);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_user_facing_audit_endpoints_remain_scoped_to_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownAudit = $this->createAuditFor($user, Audit::STATUS_COMPLETED);
        $otherAudit = $this->createAuditFor($otherUser, Audit::STATUS_COMPLETED);
        Sanctum::actingAs($user);

        $this->getJson('/api/audits')
            ->assertOk()
            ->assertJsonCount(1, 'audits')
            ->assertJsonPath('audits.0.id', $ownAudit->id)
            ->assertJsonMissing(['id' => $otherAudit->id]);

        $this->getJson("/api/audits/{$otherAudit->id}")
            ->assertNotFound();
    }

    /**
     * @return array<int, array{string, string, array<string, mixed>}>
     */
    private function adminRequests(User $target): array
    {
        return [
            ['GET', '/api/admin/users', []],
            ['POST', '/api/admin/users', [
                'name' => 'New User',
                'email' => 'new@example.com',
                'password' => 'Password1',
            ]],
            ['PATCH', "/api/admin/users/{$target->id}/deactivate", []],
            ['PATCH', "/api/admin/users/{$target->id}/reactivate", []],
        ];
    }

    private function createAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->role = User::ROLE_ADMIN;
        $admin->save();

        return $admin;
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
