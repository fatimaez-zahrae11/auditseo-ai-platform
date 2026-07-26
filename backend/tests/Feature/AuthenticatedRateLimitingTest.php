<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticatedRateLimitingTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_routes_are_rate_limited_after_thirty_requests_per_minute(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        foreach (range(1, 30) as $attempt) {
            $this->withToken($token)
                ->getJson('/api/me')
                ->assertOk();
        }

        $this->withToken($token)
            ->getJson('/api/me')
            ->assertTooManyRequests();
    }

    public function test_audit_creation_is_rate_limited_after_ten_requests_per_hour(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test-token')->plainTextToken;

        foreach (range(1, 10) as $attempt) {
            $this->withToken($token)
                ->postJson('/api/audits', [])
                ->assertUnprocessable();
        }

        $this->withToken($token)
            ->postJson('/api/audits', [])
            ->assertTooManyRequests();
    }
}
