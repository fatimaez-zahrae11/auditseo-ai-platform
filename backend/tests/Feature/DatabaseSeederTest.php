<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_seeder_fails_before_creating_users_in_production(): void
    {
        $originalEnvironment = $this->app->environment();
        $exception = null;
        $this->app['env'] = 'production';

        try {
            $this->app->make(DatabaseSeeder::class)->run();
        } catch (LogicException $caught) {
            $exception = $caught;
        } finally {
            $this->app['env'] = $originalEnvironment;
        }

        $this->assertInstanceOf(LogicException::class, $exception);
        $this->assertSame(
            'The default database seeder may only run in local or testing environments.',
            $exception->getMessage(),
        );
        $this->assertDatabaseCount('users', 0);
    }

    public function test_default_seeder_preserves_the_testing_demo_user(): void
    {
        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', 'test@example.com')->sole();

        $this->assertSame('Test User', $user->name);
        $this->assertTrue($user->hasVerifiedEmail());
        $this->assertTrue(Hash::check('password', $user->password));
    }
}
