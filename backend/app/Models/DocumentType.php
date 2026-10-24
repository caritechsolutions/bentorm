<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentType extends Model
{
    protected $fillable = [
        'key', 'name', 'description', 'applies_to', 'service_area_id', 'shop_category_id', 'is_required', 'requires_expiry',
        'requires_number', 'is_image_only', 'visible_to_riders', 'sort_order', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean', 'requires_expiry' => 'boolean', 'requires_number' => 'boolean',
            'is_image_only' => 'boolean', 'visible_to_riders' => 'boolean', 'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    /** Active types for a driver or vehicle, global plus those specific to the given service area. */
    public function scopeApplicable(Builder $q, string $appliesTo, ?int $serviceAreaId): Builder
    {
        return $q->where('applies_to', $appliesTo)->where('is_active', true)
            ->where(fn ($w) => $w->whereNull('service_area_id')->when($serviceAreaId, fn ($w2) => $w2->orWhere('service_area_id', $serviceAreaId)))
            ->orderBy('sort_order');
    }
}
