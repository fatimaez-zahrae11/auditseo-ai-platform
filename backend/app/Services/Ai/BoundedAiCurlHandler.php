<?php

namespace App\Services\Ai;

use Closure;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\Promise\PromiseInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

final class BoundedAiCurlHandler
{
    private readonly CurlHandler $handler;

    /**
     * @param  Closure(ResponseInterface): void  $onHeaders
     */
    public function __construct(
        private readonly int $maxBytes,
        private readonly Closure $onHeaders,
    ) {
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

        $options['sink'] = new BoundedAiResponseStream($this->maxBytes);
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
