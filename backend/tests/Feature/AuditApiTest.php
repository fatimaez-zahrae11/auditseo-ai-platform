<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AuditApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_users_cannot_access_audit_routes(): void
    {
        $this->postJson('/api/audits', ['url' => 'https://example.com'])->assertUnauthorized();
        $this->getJson('/api/audits')->assertUnauthorized();
        $this->getJson('/api/audits/1')->assertUnauthorized();
    }

    public function test_an_authenticated_user_can_create_an_audit(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->postJson('/api/audits', [
            'url' => 'https://example.com/page',
        ]);

        $response
            ->assertCreated()
            ->assertJsonPath('audit.domain.user_id', $user->id)
            ->assertJsonPath('audit.domain.domain_name', 'example.com')
            ->assertJsonPath('audit.global_score', 0);

        $this->assertDatabaseHas('domains', [
            'user_id' => $user->id,
            'domain_name' => 'example.com',
        ]);
        $this->assertDatabaseHas('audits', [
            'domain_id' => $response->json('audit.domain_id'),
            'global_score' => 0,
        ]);
    }

    public function test_creating_an_audit_reuses_the_users_existing_domain(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/audits', ['url' => 'https://example.com/first'])->assertCreated();
        $this->postJson('/api/audits', ['url' => 'https://example.com/second'])->assertCreated();

        $this->assertDatabaseCount('domains', 1);
        $this->assertDatabaseCount('audits', 2);
    }

    public function test_an_authenticated_user_can_list_only_their_audits(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownAudit = $this->createAuditFor($user, 'own.example.com');
        $otherAudit = $this->createAuditFor($otherUser, 'other.example.com');
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/audits');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'audits')
            ->assertJsonPath('audits.0.id', $ownAudit->id)
            ->assertJsonMissing(['id' => $otherAudit->id]);
    }

    public function test_an_authenticated_user_can_view_their_own_audit(): void
    {
        $user = User::factory()->create();
        $audit = $this->createAuditFor($user, 'own.example.com');
        Sanctum::actingAs($user);

        $this->getJson("/api/audits/{$audit->id}")
            ->assertOk()
            ->assertJsonPath('audit.id', $audit->id)
            ->assertJsonPath('audit.domain.user_id', $user->id);
    }

    public function test_an_authenticated_user_cannot_view_another_users_audit(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherAudit = $this->createAuditFor($otherUser, 'other.example.com');
        Sanctum::actingAs($user);

        $this->getJson("/api/audits/{$otherAudit->id}")->assertNotFound();
    }

    private function createAuditFor(User $user, string $domainName): Audit
    {
        $domain = Domain::create([
            'user_id' => $user->id,
            'domain_name' => $domainName,
            'url' => "https://{$domainName}",
        ]);

        return $domain->audits()->create([
            'global_score' => 0,
            'technical_score' => 0,
            'content_score' => 0,
            'links_score' => 0,
            'performance_score' => 0,
            'raw_data' => null,
        ]);
    }
}
