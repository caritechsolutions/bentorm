<?php

namespace App\Support;

use App\Models\ServiceArea;

/** What the geofences say about one point. */
final class GeoContext
{
    /** @param ServiceArea[] $zones */
    public function __construct(
        public readonly float $lat,
        public readonly float $lng,
        public readonly ?ServiceArea $service,
        public readonly array $zones,
        public readonly ?ServiceArea $blocked,
    ) {}

    public function isServed(): bool
    {
        return $this->service !== null && $this->blocked === null;
    }

    /** Highest-priority zone, used as the trip's pickup/drop-off zone. */
    public function primaryZone(): ?ServiceArea
    {
        return $this->zones[0] ?? null;
    }

    public function inZone(int $zoneId): bool
    {
        foreach ($this->zones as $z) {
            if ($z->id === $zoneId) {
                return true;
            }
        }

        return false;
    }

    public function toArray(): array
    {
        return [
            'served' => $this->isServed(),
            'service_area' => $this->service ? [
                'id' => $this->service->id, 'code' => $this->service->code, 'name' => $this->service->name,
                'country_code' => $this->service->country_code, 'currency' => $this->service->currency,
            ] : null,
            'zones' => array_map(fn ($z) => ['id' => $z->id, 'code' => $z->code, 'name' => $z->name], $this->zones),
            'blocked' => $this->blocked ? ['code' => $this->blocked->code, 'name' => $this->blocked->name, 'message' => $this->blocked->message] : null,
        ];
    }
}
