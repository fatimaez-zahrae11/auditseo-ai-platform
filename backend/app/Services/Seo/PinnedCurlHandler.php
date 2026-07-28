<?php

namespace App\Services\Seo;

use App\Security\CurlTransportCapabilities;
use Closure;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

final class PinnedCurlHandler
{
    private readonly CurlHandler $handler;

    /**
     * @param  Closure(ResponseInterface): void  $onHeaders
     */
    public function __construct(
        CurlTransportCapabilities $capabilities,
        private readonly int $maxBytes,
        private readonly Closure $onHeaders,
    ) {
        if (! $capabilities->curlIsAvailable()) {
            throw new RuntimeException('The secure HTTP transport is unavailable.');
        }

        $this->handler = new CurlHandler;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function __invoke(RequestInterface $request, array $options): PromiseInterface
    {
        unset($options['stream']);

        $existingOnHeaders = $options['on_headers'] ?? null;
        $onHeaders = $this->onHeaders;

        $options['sink'] = new BoundedResponseStream($this->maxBytes);
        $options['on_headers'] = static function (ResponseInterface $response) use (
            $existingOnHeaders,
            $onHeaders,
        ): void {
            if (is_callable($existingOnHeaders)) {
                $existingOnHeaders($response);
            }

            $onHeaders($response);
        };

        return ($this->handler)($request, $options);
    }
}
