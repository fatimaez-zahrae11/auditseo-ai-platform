<?php

namespace App\Providers;

class ResendServiceProvider extends \Resend\Laravel\ResendServiceProvider
{
    /**
     * Inbound Resend webhooks are not used by this application. Keep the
     * package's API client and outbound mail transport without exposing its
     * otherwise unsigned-by-default public webhook endpoint.
     */
    protected function registerRoutes(): void
    {
        // Intentionally disabled.
    }
}
