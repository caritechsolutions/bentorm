<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A saved card. The gateway stores the card; we keep its token (encrypted) and display details. */
class PaymentMethod extends Model
{
    use HasUlids;

    protected $fillable = [
        'user_id', 'gateway_id', 'token', 'setup_ref', 'brand', 'last4', 'exp_month', 'exp_year', 'holder_name',
        'status', 'is_default', 'redirect_url', 'failure_reason', 'payload',
    ];

    protected $hidden = ['token'];

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
            'token' => 'encrypted',
            'exp_month' => 'integer',
            'exp_year' => 'integer',
            'is_default' => 'boolean',
            'payload' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class);
    }

    public function isUsable(): bool
    {
        return $this->status === 'active' && $this->token && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        if (! $this->exp_month || ! $this->exp_year) {
            return false;
        }

        return now()->startOfMonth()->gt(now()->setDate($this->exp_year, $this->exp_month, 1)->startOfMonth());
    }
}
