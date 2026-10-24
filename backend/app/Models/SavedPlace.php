<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedPlace extends Model
{
    protected $fillable = ['user_id', 'kind', 'label', 'address', 'lat', 'lng', 'place_id'];

    protected function casts(): array
    {
        return ['lat' => 'float', 'lng' => 'float'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function toApi(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'label' => $this->label, 'address' => $this->address,
            'lat' => $this->lat, 'lng' => $this->lng, 'place_id' => $this->place_id];
    }
}
