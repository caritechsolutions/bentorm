<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An in-app call between rider and driver on a trip. Signalling (WebRTC offer/answer/ICE) goes through the server. */
class Call extends Model
{
    use HasUlids;

    public const RING_SECONDS = 45;

    protected $guarded = ['id', 'public_id'];

    protected function casts(): array
    {
        return ['video' => 'boolean', 'answered_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function caller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'caller_id');
    }

    public function callee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'callee_id');
    }

    public function involves(User $u): bool
    {
        return $u->id === $this->caller_id || $u->id === $this->callee_id;
    }

    public function otherThan(User $u): int
    {
        return $u->id === $this->caller_id ? $this->callee_id : $this->caller_id;
    }

    public function isLive(): bool
    {
        return in_array($this->status, ['ringing', 'accepted'], true);
    }
}
