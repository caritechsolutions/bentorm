<?php

namespace App\Models;

use App\Enums\AreaType;
use App\Support\GeoJson;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class ServiceArea extends Model
{
    public const CACHE_KEY = 'geo:areas:v1';

    protected $fillable = [
        'parent_id', 'type', 'code', 'name', 'country_code', 'currency', 'timezone', 'tax_rate', 'tax_label',
        'commission_rate', 'rounding_increment', 'hold_buffer_rate', 'polygon', 'message', 'priority', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => AreaType::class,
            'polygon' => 'array',
            'tax_rate' => 'float',
            'commission_rate' => 'float',
            'rounding_increment' => 'float',
            'hold_buffer_rate' => 'float',
            'min_lat' => 'float', 'max_lat' => 'float', 'min_lng' => 'float', 'max_lng' => 'float',
            'priority' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ServiceArea $area) {
            $area->polygon = GeoJson::normalise($area->polygon);
            [$area->min_lat, $area->max_lat, $area->min_lng, $area->max_lng] = GeoJson::bbox($area->polygon);
        });
        $flush = fn () => Cache::forget(self::CACHE_KEY);
        static::saved($flush);
        static::deleted($flush);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function fareConfigs(): HasMany
    {
        return $this->hasMany(FareConfig::class);
    }

    public function fareRules(): HasMany
    {
        return $this->hasMany(FareRule::class);
    }

    public function isService(): bool
    {
        return $this->type === AreaType::Service;
    }

    public function contains(float $lat, float $lng): bool
    {
        return $lat >= $this->min_lat && $lat <= $this->max_lat && $lng >= $this->min_lng && $lng <= $this->max_lng
            && GeoJson::contains($this->polygon, $lat, $lng);
    }

    /** The service area this row belongs to (itself for a service area). */
    public function serviceRoot(): ?self
    {
        $node = $this;
        $guard = 0;
        while ($node && ! $node->isService() && $guard++ < 5) {
            $node = $node->parent;
        }

        return $node?->isService() ? $node : null;
    }
}
