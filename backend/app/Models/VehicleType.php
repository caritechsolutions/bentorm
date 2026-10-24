<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleType extends Model
{
    protected $fillable = ['key', 'service', 'name', 'description', 'max_passengers', 'max_luggage', 'icon_url', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['max_passengers' => 'integer', 'max_luggage' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /** Types riders can book (the "delivery" rate card only prices shop deliveries). */
    public function scopeRides(\Illuminate\Database\Eloquent\Builder $q): \Illuminate\Database\Eloquent\Builder
    {
        return $q->where('service', 'ride');
    }
}
