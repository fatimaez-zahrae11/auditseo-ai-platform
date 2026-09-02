<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiUsageLog extends Model
{
    use MassPrunable;

    protected $fillable = [
        'user_id',
        'provider',
        'status',
        'status_code',
        'error_message',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        $days = max(1, (int) config('retention.api_usage_logs_days', 90));

        return static::query()->where('created_at', '<', now()->subDays($days));
    }
}
