<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

class EmailBrandingTest extends TestCase
{
    use RefreshDatabase;

    public function test_application_and_sender_defaults_use_full_auditseo_branding(): void
    {
        $this->assertSame('AuditSEO AI Platform', config('app.name'));
        $this->assertSame('AuditSEO', config('app.short_name'));
        $this->assertSame('AuditSEO AI Platform', config('mail.from.name'));
        $this->assertNotSame('hello@example.com', config('mail.from.address'));
    }

    public function test_verification_email_is_branded_and_keeps_a_valid_signed_url(): void
    {
        $this->configureBranding();
        $user = User::factory()->unverified()->create();
        $message = (new VerifyEmail)->toMail($user);

        $this->assertInstanceOf(MailMessage::class, $message);
        $this->assertSame('Verify your AuditSEO account', $message->subject);
        $this->assertSame('Verify Email Address', $message->actionText);
        $this->assertSame(['no-reply@example.com', 'AuditSEO AI Platform'], $message->from);

        [$html, $text] = $this->renderMessage($message);
        $this->assertBrandedContent($html, $text);
        $this->assertStringContainsString('Thank you for creating an AuditSEO account.', $text);
        $this->assertStringContainsString('This verification link will expire in 60 minutes.', $text);
        $this->assertStringContainsString($message->actionUrl, $text);

        $this->getJson($message->actionUrl)
            ->assertOk()
            ->assertJsonPath('message', 'Email verified successfully. You may now log in.');
        $this->assertTrue($user->fresh()->hasVerifiedEmail());
    }

    public function test_password_reset_email_is_branded_and_keeps_encoded_frontend_url(): void
    {
        $this->configureBranding();
        config(['services.frontend.url' => 'https://auditseo.example']);
        $user = User::factory()->create(['email' => 'person+reset@example.com']);
        $token = 'safe-test-reset-token';
        $message = (new ResetPassword($token))->toMail($user);
        $query = [];
        parse_str((string) parse_url($message->actionUrl, PHP_URL_QUERY), $query);

        $this->assertInstanceOf(MailMessage::class, $message);
        $this->assertSame('Reset your AuditSEO password', $message->subject);
        $this->assertSame('Reset Password', $message->actionText);
        $this->assertSame(['no-reply@example.com', 'AuditSEO AI Platform'], $message->from);
        $this->assertStringStartsWith('https://auditseo.example/reset-password?', $message->actionUrl);
        $this->assertStringContainsString('email=person%2Breset%40example.com', $message->actionUrl);
        $this->assertSame($token, $query['token'] ?? null);
        $this->assertSame($user->email, $query['email'] ?? null);
        $this->assertStringNotContainsString('Bearer', $message->actionUrl);

        [$html, $text] = $this->renderMessage($message);
        $this->assertBrandedContent($html, $text);
        $this->assertStringContainsString('This password reset link will expire in 60 minutes.', $text);
        $this->assertStringContainsString($message->actionUrl, $text);
    }

    private function configureBranding(): void
    {
        config([
            'app.name' => 'AuditSEO AI Platform',
            'app.short_name' => 'AuditSEO',
            'auth.verification.expire' => 60,
            'mail.from.address' => 'no-reply@example.com',
            'mail.from.name' => 'AuditSEO AI Platform',
        ]);
    }

    /**
     * @return array{string, string}
     */
    private function renderMessage(MailMessage $message): array
    {
        $markdown = app(Markdown::class);
        $theme = $message->theme ?: (string) config('mail.markdown.theme', 'default');
        $html = (string) $markdown->theme($theme)->render($message->markdown, $message->data());
        $text = (string) $markdown->theme($theme)->renderText($message->markdown, $message->data());

        return [$html, $text];
    }

    private function assertBrandedContent(string $html, string $text): void
    {
        $rendered = $html."\n".$text;

        $this->assertStringContainsString('AuditSEO AI Platform', $rendered);
        $this->assertStringContainsString('All rights reserved.', $rendered);
        $this->assertStringNotContainsString('laravel', strtolower($rendered));
        $this->assertStringNotContainsString('Laravel Logo', $rendered);
        $this->assertStringNotContainsString('hello@example.com', strtolower($rendered));
        $this->assertStringNotContainsString('<script', strtolower($html));
        $this->assertDoesNotMatchRegularExpression('/<img\b/i', $html);
    }
}
