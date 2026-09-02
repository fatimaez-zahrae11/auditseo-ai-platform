<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuthAuditLog extends Model
{
    public const EVENT_LOGIN = 'login';

    public const EVENT_GOOGLE_OAUTH_REDIRECT = 'google_oauth_redirect';

    public const EVENT_GOOGLE_OAUTH_LOGIN = 'google_oauth_login';

    public const EVENT_LOGOUT = 'logout';

    public const EVENT_LOGOUT_ALL = 'logout_all';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SUCCESS = 'success';

    protected $fillable = [
        'user_id',
        'email',
        'event',
        'ip_address',
        'user_agent',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
