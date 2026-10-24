<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Document extends Model
{
    protected $fillable = [
        'documentable_type', 'documentable_id', 'document_type_id', 'uploaded_by', 'number', 'expires_at',
        'disk', 'path', 'original_name', 'mime', 'size', 'status', 'reviewed_by', 'reviewed_at', 'review_note',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'expires_at' => 'date',
            'reviewed_at' => 'datetime',
            'size' => 'integer',
        ];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    /** The user who owns the document — the driver for both driver and vehicle documents. */
    public function ownerId(): ?int
    {
        $d = $this->documentable;

        return match (true) {
            $d instanceof DriverProfile => $d->user_id,
            $d instanceof Vehicle => $d->driver_id,
            default => null, // stores: see Store::users()
        };
    }
}
