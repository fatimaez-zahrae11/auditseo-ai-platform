<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminAuditApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_user_cannot_access_admin_audits(): void
    {
        $this->getJson('/api/admin/audits')
            ->assertUnauthorized()
            ->assertExactJson([
                'message' => 'Unauthenticated.',
            ]);
    }

    public function test_authenticated_non_admin_user_cannot_access_admin_audits(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/audits')
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Forbidden',
            ]);
    }

    public function test_inactive_admin_cannot_access_admin_audits(): void
    {
        $admin = $this->createAdmin();
        $admin->is_active = false;
        $admin->save();
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/audits')
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Account disabled',
            ]);
    }

    public function test_admin_sees_audits_from_multiple_users_with_owner_data_and_safe_fields(): void
    {
        $admin = $this->createAdmin();
        $firstUser = User::factory()->create(['email' => 'first@example.com']);
        $secondUser = User::factory()->create(['email' => 'second@example.com']);
        $firstAudit = $this->createAuditFor($firstUser, Audit::STATUS_COMPLETED);
        $secondAudit = $this->createAuditFor($secondUser, Audit::STATUS_FAILED, [
            'failure_reason' => 'Sensitive internal failure detail',
        ]);
        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/admin/audits');

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
            ->assertJsonMissingPath('audits.0.failure_reason')
            ->assertJsonMissingPath('audits.0.raw_data');

        $this->assertEqualsCanonicalizing(
            [$firstAudit->id, $secondAudit->id],
            collect($response->json('audits'))->pluck('id')->all(),
        );

        $listedAudit = collect($response->json('audits'))->firstWhere('id', $firstAudit->id);

        $this->assertSame($firstUser->id, $listedAudit['user']['id']);
        $this->assertSame($firstUser->email, $listedAudit['user']['email']);
        $this->assertEqualsCanonicalizing([
            'id',
            'status',
            'requested_url',
            'final_url',
            'global_score',
            'technical_score',
            'content_score',
            'links_score',
            'performance_score',
            'created_at',
            'updated_at',
            'completed_at',
            'failed_at',
            'domain',
            'user',
        ], array_keys($listedAudit));
    }

    public function test_status_filter_works(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        $completedAudit = $this->createAuditFor($user, Audit::STATUS_COMPLETED);
        $this->createAuditFor($user, Audit::STATUS_FAILED);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/audits?status=completed')
            ->assertOk()
            ->assertJsonCount(1, 'audits')
            ->assertJsonPath('audits.0.id', $completedAudit->id)
            ->assertJsonPath('audits.0.status', Audit::STATUS_COMPLETED);

        $this->getJson('/api/admin/audits?status=invalid')
            ->assertUnprocessable();
    }

    public function test_user_id_filter_works(): void
    {
        $admin = $this->createAdmin();
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $firstAudit = $this->createAuditFor($firstUser, Audit::STATUS_PENDING);
        $this->createAuditFor($secondUser, Audit::STATUS_PENDING);
        Sanctum::actingAs($admin);

        $this->getJson("/api/admin/audits?user_id={$firstUser->id}")
            ->assertOk()
            ->assertJsonCount(1, 'audits')
            ->assertJsonPath('audits.0.id', $firstAudit->id)
            ->assertJsonPath('audits.0.user.id', $firstUser->id);
    }

    public function test_created_from_and_created_to_filters_are_inclusive(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        $oldAudit = $this->createAuditFor($user, Audit::STATUS_COMPLETED, [], '2026-01-01 12:00:00');
        $includedAudit = $this->createAuditFor($user, Audit::STATUS_COMPLETED, [], '2026-01-15 12:00:00');
        $newAudit = $this->createAuditFor($user, Audit::STATUS_COMPLETED, [], '2026-02-01 12:00:00');
        Sanctum::actingAs($admin);

        $response = $this->getJson(
            '/api/admin/audits?created_from=2026-01-15&created_to=2026-01-15',
        );

        $response
            ->assertOk()
            ->assertJsonCount(1, 'audits')
            ->assertJsonPath('audits.0.id', $includedAudit->id)
            ->assertJsonMissing(['id' => $oldAudit->id])
            ->assertJsonMissing(['id' => $newAudit->id]);
    }

    public function test_domain_and_url_search_works(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        $domainAudit = $this->createAuditFor($user, Audit::STATUS_PENDING, [
            'domain_name' => 'unique-domain.example',
            'domain_url' => 'https://unique-domain.example',
            'requested_url' => 'https://unique-domain.example/home',
        ]);
        $urlAudit = $this->createAuditFor($user, Audit::STATUS_RUNNING, [
            'domain_name' => 'another.example',
            'domain_url' => 'https://another.example',
            'requested_url' => 'https://another.example/special-path',
        ]);
        $this->createAuditFor($user, Audit::STATUS_FAILED, [
            'domain_name' => 'unrelated.example',
            'domain_url' => 'https://unrelated.example',
            'requested_url' => 'https://unrelated.example/page',
        ]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/audits?search=UNIQUE-DOMAIN')
            ->assertOk()
            ->assertJsonCount(1, 'audits')
            ->assertJsonPath('audits.0.id', $domainAudit->id);

        $this->getJson('/api/admin/audits?search=special-path')
            ->assertOk()
            ->assertJsonCount(1, 'audits')
            ->assertJsonPath('audits.0.id', $urlAudit->id);
    }

    public function test_admin_audits_are_paginated_with_a_safe_per_page_maximum(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();

        foreach (range(1, 5) as $index) {
            $this->createAuditFor($user, Audit::STATUS_PENDING, [
                'domain_name' => "pagination-{$index}.example",
            ]);
        }

        Sanctum::actingAs($admin);

        $this->getJson('/api/admin/audits?per_page=2&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'audits')
            ->assertJsonPath('pagination.current_page', 2)
            ->assertJsonPath('pagination.per_page', 2)
            ->assertJsonPath('pagination.total', 5)
            ->assertJsonPath('pagination.last_page', 3);

        $this->getJson('/api/admin/audits?per_page=500')
            ->assertOk()
            ->assertJsonPath('pagination.per_page', 100);
    }

    public function test_admin_audit_list_query_count_does_not_grow_with_results(): void
    {
        $admin = $this->createAdmin();
        $user = User::factory()->create();
        $this->createAuditFor($user, Audit::STATUS_PENDING);
        Sanctum::actingAs($admin);

        $capturing = false;
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$capturing, &$queries): void {
            if ($capturing) {
                $queries[] = $query->sql;
            }
        });

        $capturing = true;
        $this->getJson('/api/admin/audits')->assertOk();
        $capturing = false;
        $singleAuditQueryCount = count($queries);

        foreach (range(1, 10) as $index) {
            $owner = User::factory()->create();
            $this->createAuditFor($owner, Audit::STATUS_PENDING, [
                'domain_name' => "query-count-{$index}.example",
            ]);
        }

        $queries = [];
        $capturing = true;
        $this->getJson('/api/admin/audits')->assertOk();
        $capturing = false;

        $this->assertSame($singleAuditQueryCount, count($queries));
        $this->assertLessThanOrEqual(5, count($queries));
    }

    public function test_user_facing_audit_endpoints_remain_scoped_to_authenticated_user(): void
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
    private function createAuditFor(
        User $user,
        string $status,
        array $attributes = [],
        ?string $createdAt = null,
    ): Audit {
        $domainName = $attributes['domain_name'] ?? fake()->unique()->domainName();
        $domainUrl = $attributes['domain_url'] ?? "https://{$domainName}";
        $domain = Domain::create([
            'user_id' => $user->id,
            'domain_name' => $domainName,
            'url' => $domainUrl,
        ]);
        $audit = $domain->audits()->create([
            'status' => $status,
            'requested_url' => $attributes['requested_url'] ?? $domainUrl,
            'final_url' => $attributes['final_url'] ?? null,
            'global_score' => $attributes['global_score'] ?? 80,
            'technical_score' => $attributes['technical_score'] ?? 81,
            'content_score' => $attributes['content_score'] ?? 82,
            'links_score' => $attributes['links_score'] ?? 83,
            'performance_score' => $attributes['performance_score'] ?? 84,
            'completed_at' => $status === Audit::STATUS_COMPLETED ? now() : null,
            'failed_at' => $status === Audit::STATUS_FAILED ? now() : null,
            'failure_reason' => $attributes['failure_reason'] ?? null,
        ]);

        if ($createdAt !== null) {
            $audit->forceFill([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ])->save();
        }

        return $audit->refresh();
    }
}
