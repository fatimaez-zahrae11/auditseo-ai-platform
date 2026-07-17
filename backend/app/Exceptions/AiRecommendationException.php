<?php

namespace App\Exceptions;

use RuntimeException;

class AiRecommendationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The AI recommendation service is unavailable.');
    }
}
