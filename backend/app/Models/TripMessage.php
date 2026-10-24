<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripMessage extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function toApi(User $viewer): array
    {
        return ['id' => $this->id, 'body' => $this->body, 'mine' => $this->sender_id === $viewer->id,
            'sender' => $this->sender?->firstName(), 'at' => $this->created_at?->toIso8601String(), 'read' => $this->read_at !== null];
    }
}
