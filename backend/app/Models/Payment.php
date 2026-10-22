<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payment extends Model
{
    use HasUlids;

    protected $fillable = [
        'trip_id', 'user_id', 'gateway_id', 'payment_method_id', 'purpose', 'method', 'gateway_ref', 'amount', 'currency',
        'authorized_amount', 'captured_amount', 'status',
        'redirect_url', 'failure_reason', 'payload', 'paid_at',
    ];

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'amount' => 'float',
            'authorized_amount' => 'float',
            'captured_amount' => 'float',
            'payload' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, ['captured', 'voided', 'failed', 'cancelled', 'refunded'], true);
    }

    public function isHold(): bool
    {
        return $this->purpose === 'hold';
    }
}
