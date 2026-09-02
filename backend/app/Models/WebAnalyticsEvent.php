<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebAnalyticsEvent extends Model
{
    use MassPrunable;

    public const TYPE_PAGE_VIEW = 'page_view';

    protected $fillable = [
        'visitor_id_hash',
        'session_id_hash',
        'user_id',
        'path',
        'page_title',
        'referrer_host',
        'event_type',
    ];

    protected $hidden = [
        'visitor_id_hash',
        'session_id_hash',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function prunable(): Builder
    {
        $days = max(1, (int) config('retention.web_analytics_events_days', 365));

        return static::query()->where('created_at', '<', now()->subDays($days));
    }
}
