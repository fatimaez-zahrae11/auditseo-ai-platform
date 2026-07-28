<?php

namespace App\Security;

final class CurlTransportCapabilities
{
    public function __construct(
        private readonly ?bool $curlAvailable = null,
        private readonly ?bool $dnsPinningAvailable = null,
    ) {}

    public function curlIsAvailable(): bool
    {
        return $this->curlAvailable
            ?? (extension_loaded('curl')
                && function_exists('curl_init')
                && function_exists('curl_exec'));
    }

    public function dnsPinningIsAvailable(): bool
    {
        return $this->curlIsAvailable()
            && ($this->dnsPinningAvailable ?? defined('CURLOPT_RESOLVE'));
    }
}
