<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class IpGeolocation extends Model
{
    use MassPrunable;

    protected $fillable = [
        'ip_hash',
        'ip_masked',
        'country_code',
        'country_name',
        'region',
        'city',
        'latitude',
        'longitude',
        'timezone',
        'isp',
        'source',
        'resolved_at',
    ];

    protected $hidden = [
        'ip_hash',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'resolved_at' => 'datetime',
        ];
    }

    public function prunable(): Builder
    {
        $days = max(1, (int) config('retention.ip_geolocations_days', 90));

        return static::query()->where('updated_at', '<', now()->subDays($days));
    }
}
