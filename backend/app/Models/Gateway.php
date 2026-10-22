<?php

namespace App\Models;

use App\Enums\GatewayType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Gateway extends Model
{
    protected $fillable = [
        'type', 'plugin', 'name', 'config', 'service_area_id', 'priority', 'is_active', 'last_tested_at', 'last_test_result',
    ];

    protected $hidden = ['config'];

    protected function casts(): array
    {
        return [
            'type' => GatewayType::class,
            'config' => 'encrypted:array',
            'priority' => 'integer',
            'is_active' => 'boolean',
            'last_tested_at' => 'datetime',
        ];
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }
}
