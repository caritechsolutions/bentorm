<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'float', 'line_total' => 'float', 'options' => 'array', 'removed' => 'boolean'];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** "Side: Breadfruit · Extras: Cheese" */
    public function optionsText(): string
    {
        return collect($this->options ?? [])
            ->map(fn ($g) => $g['group'].': '.collect($g['choices'] ?? [])->pluck('name')->implode(', '))
            ->implode(' · ');
    }
}
