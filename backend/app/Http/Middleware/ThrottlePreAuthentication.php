<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * A distinct middleware class keeps this IP ceiling ahead of Laravel's
 * authentication-prioritized route middleware. The named limiter behavior is
 * inherited unchanged from the framework implementation.
 */
class ThrottlePreAuthentication extends ThrottleRequests
{
}
