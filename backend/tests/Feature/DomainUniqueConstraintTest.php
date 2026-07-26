<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainUniqueConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_same_user_cannot_have_duplicate_domain_names(): void
    {
        $user = User::factory()->create();

        Domain::create([
            'user_id' => $user->id,
            'domain_name' => 'example.com',
            'url' => 'https://example.com',
        ]);

        $this->expectException(QueryException::class);

        Domain::create([
            'user_id' => $user->id,
            'domain_name' => 'example.com',
            'url' => 'https://example.com/duplicate',
        ]);
    }

    public function test_different_users_can_have_the_same_domain_name(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        Domain::create([
            'user_id' => $firstUser->id,
            'domain_name' => 'example.com',
            'url' => 'https://example.com',
        ]);

        Domain::create([
            'user_id' => $secondUser->id,
            'domain_name' => 'example.com',
            'url' => 'https://example.com',
        ]);

        $this->assertDatabaseCount('domains', 2);
        $this->assertDatabaseHas('domains', [
            'user_id' => $firstUser->id,
            'domain_name' => 'example.com',
        ]);
        $this->assertDatabaseHas('domains', [
            'user_id' => $secondUser->id,
            'domain_name' => 'example.com',
        ]);
    }
}
