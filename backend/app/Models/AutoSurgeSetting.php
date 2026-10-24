<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutoSurgeSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'start_ratio' => 'float', 'per_ratio' => 'float', 'max_multiplier' => 'float',
            'current_multiplier' => 'float', 'computed_at' => 'datetime', 'window_minutes' => 'integer', 'min_requests' => 'integer'];
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    /** The multiplier for $demand requests against $supply free drivers (1 = no surge), in steps of 0.1. */
    public function multiplierFor(int $demand, int $supply): float
    {
        if (! $this->enabled || $demand < $this->min_requests) {
            return 1.0;
        }
        $ratio = $demand / max(1, $supply);
        if ($ratio < $this->start_ratio) {
            return 1.0;
        }
        $m = 1 + ($ratio - $this->start_ratio) * $this->per_ratio + 0.1; // first step at the threshold

        return round(min(max(1.0, floor($m * 10) / 10), max(1.0, $this->max_multiplier)), 2);
    }
}
