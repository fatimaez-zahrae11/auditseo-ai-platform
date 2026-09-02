<?php

namespace App\Exceptions;

use RuntimeException;

final class CrawlUnavailableException extends RuntimeException
{
    public const PUBLIC_MESSAGE = 'This website could not be analyzed because it blocked or refused automated crawling. Please try another public website.';

    public function __construct()
    {
        parent::__construct('Website crawl failed.');
    }
}
