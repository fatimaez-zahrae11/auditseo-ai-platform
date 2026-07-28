<?php

namespace App\Services\Ai;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use InvalidArgumentException;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

final class BoundedAiResponseStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private StreamInterface $stream;

    private int $writtenBytes = 0;

    public function __construct(private readonly int $maxBytes)
    {
        if ($maxBytes < 1) {
            throw new InvalidArgumentException('The AI response size limit must be positive.');
        }

        $resource = fopen("php://temp/maxmemory:{$maxBytes}", 'w+b');
        if ($resource === false) {
            throw new RuntimeException('Unable to create the bounded AI response stream.');
        }

        $this->stream = Utils::streamFor($resource);
    }

    public function write($string): int
    {
        $bytes = strlen($string);

        if ($this->writtenBytes + $bytes > $this->maxBytes) {
            throw new RuntimeException('The AI response exceeded the size limit.');
        }

        $written = $this->stream->write($string);
        $this->writtenBytes += $written;

        return $written;
    }
}
