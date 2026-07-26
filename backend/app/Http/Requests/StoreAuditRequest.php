<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\HttpFoundation\IpUtils;

class StoreAuditRequest extends FormRequest
{
    /**
     * PHP's reserved-range filter does not cover every special-use range.
     *
     * @var list<string>
     */
    private const ADDITIONAL_NON_PUBLIC_RANGES = [
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '::/96',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/32',
        '2002::/16',
    ];

    /**
     * @var list<string>
     */
    private const INTERNAL_HOST_SUFFIXES = [
        '.home',
        '.internal',
        '.lan',
        '.local',
        '.localhost',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'url' => [
                'bail',
                'required',
                'url',
                'max:2048',
                'regex:/^https?:\/\//i',
                $this->publicHttpUrlRule(),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'url.required' => 'The website URL is required.',
            'url.url' => 'The website URL is not valid.',
            'url.max' => 'The website URL may not be longer than 2048 characters.',
            'url.regex' => 'The website URL must begin with http:// or https://.',
        ];
    }

    private function publicHttpUrlRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $parts = parse_url((string) $value);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = strtolower(rtrim(trim((string) ($parts['host'] ?? ''), '[]'), '.'));
            $port = $parts['port'] ?? null;

            if (! in_array($scheme, ['http', 'https'], true)
                || $host === ''
                || ! $this->usesStandardPort($scheme, $port)
                || $this->isInternalHostname($host)) {
                $fail($this->unsafeUrlMessage());

                return;
            }

            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                if (! $this->isPublicIp($host)) {
                    $fail($this->unsafeUrlMessage());
                }

                return;
            }

            $resolvedAddresses = $this->resolveHostAddresses($host);

            if ($resolvedAddresses === []) {
                $fail($this->unsafeUrlMessage());

                return;
            }

            foreach ($resolvedAddresses as $address) {
                if (! $this->isPublicIp($address)) {
                    $fail($this->unsafeUrlMessage());

                    return;
                }
            }
        };
    }

    private function usesStandardPort(string $scheme, mixed $port): bool
    {
        return $port === null
            || ($scheme === 'http' && $port === 80)
            || ($scheme === 'https' && $port === 443);
    }

    private function isInternalHostname(string $host): bool
    {
        if ($host === 'localhost' || ! str_contains($host, '.')) {
            return true;
        }

        foreach (self::INTERNAL_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function resolveHostAddresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        if ($records === false) {
            return [];
        }

        $addresses = [];

        foreach ($records as $record) {
            if (isset($record['ip'])) {
                $addresses[] = $record['ip'];
            }

            if (isset($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }

    private function isPublicIp(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false
            && ! IpUtils::checkIp($address, self::ADDITIONAL_NON_PUBLIC_RANGES);
    }

    private function unsafeUrlMessage(): string
    {
        return 'The URL must point to a public website using HTTP or HTTPS on its standard port.';
    }
}
