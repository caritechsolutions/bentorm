<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    public const SIZES = ['small' => 'Small (fits a bag)', 'medium' => 'Medium (needs a car seat or boot)', 'large' => 'Large (needs an XL vehicle)'];

    protected $fillable = ['menu_section_id', 'name', 'description', 'price', 'photo_path', 'size', 'options', 'is_available', 'sort_order'];

    protected function casts(): array
    {
        return ['price' => 'float', 'options' => 'array', 'is_available' => 'boolean', 'sort_order' => 'integer'];
    }

    protected static function booted(): void
    {
        // Form repeaters key rows by random ids: keep plain lists, prices as numbers, no empty groups.
        static::saving(function (Product $p) {
            $groups = [];
            foreach (array_values((array) ($p->options ?? [])) as $g) {
                $choices = [];
                foreach (array_values((array) ($g['choices'] ?? [])) as $c) {
                    if (filled($c['name'] ?? null)) {
                        $choices[] = ['name' => trim((string) $c['name']), 'price' => round((float) ($c['price'] ?? 0), 2)];
                    }
                }
                if (filled($g['name'] ?? null) && $choices) {
                    $groups[] = ['name' => trim((string) $g['name']), 'required' => (bool) ($g['required'] ?? false), 'multiple' => (bool) ($g['multiple'] ?? false), 'choices' => $choices];
                }
            }
            $p->options = $groups ?: null;
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(MenuSection::class, 'menu_section_id');
    }

    public function photoUrl(): ?string
    {
        return $this->photo_path ? Storage::disk('public')->url($this->photo_path) : null;
    }
}
