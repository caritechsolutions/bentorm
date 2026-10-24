<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** What customers browse first in the Shop: Food, Groceries, Electronics… */
class ShopCategory extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'icon', 'image_path', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (ShopCategory $c) {
            if (blank($c->slug)) {
                $c->slug = Str::slug($c->name);
            }
        });
    }

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
