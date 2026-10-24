<?php

namespace App\Models;

use App\Enums\StoreStatus;
use App\Services\GeoService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * A shop on the marketplace. Run from the store portal (/store) by its owner and staff; listed for
 * customers only once an admin approves it and the store switches itself open.
 */
class Store extends Model
{
    public const DAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

    protected $fillable = [
        'name', 'slug', 'shop_category_id', 'description', 'logo_path', 'cover_path', 'phone', 'email', 'address', 'lat', 'lng',
        'hours', 'is_open', 'is_busy', 'prep_minutes', 'busy_extra_minutes', 'delivery_radius_km', 'min_order',
        'vat_registered', 'prices_include_vat',
    ];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'status' => StoreStatus::class,
            'hours' => 'array',
            'lat' => 'float', 'lng' => 'float',
            'is_open' => 'boolean', 'is_busy' => 'boolean', 'vat_registered' => 'boolean', 'prices_include_vat' => 'boolean',
            'prep_minutes' => 'integer', 'busy_extra_minutes' => 'integer',
            'delivery_radius_km' => 'float', 'min_order' => 'float', 'commission_rate' => 'float',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Store $s) {
            if (blank($s->slug)) {
                $base = Str::slug($s->name) ?: 'store';
                $slug = $base;
                for ($i = 2; self::query()->where('slug', $slug)->exists(); $i++) {
                    $slug = "{$base}-{$i}";
                }
                $s->slug = $slug;
            }
        });
        // The pin decides which service area (currency, tax, delivery rate card) the store belongs to.
        static::saving(function (Store $s) {
            if ($s->lng !== null) {
                $s->lng = \App\Support\GeoJson::wrapLng((float) $s->lng);
            }
            if (($s->isDirty('lat') || $s->isDirty('lng')) && $s->lat !== null && $s->lng !== null) {
                $s->service_area_id = app(GeoService::class)->resolve((float) $s->lat, (float) $s->lng)->service?->id;
            }
        });
    }

    // ---------------------------------------------------------------- relations

    public function category(): BelongsTo
    {
        return $this->belongsTo(ShopCategory::class, 'shop_category_id');
    }

    public function serviceArea(): BelongsTo
    {
        return $this->belongsTo(ServiceArea::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'store_users')->withPivot('role')->withTimestamps();
    }

    /** Filament tenancy: the Staff page lists these. */
    public function storeMembers(): HasMany
    {
        return $this->hasMany(StoreMember::class);
    }

    public function sections(): HasMany
    {
        return $this->hasMany(MenuSection::class);
    }

    /** Filament tenancy looks up resources by their model's plural name. */
    public function menuSections(): HasMany
    {
        return $this->hasMany(MenuSection::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    // ---------------------------------------------------------------- state

    public function isApproved(): bool
    {
        return $this->status === StoreStatus::Approved;
    }

    /** Customers can order: approved, switched open, and within today's hours. */
    public function isAcceptingOrders(?Carbon $at = null): bool
    {
        return $this->isApproved() && $this->is_open && $this->openAt($at);
    }

    /** Within opening hours (no hours set = always). Times are in the store's service-area time zone. */
    public function openAt(?Carbon $at = null): bool
    {
        $hours = $this->hours;
        if (! is_array($hours) || $hours === []) {
            return true;
        }
        $tz = $this->serviceArea?->timezone ?: config('app.timezone');
        $now = ($at ?? now())->copy()->setTimezone($tz);
        $day = strtolower($now->format('D'));
        $t = $now->format('H:i');
        foreach ((array) ($hours[$day] ?? []) as $range) {
            [$from, $to] = [(string) ($range['from'] ?? $range[0] ?? ''), (string) ($range['to'] ?? $range[1] ?? '')];
            if ($from === '' || $to === '') {
                continue;
            }
            if ($from <= $to ? ($t >= $from && $t < $to) : ($t >= $from || $t < $to)) { // past-midnight ranges
                return true;
            }
        }

        return false;
    }

    public function currentPrepMinutes(): int
    {
        return $this->prep_minutes + ($this->is_busy ? $this->busy_extra_minutes : 0);
    }

    public function owners(): BelongsToMany
    {
        return $this->users()->wherePivot('role', 'owner');
    }

    public function logoUrl(): ?string
    {
        return $this->logo_path ? Storage::disk('public')->url($this->logo_path) : null;
    }

    public function coverUrl(): ?string
    {
        return $this->cover_path ? Storage::disk('public')->url($this->cover_path) : null;
    }
}
