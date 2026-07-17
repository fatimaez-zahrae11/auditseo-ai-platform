<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiRecommendation extends Model
{
    protected $fillable = [
        'audit_id',
        'provider',
        'prompt_summary',
        'generated_text',
    ];

    public function audit()
    {
        return $this->belongsTo(Audit::class);
    }
}
