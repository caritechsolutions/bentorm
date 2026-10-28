<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A person who runs a fleet in the portal (owner | manager). */
class FleetMember extends Model
{
    protected $table = 'fleet_users';

    protected $fillable = ['user_id', 'role'];

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
