<?php

namespace App\Models;

use App\Enums\OfferResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripOffer extends Model
{
    protected $fillable = ['trip_id', 'driver_id', 'distance_m', 'eta_s', 'radius_m', 'wave', 'response', 'offered_at', 'expires_at', 'responded_at'];

    protected function casts(): array
    {
        return [
            'response' => OfferResponse::class,
            'offered_at' => 'datetime',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function isPending(): bool
    {
        return $this->response === OfferResponse::Pending && $this->expires_at->isFuture();
    }
}
