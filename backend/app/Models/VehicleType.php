<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class VehicleType extends Model
{
    protected $fillable = ['key', 'name', 'description', 'max_passengers', 'max_luggage', 'icon_url', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['max_passengers' => 'integer', 'max_luggage' => 'integer', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }
}
