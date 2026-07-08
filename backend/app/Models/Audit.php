<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Audit extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain_id',
        'global_score',
        'technical_score',
        'content_score',
        'links_score',
        'performance_score',
        'raw_data',
    ];

    protected $casts = [
        'raw_data' => 'array',
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