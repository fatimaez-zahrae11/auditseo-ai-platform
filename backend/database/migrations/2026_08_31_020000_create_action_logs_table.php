<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('action_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('actor_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->string('actor_role', 20)->nullable();
            $table->string('actor_name')->nullable();
            $table->string('actor_email')->nullable();
            $table->string('action', 100);
            $table->string('entity_type', 100)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('status', 20)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['actor_role', 'created_at']);
            $table->index(['actor_user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['status', 'created_at']);
            $table->index('created_at');
        });

        $this->copyAdminLogs();
        $this->copyAuthenticatedAuthLogs();
    }

    public function down(): void
    {
        Schema::dropIfExists('action_logs');
    }

    private function copyAdminLogs(): void
    {
        DB::table('admin_action_logs')
            ->orderBy('id')
            ->chunkById(500, function ($logs): void {
                $actors = $this->actors($logs->pluck('admin_user_id')->filter()->all());
                $rows = $logs->map(function ($log) use ($actors): array {
                    $actor = $actors->get($log->admin_user_id);

                    return [
                        'actor_user_id' => $log->admin_user_id,
                        'actor_role' => $actor?->role ?? 'admin',
                        'actor_name' => $actor?->name,
                        'actor_email' => $actor?->email,
                        'action' => $log->action,
                        'entity_type' => $this->normalizeEntityType($log->target_type),
                        'entity_id' => $log->target_id,
                        'status' => 'success',
                        'metadata' => $this->safeMetadataJson($log->metadata),
                        'created_at' => $log->created_at,
                        'updated_at' => $log->created_at,
                    ];
                })->all();

                if ($rows !== []) {
                    DB::table('action_logs')->insert($rows);
                }
            });
    }

    private function copyAuthenticatedAuthLogs(): void
    {
        DB::table('auth_audit_logs')
            ->whereNotNull('user_id')
            ->orderBy('id')
            ->chunkById(500, function ($logs): void {
                $actors = $this->actors($logs->pluck('user_id')->all());
                $rows = $logs->map(function ($log) use ($actors): array {
                    $actor = $actors->get($log->user_id);

                    return [
                        'actor_user_id' => $log->user_id,
                        'actor_role' => $actor?->role,
                        'actor_name' => $actor?->name,
                        'actor_email' => $actor?->email ?? $log->email,
                        'action' => match ($log->event) {
                            'logout' => 'user.logged_out',
                            'logout_all' => 'user.logged_out_all',
                            default => 'user.logged_in',
                        },
                        'entity_type' => 'user',
                        'entity_id' => $log->user_id,
                        'status' => $log->status === 'success' ? 'success' : 'failure',
                        'metadata' => null,
                        'created_at' => $log->created_at,
                        'updated_at' => $log->updated_at,
                    ];
                })->all();

                if ($rows !== []) {
                    DB::table('action_logs')->insert($rows);
                }
            });
    }

    private function actors(array $ids)
    {
        return DB::table('users')
            ->whereIn('id', array_values(array_unique($ids)))
            ->get(['id', 'name', 'email', 'role'])
            ->keyBy('id');
    }

    private function normalizeEntityType(?string $type): ?string
    {
        return $type === null ? null : strtolower($type);
    }

    private function safeMetadataJson(mixed $metadata): ?string
    {
        $decoded = is_string($metadata) ? json_decode($metadata, true) : $metadata;

        if (! is_array($decoded)) {
            return null;
        }

        $safe = $this->sanitizeMetadata($decoded);

        return $safe === [] ? null : json_encode($safe, JSON_THROW_ON_ERROR);
    }

    private function sanitizeMetadata(array $metadata): array
    {
        $safe = [];

        foreach ($metadata as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = $this->sanitizeMetadata($value);
            } elseif (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
                $safe[$key] = $value;
            } elseif (is_string($value)) {
                $safe[$key] = Str::limit($value, 1_000, '');
            }
        }

        return $safe;
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
};
