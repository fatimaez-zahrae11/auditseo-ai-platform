<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Domain extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'domain_name',
        'url',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function audits()
    {
        return $this->hasMany(Audit::class);
    }
}