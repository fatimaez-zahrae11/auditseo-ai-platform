<?php

namespace App\Exceptions;

use RuntimeException;

final class AuditProcessingException extends RuntimeException
{
    public const MESSAGE = 'Audit processing failed.';

    public function __construct(
        private readonly bool $validationFailure = false,
        private readonly string $failureReason = self::MESSAGE,
    ) {
        parent::__construct(self::MESSAGE);
    }

    public function isValidationFailure(): bool
    {
        return $this->validationFailure;
    }

    public function failureReason(): string
    {
        return $this->failureReason === CrawlUnavailableException::PUBLIC_MESSAGE
            ? CrawlUnavailableException::PUBLIC_MESSAGE
            : self::MESSAGE;
    }
}
