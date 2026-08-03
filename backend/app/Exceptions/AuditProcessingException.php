<?php

namespace App\Exceptions;

use RuntimeException;

final class AuditProcessingException extends RuntimeException
{
    public const MESSAGE = 'Audit processing failed.';

    public function __construct(
        private readonly bool $validationFailure = false,
    ) {
        parent::__construct(self::MESSAGE);
    }

    public function isValidationFailure(): bool
    {
        return $this->validationFailure;
    }
}
