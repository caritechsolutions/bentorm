<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NavigationUsage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['trip_id', 'driver_id', 'destination'];
}
