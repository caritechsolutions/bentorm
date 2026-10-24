<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class HelpArticle extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_published' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::saving(function (HelpArticle $a) {
            if (blank($a->slug)) {
                $base = Str::slug($a->title) ?: 'article';
                $slug = $base;
                for ($i = 2; self::query()->where('slug', $slug)->whereKeyNot($a->id)->exists(); $i++) {
                    $slug = "{$base}-{$i}";
                }
                $a->slug = $slug;
            }
        });
    }

    /** Published articles for riders, drivers or store staff (plus the "all" ones). */
    public function scopeFor(Builder $q, string $audience): Builder
    {
        return $q->where('is_published', true)->whereIn('audience', ['all', $audience]);
    }

    public function html(): string
    {
        return Str::markdown($this->body, ['html_input' => 'strip', 'allow_unsafe_links' => false]);
    }
}
