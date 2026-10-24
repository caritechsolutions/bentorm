<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PromoCode extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'float', 'max_discount' => 'float', 'min_spend' => 'float', 'first_order_only' => 'boolean', 'is_active' => 'boolean',
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'max_uses' => 'integer', 'max_uses_per_user' => 'integer'];
    }

    protected static function booted(): void
    {
        static::saving(fn (PromoCode $p) => $p->code = strtoupper(trim((string) $p->code)));
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromoRedemption::class);
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    /** Discount on $amount (percent capped by max_discount, or a flat amount), never more than $amount. */
    public function discountOn(float $amount): float
    {
        $d = $this->kind === 'percent' ? $amount * $this->value / 100 : $this->value;
        if ($this->kind === 'percent' && $this->max_discount) {
            $d = min($d, $this->max_discount);
        }

        return round(max(0, min($d, $amount)), 2);
    }

    /** "15% off (up to 10.00)" / "10.00 off". */
    public function summary(string $currency = 'BBD'): string
    {
        return $this->kind === 'percent'
            ? rtrim(rtrim(number_format($this->value, 2), '0'), '.').'% off'.($this->max_discount ? " (up to {$currency} ".number_format($this->max_discount, 2).')' : '')
            : "{$currency} ".number_format($this->value, 2).' off';
    }
}
