<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cash a fleet driver collected in a fleet car (+) or handed over to the owner (−). */
class FleetCash extends Model
{
    protected $table = 'fleet_cash';

    protected $fillable = ['fleet_id', 'driver_id', 'trip_id', 'amount', 'currency', 'description', 'created_by'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
