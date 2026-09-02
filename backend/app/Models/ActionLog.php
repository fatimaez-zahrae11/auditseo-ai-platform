<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActionLog extends Model
{
    use MassPrunable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_USER = 'user';

    public const ROLE_SYSTEM = 'system';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILURE = 'failure';

    public const ACTION_USER_REGISTERED = 'user.registered';

    public const ACTION_USER_LOGGED_IN = 'user.logged_in';

    public const ACTION_GOOGLE_OAUTH_REDIRECT_REQUESTED = 'google.oauth_redirect_requested';

    public const ACTION_GOOGLE_OAUTH_LOGIN = 'google.oauth_login';

    public const ACTION_GOOGLE_OAUTH_ACCOUNT_LINKED = 'google.oauth_account_linked';

    public const ACTION_USER_LOGGED_OUT = 'user.logged_out';

    public const ACTION_USER_LOGGED_OUT_ALL = 'user.logged_out_all';

    public const ACTION_EMAIL_VERIFICATION_REQUESTED = 'email.verification_requested';

    public const ACTION_EMAIL_VERIFIED = 'email.verified';

    public const ACTION_AUDIT_CREATED = 'audit.created';

    public const ACTION_RECOMMENDATION_REQUESTED = 'recommendation.requested';

    protected $fillable = [
        'actor_user_id',
        'actor_role',
        'actor_name',
        'actor_email',
        'action',
        'entity_type',
        'entity_id',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'entity_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function prunable(): Builder
    {
        $days = max(1, (int) config('retention.action_logs_days', 365));

        return static::query()->where('created_at', '<', now()->subDays($days));
    }
}
