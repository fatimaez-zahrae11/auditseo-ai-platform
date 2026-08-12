<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_are_regular_users_by_default_and_role_is_not_mass_assignable(): void
    {
        $user = User::factory()->create();

        $this->assertSame(User::ROLE_USER, $user->role);
        $this->assertFalse($user->isAdmin());
        $this->assertFalse($user->isFillable('role'));

        $user->fill([
            'name' => 'Updated Name',
            'role' => User::ROLE_ADMIN,
        ])->save();

        $this->assertSame('Updated Name', $user->fresh()->name);
        $this->assertSame(User::ROLE_USER, $user->fresh()->role);
        $this->assertFalse($user->fresh()->isAdmin());
    }

    public function test_registration_cannot_set_the_new_users_role(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'Password1',
            'role' => User::ROLE_ADMIN,
        ])->assertCreated();

        $user = User::where('email', 'test@example.com')->sole();

        $this->assertSame(User::ROLE_USER, $user->role);
        $this->assertFalse($user->isAdmin());
    }

    public function test_database_rejects_roles_outside_the_allowed_values(): void
    {
        $user = User::factory()->create();

        $this->expectException(QueryException::class);

        $user->forceFill(['role' => 'superadmin'])->save();
    }

    public function test_make_admin_promotes_an_existing_user(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
        ]);

        $this->artisan('make:admin', ['email' => ' ADMIN@Example.COM '])
            ->expectsOutput('User [admin@example.com] is now an admin.')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(User::ROLE_ADMIN, $user->fresh()->role);
        $this->assertTrue($user->fresh()->isAdmin());
    }

    public function test_make_admin_fails_for_an_unknown_email_without_creating_a_user(): void
    {
        $this->artisan('make:admin', ['email' => 'missing@example.com'])
            ->expectsOutput('No user exists with email [missing@example.com].')
            ->assertExitCode(Command::FAILURE);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_make_admin_is_idempotent_for_an_existing_admin(): void
    {
        $user = User::factory()->create();
        $user->role = User::ROLE_ADMIN;
        $user->save();

        $this->artisan('make:admin', ['email' => $user->email])
            ->expectsOutput("User [{$user->email}] is already an admin.")
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(User::ROLE_ADMIN, $user->fresh()->role);
        $this->assertTrue($user->fresh()->isAdmin());
        $this->assertDatabaseCount('users', 1);
    }
}
