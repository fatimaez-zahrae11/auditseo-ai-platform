<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset as PasswordResetEvent;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const FORGOT_PASSWORD_MESSAGE = 'If an account exists for this email, a password reset link has been sent.';

    private const INVALID_RESET_LINK_MESSAGE = 'This password reset link is invalid or has expired.';

    public function test_forgot_password_returns_generic_response_for_existing_email_and_sends_frontend_link(): void
    {
        Notification::fake();
        config(['services.frontend.url' => 'https://auditseo.example']);
        $user = User::factory()->create(['email' => 'person@example.com']);

        $response = $this->postJson('/api/forgot-password', [
            'email' => ' PERSON@EXAMPLE.COM ',
        ]);

        $response->assertOk()->assertExactJson([
            'message' => self::FORGOT_PASSWORD_MESSAGE,
        ]);
        Notification::assertSentTo(
            $user,
            ResetPasswordNotification::class,
            function (ResetPasswordNotification $notification) use ($user): bool {
                $url = $notification->toMail($user)->actionUrl;
                $query = [];
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

                $this->assertStringStartsWith('https://auditseo.example/reset-password?', $url);
                $this->assertSame($user->email, $query['email'] ?? null);
                $this->assertNotSame('', $query['token'] ?? '');
                $this->assertStringNotContainsString('Bearer', $url);

                return true;
            },
        );
    }

    public function test_forgot_password_returns_same_generic_response_for_unknown_email(): void
    {
        Notification::fake();

        $this->postJson('/api/forgot-password', [
            'email' => 'unknown@example.com',
        ])->assertOk()->assertExactJson([
            'message' => self::FORGOT_PASSWORD_MESSAGE,
        ]);

        Notification::assertNothingSent();
    }

    public function test_password_can_be_reset_and_existing_tokens_are_revoked_without_changing_role(): void
    {
        Event::fake([PasswordResetEvent::class]);
        $user = User::factory()->create([
            'password' => Hash::make('OldPassword1'),
            'remember_token' => 'old-remember-token',
            'role' => User::ROLE_ADMIN,
        ]);
        $user->createToken('first-token');
        $user->createToken('second-token');
        $token = Password::broker()->createToken($user);

        $response = $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewPassword2',
            'password_confirmation' => 'NewPassword2',
        ]);

        $response->assertOk()->assertExactJson([
            'message' => 'Your password has been reset successfully. You may now sign in.',
        ]);
        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword2', $user->password));
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertSame(User::ROLE_ADMIN, $user->role);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Event::assertDispatched(PasswordResetEvent::class, fn (PasswordResetEvent $event): bool => $event->user->is($user));
    }

    public function test_invalid_reset_token_fails_with_safe_generic_response(): void
    {
        $user = User::factory()->create();
        $submittedToken = 'invalid-sensitive-reset-token';

        $response = $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $submittedToken,
            'password' => 'NewPassword2',
            'password_confirmation' => 'NewPassword2',
        ]);

        $response->assertUnprocessable()->assertExactJson([
            'message' => self::INVALID_RESET_LINK_MESSAGE,
        ]);
        $this->assertStringNotContainsString($submittedToken, $response->getContent());
        $this->assertStringNotContainsString('vendor', strtolower($response->getContent()));
        $this->assertStringNotContainsString('exception', strtolower($response->getContent()));
    }

    public function test_expired_reset_token_fails_with_safe_generic_response(): void
    {
        $user = User::factory()->create();
        $token = Password::broker()->createToken($user);
        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(61)]);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewPassword2',
            'password_confirmation' => 'NewPassword2',
        ])->assertUnprocessable()->assertExactJson([
            'message' => self::INVALID_RESET_LINK_MESSAGE,
        ]);
    }

    public function test_resetting_password_does_not_authenticate_an_inactive_user(): void
    {
        $user = User::factory()->create([
            'email' => 'inactive@example.com',
            'email_verified_at' => now(),
            'is_active' => false,
            'password' => Hash::make('OldPassword1'),
        ]);
        $token = Password::broker()->createToken($user);

        $this->postJson('/api/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewPassword2',
            'password_confirmation' => 'NewPassword2',
        ])->assertOk()->assertJsonMissingPath('token')->assertJsonMissingPath('user');

        $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'NewPassword2',
        ])->assertForbidden()->assertExactJson([
            'message' => 'Account disabled',
        ]);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }
}
