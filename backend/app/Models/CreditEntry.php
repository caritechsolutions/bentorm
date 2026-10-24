<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One line of a customer's ride credit (+ earned, − used). The balance is the sum. */
class CreditEntry extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function balance(int $userId): float
    {
        return round((float) self::query()->where('user_id', $userId)->sum('amount'), 2);
    }

    public function label(): string
    {
        return match ($this->reason) {
            'referral_bonus' => 'Invite reward',
            'welcome' => 'Welcome credit',
            'trip' => 'Used on a ride',
            'order' => 'Used on an order',
            'refund' => 'Returned',
            'admin' => $this->note ?: 'From Ride Coop',
            default => ucfirst($this->reason),
        };
    }
}
