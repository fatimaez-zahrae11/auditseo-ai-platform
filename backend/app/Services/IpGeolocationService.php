<?php

namespace App\Services;

use App\Models\IpGeolocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class IpGeolocationService
{
    /**
     * Resolve unique addresses from the database cache first and update missing or stale
     * entries in one batch. Raw addresses are used only in memory and are never stored in
     * this cache; SHA-256 hashes are the cache keys.
     *
     * @param  list<string>  $ipAddresses
     * @return array<string, array<string, float|string|null>>
     */
    public function resolveMany(array $ipAddresses): array
    {
        $addresses = collect($ipAddresses)
            ->filter(fn (mixed $ip): bool => is_string($ip) && $ip !== '')
            ->unique()
            ->values();

        if ($addresses->isEmpty()) {
            return [];
        }

        $hashes = $addresses->mapWithKeys(
            fn (string $ip): array => [$ip => hash('sha256', $ip)],
        );
        $cached = IpGeolocation::query()
            ->whereIn('ip_hash', $hashes->values())
            ->get()
            ->keyBy('ip_hash');
        $now = CarbonImmutable::now();
        $freshAfter = $now->subDays(max(1, (int) config('ip_intelligence.cache_days', 30)));
        $reader = $this->maxMindReader();
        $resolved = [];
        $pendingRows = [];

        foreach ($hashes as $ip => $hash) {
            /** @var IpGeolocation|null $cachedLocation */
            $cachedLocation = $cached->get($hash);

            if ($cachedLocation?->resolved_at !== null
                && $cachedLocation->resolved_at->greaterThanOrEqualTo($freshAfter)) {
                $resolved[$ip] = $this->locationFromModel($cachedLocation);

                continue;
            }

            $location = $this->isPrivateOrLocal($ip)
                ? $this->localLocation($ip)
                : $this->lookupPublicAddress($ip, $reader);

            if ($location === null && $cachedLocation !== null) {
                $resolved[$ip] = $this->locationFromModel($cachedLocation);

                continue;
            }

            $location ??= $this->unknownLocation($ip);
            $resolved[$ip] = $location;
            $pendingRows[] = [
                'ip_hash' => $hash,
                ...$location,
                'resolved_at' => $now,
                'created_at' => $cachedLocation?->created_at ?? $now,
                'updated_at' => $now,
            ];
        }

        if ($pendingRows !== []) {
            IpGeolocation::query()->upsert(
                $pendingRows,
                ['ip_hash'],
                [
                    'ip_masked',
                    'country_code',
                    'country_name',
                    'region',
                    'city',
                    'latitude',
                    'longitude',
                    'timezone',
                    'isp',
                    'source',
                    'resolved_at',
                    'updated_at',
                ],
            );
        }

        if (is_object($reader) && method_exists($reader, 'close')) {
            $reader->close();
        }

        return $resolved;
    }

    /**
     * @return array<string, float|string|null>
     */
    public function resolve(string $ipAddress): array
    {
        return $this->resolveMany([$ipAddress])[$ipAddress]
            ?? $this->unknownLocation($ipAddress);
    }

    public function mask(string $ipAddress): string
    {
        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ipAddress);

            return $parts[0].'.xxx.xxx.'.$parts[3];
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = explode(':', $this->expandedIpv6($ipAddress));

            return $parts[0].':'.$parts[1].':xxxx:xxxx:xxxx:xxxx:xxxx:'.$parts[7];
        }

        return 'Unknown address';
    }

    private function isPrivateOrLocal(string $ipAddress): bool
    {
        if ($ipAddress === '::1') {
            return true;
        }

        if (filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $numeric = ip2long($ipAddress);

            return $numeric !== false && (
                ($numeric >= ip2long('10.0.0.0') && $numeric <= ip2long('10.255.255.255'))
                || ($numeric >= ip2long('127.0.0.0') && $numeric <= ip2long('127.255.255.255'))
                || ($numeric >= ip2long('169.254.0.0') && $numeric <= ip2long('169.254.255.255'))
                || ($numeric >= ip2long('172.16.0.0') && $numeric <= ip2long('172.31.255.255'))
                || ($numeric >= ip2long('192.168.0.0') && $numeric <= ip2long('192.168.255.255'))
            );
        }

        if (! filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return false;
        }

        $normalized = strtolower($this->expandedIpv6($ipAddress));

        return str_starts_with($normalized, 'fc')
            || str_starts_with($normalized, 'fd')
            || str_starts_with($normalized, 'fe8')
            || str_starts_with($normalized, 'fe9')
            || str_starts_with($normalized, 'fea')
            || str_starts_with($normalized, 'feb');
    }

    private function maxMindReader(): ?object
    {
        $path = config('ip_intelligence.maxmind_database_path');
        $readerClass = 'GeoIp2\\Database\\Reader';

        if (! is_string($path) || $path === '' || ! is_readable($path) || ! class_exists($readerClass)) {
            return null;
        }

        try {
            return new $readerClass($path);
        } catch (Throwable) {
            Log::warning('IP geolocation database could not be opened.', [
                'provider' => 'maxmind-geolite2',
            ]);

            return null;
        }
    }

    /**
     * @return array<string, float|string|null>|null
     */
    private function lookupPublicAddress(string $ipAddress, ?object $reader): ?array
    {
        if (! filter_var($ipAddress, FILTER_VALIDATE_IP) || $reader === null) {
            return null;
        }

        try {
            $record = $reader->city($ipAddress);

            return [
                'ip_masked' => $this->mask($ipAddress),
                'country_code' => $record->country->isoCode,
                'country_name' => $record->country->name,
                'region' => $record->mostSpecificSubdivision->name,
                'city' => $record->city->name,
                'latitude' => $record->location->latitude,
                'longitude' => $record->location->longitude,
                'timezone' => $record->location->timeZone,
                'isp' => null,
                'source' => 'maxmind-geolite2',
            ];
        } catch (Throwable) {
            Log::warning('IP geolocation lookup failed safely.', [
                'provider' => 'maxmind-geolite2',
            ]);

            return null;
        }
    }

    /**
     * @return array<string, float|string|null>
     */
    private function localLocation(string $ipAddress): array
    {
        return [
            'ip_masked' => $this->mask($ipAddress),
            'country_code' => null,
            'country_name' => 'Local network',
            'region' => null,
            'city' => null,
            'latitude' => null,
            'longitude' => null,
            'timezone' => null,
            'isp' => null,
            'source' => 'local',
        ];
    }

    /**
     * @return array<string, float|string|null>
     */
    private function unknownLocation(string $ipAddress): array
    {
        return [
            'ip_masked' => $this->mask($ipAddress),
            'country_code' => null,
            'country_name' => 'Unknown',
            'region' => null,
            'city' => null,
            'latitude' => null,
            'longitude' => null,
            'timezone' => null,
            'isp' => null,
            'source' => null,
        ];
    }

    /**
     * @return array<string, float|string|null>
     */
    private function locationFromModel(IpGeolocation $location): array
    {
        return [
            'ip_masked' => $location->ip_masked,
            'country_code' => $location->country_code,
            'country_name' => $location->country_name ?? 'Unknown',
            'region' => $location->region,
            'city' => $location->city,
            'latitude' => $location->latitude,
            'longitude' => $location->longitude,
            'timezone' => $location->timezone,
            'isp' => $location->isp,
            'source' => $location->source,
        ];
    }

    private function expandedIpv6(string $ipAddress): string
    {
        $packed = inet_pton($ipAddress);

        return $packed === false
            ? $ipAddress
            : implode(':', str_split(bin2hex($packed), 4));
    }
}
