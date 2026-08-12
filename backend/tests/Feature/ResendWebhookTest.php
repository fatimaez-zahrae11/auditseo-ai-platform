<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Resend\Laravel\Transport\ResendTransportFactory;
use Tests\TestCase;

class ResendWebhookTest extends TestCase
{
    public function test_inbound_resend_webhook_is_not_registered_or_accepted(): void
    {
        $this->assertFalse(Route::has('resend.webhook'));

        $this->postJson('/resend/webhook', [
            'type' => 'email.delivered',
            'data' => ['email_id' => 'example'],
        ])->assertNotFound();
    }

    public function test_resend_outbound_mail_transport_remains_registered(): void
    {
        config([
            'services.resend.key' => 'test-resend-api-key',
            'mail.mailers.resend' => ['transport' => 'resend'],
        ]);

        Mail::purge('resend');

        $this->assertInstanceOf(
            ResendTransportFactory::class,
            Mail::mailer('resend')->getSymfonyTransport(),
        );
    }
}
