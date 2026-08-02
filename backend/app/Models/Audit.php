<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Audit extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $attributes = [
        'status' => self::STATUS_PENDING,
    ];

    protected $fillable = [
        'domain_id',
        'global_score',
        'technical_score',
        'content_score',
        'links_score',
        'performance_score',
        'raw_data',
        'status',
        'started_at',
        'completed_at',
        'failed_at',
        'failure_reason',
    ];

    protected $hidden = [
        'failure_reason',
    ];

    protected $casts = [
        'raw_data' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function domain()
    {
        return $this->belongsTo(Domain::class);
    }

    public function issues()
    {
        return $this->hasMany(AuditIssue::class);
    }

    public function aiRecommendations()
    {
        return $this->hasMany(AiRecommendation::class);
    }
}
