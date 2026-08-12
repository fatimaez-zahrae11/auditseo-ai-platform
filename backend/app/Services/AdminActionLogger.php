<?php

namespace App\Services;

use App\Models\AdminActionLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class AdminActionLogger
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        User $adminUser,
        string $action,
        ?Model $target = null,
        array $metadata = [],
        ?Request $request = null,
    ): void {
        try {
            $safeMetadata = $this->sanitizeMetadata($metadata);

            AdminActionLog::query()->create([
                'admin_user_id' => $adminUser->getKey(),
                'action' => Str::limit($action, 100, ''),
                'target_type' => $target === null
                    ? null
                    : Str::limit(class_basename($target), 100, ''),
                'target_id' => $target?->getKey(),
                'metadata' => $safeMetadata === [] ? null : $safeMetadata,
                'ip_address' => $request?->ip() === null
                    ? null
                    : Str::limit((string) $request->ip(), 45, ''),
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // Admin action logging must never alter the original API response.
        }
    }

    /**
     * @param  array<array-key, mixed>  $metadata
     * @return array<array-key, mixed>
     */
    public function sanitizeMetadata(array $metadata): array
    {
        $safe = [];

        foreach ($metadata as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = $this->sanitizeMetadata($value);

                continue;
            }

            if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
                $safe[$key] = $value;

                continue;
            }

            if (is_string($value)) {
                $safe[$key] = Str::limit($value, 1_000, '');
            }
        }

        return $safe;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', trim($key)));

        if (str_contains($normalized, '.env') || str_contains($normalized, 'authorization')) {
            return true;
        }

        foreach (['password', 'token', 'api_key', 'apikey', 'secret', 'cookie', 'session'] as $term) {
            if (str_contains($normalized, $term)) {
                return true;
            }
        }

        return false;
    }
}
