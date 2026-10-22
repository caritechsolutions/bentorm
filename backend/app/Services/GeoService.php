<?php

namespace App\Services;

use App\Enums\AreaType;
use App\Models\ServiceArea;
use App\Support\GeoContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class GeoService
{
    /** All active areas, cached; flushed automatically whenever an area is saved or deleted. */
    public function areas(): Collection
    {
        return Cache::remember(ServiceArea::CACHE_KEY, 300, fn () => ServiceArea::query()
            ->where('is_active', true)->orderByDesc('priority')->orderBy('id')->get());
    }

    public function resolve(float $lat, float $lng): GeoContext
    {
        $hits = $this->areas()->filter(fn (ServiceArea $a) => $a->contains($lat, $lng))->values();

        $service = $hits->first(fn (ServiceArea $a) => $a->type === AreaType::Service);

        $zones = $service
            ? $hits->filter(fn (ServiceArea $a) => $a->type === AreaType::Zone && $a->parent_id === $service->id)->values()->all()
            : [];

        $blocked = $hits->first(fn (ServiceArea $a) => $a->type === AreaType::Blocked
            && ($a->parent_id === null || $a->parent_id === $service?->id));

        return new GeoContext($lat, $lng, $service, $zones, $blocked);
    }

    /**
     * Enforce geofences for a trip. Returns [pickupContext, dropoffContext].
     *
     * @return array{0: GeoContext, 1: GeoContext}
     */
    public function assertTripAllowed(float $pickupLat, float $pickupLng, float $dropoffLat, float $dropoffLng): array
    {
        $pickup = $this->resolve($pickupLat, $pickupLng);
        $dropoff = $this->resolve($dropoffLat, $dropoffLng);

        if (! $pickup->service) {
            throw ValidationException::withMessages(['pickup' => ["We don't operate at this pickup location yet."]]);
        }
        if ($pickup->blocked) {
            throw ValidationException::withMessages(['pickup' => [$pickup->blocked->message ?: 'Pickups are not available at this location.']]);
        }
        if ($dropoff->blocked) {
            throw ValidationException::withMessages(['dropoff' => [$dropoff->blocked->message ?: 'Drop-offs are not available at this location.']]);
        }
        if (! $dropoff->service || $dropoff->service->id !== $pickup->service->id) {
            throw ValidationException::withMessages(['dropoff' => ["Drop-off must be within {$pickup->service->name}."]]);
        }

        return [$pickup, $dropoff];
    }
}
