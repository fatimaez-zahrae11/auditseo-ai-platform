<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminActionLog extends Model
{
    public const UPDATED_AT = null;

    public const ACTION_USER_CREATED = 'user.created';

    public const ACTION_USER_DEACTIVATED = 'user.deactivated';

    public const ACTION_USER_REACTIVATED = 'user.reactivated';

    public const ACTION_SYSTEM_LOGS_VIEWED = 'system.logs.viewed';

    public const ACTION_SYSTEM_HEALTH_VIEWED = 'system.health_detailed.viewed';

    protected $fillable = [
        'admin_user_id',
        'action',
        'target_type',
        'target_id',
        'metadata',
        'ip_address',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'target_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function adminUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id');
    }
}
