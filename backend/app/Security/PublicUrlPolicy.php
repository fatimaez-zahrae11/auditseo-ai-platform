<?php

namespace App\Security;

use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

final class PublicUrlPolicy
{
    public const VALIDATION_MESSAGE = 'The URL must point to a public website using HTTP or HTTPS on its standard port.';

    /**
     * @var list<string>
     */
    private const BLOCKED_NETWORKS = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '100.64.0.0/10',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '192.88.99.0/24',
        '192.168.0.0/16',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '255.255.255.255/32',
        '::/128',
        '::1/128',
        '::ffff:0:0/96',
        '64:ff9b::/96',
        '64:ff9b:1::/48',
        '100::/64',
        '2001::/32',
        '2001:db8::/32',
        '2002::/16',
        'fc00::/7',
        'fe80::/10',
        'ff00::/8',
    ];

    public function __construct(private readonly DnsResolver $dnsResolver) {}

    /**
     * @return array{
     *     host: string,
     *     port: int,
     *     addresses: list<string>,
     *     is_ip_literal: bool
     * }
     *
     * @throws ValidationException
     */
    public function validate(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            $this->reject();
        }

        $scheme = strtolower((string) $parts['scheme']);
        $connectionHost = strtolower(trim((string) $parts['host'], '[]'));
        $hostname = rtrim($connectionHost, '.');

        if (
            ! in_array($scheme, ['http', 'https'], true)
            || $hostname === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || $this->isInternalHostname($hostname)
        ) {
            $this->reject();
        }

        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : 80);

        if (
            ! in_array($port, [80, 443], true)
            || ($scheme === 'http' && $port !== 80)
            || ($scheme === 'https' && $port !== 443)
        ) {
            $this->reject();
        }

        $isIpLiteral = filter_var($hostname, FILTER_VALIDATE_IP) !== false;
        $addresses = $isIpLiteral
            ? [$hostname]
            : $this->dnsResolver->resolve($hostname);

        if ($addresses === []) {
            $this->reject();
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicIp($address)) {
                $this->reject();
            }
        }

        return [
            'host' => $connectionHost,
            'port' => $port,
            'addresses' => array_values(array_unique($addresses)),
            'is_ip_literal' => $isIpLiteral,
        ];
    }

    /**
     * @param  array{
     *     host: string,
     *     port: int,
     *     addresses: list<string>,
     *     is_ip_literal: bool
     * }  $target
     * @return array<string, mixed>
     */
    public function connectionOptions(array $target): array
    {
        if ($target['is_ip_literal'] || ! defined('CURLOPT_RESOLVE')) {
            return [];
        }

        $address = $target['addresses'][0];
        $curlAddress = str_contains($address, ':') ? '['.$address.']' : $address;

        return [
            'curl' => [
                constant('CURLOPT_RESOLVE') => [
                    sprintf('%s:%d:%s', $target['host'], $target['port'], $curlAddress),
                ],
            ],
        ];
    }

    private function isInternalHostname(string $hostname): bool
    {
        if ($hostname === 'localhost' || ! str_contains($hostname, '.')) {
            return true;
        }

        foreach (['.home', '.internal', '.lan', '.local', '.localhost'] as $suffix) {
            if (str_ends_with($hostname, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private function isPublicIp(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false) {
            return false;
        }

        return ! IpUtils::checkIp($address, self::BLOCKED_NETWORKS);
    }

    /**
     * @throws ValidationException
     */
    private function reject(): never
    {
        throw ValidationException::withMessages([
            'url' => [self::VALIDATION_MESSAGE],
        ]);
    }
}
