<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payout extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payeeName(): string
    {
        return $this->payee_type === 'store'
            ? (Store::query()->whereKey($this->payee_id)->value('name') ?? 'Store')
            : (User::query()->whereKey($this->payee_id)->value('name') ?? 'Driver');
    }
}
