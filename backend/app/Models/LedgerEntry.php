<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** + = Ride Coop owes the driver/store · − = they owe Ride Coop. */
class LedgerEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function scopeFor(Builder $q, string $type, int $id): Builder
    {
        return $q->where('payee_type', $type)->where('payee_id', $id);
    }

    public static function balance(string $type, int $id): float
    {
        return round((float) self::query()->for($type, $id)->sum('amount'), 2);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(Payout::class);
    }
}
