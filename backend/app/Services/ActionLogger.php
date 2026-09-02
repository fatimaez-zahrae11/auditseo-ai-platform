<?php

namespace App\Services;

use App\Models\ActionLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Throwable;

class ActionLogger
{
    /** @var list<string> */
    private const SUMMARY_KEYS = [
        'audit_id',
        'recommendation_id',
        'target_user_id',
        'previous_status',
        'new_status',
        'lines_returned',
        'reason_code',
    ];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        ?User $actor,
        string $action,
        ?Model $entity = null,
        string $status = ActionLog::STATUS_SUCCESS,
        array $metadata = [],
    ): void {
        try {
            $safeMetadata = $this->sanitizeMetadata($metadata);

            ActionLog::query()->create([
                'actor_user_id' => $actor?->getKey(),
                'actor_role' => $actor?->role ?? ActionLog::ROLE_SYSTEM,
                'actor_name' => $actor?->name,
                'actor_email' => $actor?->email,
                'action' => Str::limit($action, 100, ''),
                'entity_type' => $entity === null
                    ? null
                    : Str::limit(Str::snake(class_basename($entity)), 100, ''),
                'entity_id' => $entity?->getKey(),
                'status' => in_array($status, [ActionLog::STATUS_SUCCESS, ActionLog::STATUS_FAILURE], true)
                    ? $status
                    : null,
                'metadata' => $safeMetadata === [] ? null : $safeMetadata,
            ]);
        } catch (Throwable) {
            // Semantic action logging must never alter the original API response.
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

    /**
     * @param  array<array-key, mixed>|null  $metadata
     */
    public function metadataSummary(?array $metadata): ?string
    {
        if ($metadata === null) {
            return null;
        }

        $safeMetadata = $this->sanitizeMetadata($metadata);
        $parts = [];

        foreach (self::SUMMARY_KEYS as $key) {
            $value = $safeMetadata[$key] ?? null;

            if (! is_scalar($value)) {
                continue;
            }

            $parts[] = str_replace('_', ' ', $key).': '.Str::limit((string) $value, 100, '');
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', trim($key)));

        foreach ([
            '.env',
            'authorization',
            'password',
            'passwd',
            'token',
            'api_key',
            'apikey',
            'secret',
            'cookie',
            'session',
            'request_body',
            'payload',
            'prompt',
            'header',
        ] as $term) {
            if (str_contains($normalized, $term)) {
                return true;
            }
        }

        return false;
    }
}
