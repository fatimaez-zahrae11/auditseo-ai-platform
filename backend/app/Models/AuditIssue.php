<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditIssue extends Model
{
    protected $fillable = [
        'audit_id',
        'category',
        'title',
        'severity',
        'description',
        'recommendation',
    ];

    public function audit()
    {
        return $this->belongsTo(Audit::class);
    }
}
