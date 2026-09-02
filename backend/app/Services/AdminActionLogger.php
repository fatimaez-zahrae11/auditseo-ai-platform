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
    public function __construct(private readonly ActionLogger $actionLogger) {}

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
        $this->actionLogger->log($adminUser, $action, $target, metadata: $metadata);

        try {
            $safeMetadata = $this->actionLogger->sanitizeMetadata($metadata);

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
        return $this->actionLogger->sanitizeMetadata($metadata);
    }
}
