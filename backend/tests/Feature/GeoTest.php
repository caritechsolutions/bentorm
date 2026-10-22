<?php

namespace Tests\Feature;

use App\Models\ServiceArea;
use App\Support\GeoJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_points_resolve_to_area_and_zone(): void
    {
        $this->seedReference();

        $this->getJson('/api/v1/geo/check?lat='.self::BRIDGETOWN[0].'&lng='.self::BRIDGETOWN[1])
            ->assertOk()->assertJsonPath('served', true)->assertJsonPath('service_area.code', 'BB')->assertJsonCount(0, 'zones');

        $this->getJson('/api/v1/geo/check?lat='.self::AIRPORT[0].'&lng='.self::AIRPORT[1])
            ->assertOk()->assertJsonPath('zones.0.code', 'BB-GAIA');

        $this->getJson('/api/v1/geo/check?lat='.self::ST_LUCIA[0].'&lng='.self::ST_LUCIA[1])
            ->assertOk()->assertJsonPath('served', false)->assertJsonPath('service_area', null);
    }

    public function test_estimate_rejects_unserved_and_blocked_and_cross_area(): void
    {
        $bb = $this->seedReference();

        $this->postJson('/api/v1/fares/estimate', $this->tripPayload(self::ST_LUCIA, self::BRIDGETOWN))
            ->assertStatus(422)->assertJsonValidationErrors('pickup');

        $this->postJson('/api/v1/fares/estimate', $this->tripPayload(self::BRIDGETOWN, self::ST_LUCIA))
            ->assertStatus(422)->assertJsonValidationErrors('dropoff');

        ServiceArea::query()->create([
            'type' => 'blocked', 'parent_id' => $bb->id, 'code' => 'BB-BLOCK', 'name' => 'Closed road',
            'message' => 'No drop-offs here during the festival.',
            'polygon' => GeoJson::rectangle(self::HOLETOWN[0], self::HOLETOWN[1], 500, 500),
        ]);
        $this->postJson('/api/v1/fares/estimate', $this->tripPayload(self::BRIDGETOWN, self::HOLETOWN))
            ->assertStatus(422)->assertJsonPath('errors.dropoff.0', 'No drop-offs here during the festival.');
    }

    public function test_admin_can_create_area_from_point_list_and_bad_polygon_is_rejected(): void
    {
        $this->seedReference();
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->postJson('/api/v1/admin/service-areas', [
            'type' => 'service', 'code' => 'VC', 'name' => 'St Vincent', 'currency' => 'XCD', 'timezone' => 'America/St_Vincent',
            'tax_rate' => 0.16, 'commission_rate' => 0.2,
            'polygon' => [['lat' => 13.1, 'lng' => -61.3], ['lat' => 13.1, 'lng' => -61.1], ['lat' => 13.4, 'lng' => -61.1], ['lat' => 13.4, 'lng' => -61.3]],
        ])->assertCreated()->assertJsonPath('polygon.type', 'Polygon')->assertJsonPath('bbox.min_lat', 13.1);

        $this->actingAs($admin)->postJson('/api/v1/admin/service-areas', [
            'type' => 'service', 'code' => 'XX', 'name' => 'Bad', 'currency' => 'USD', 'timezone' => 'UTC',
            'polygon' => ['type' => 'Polygon', 'coordinates' => [[[13.1, -61.3], [13.1, 200], [13.4, -61.1]]]],
        ])->assertStatus(422)->assertJsonValidationErrors('polygon');

        $this->actingAs($admin)->postJson('/api/v1/admin/service-areas', [
            'type' => 'zone', 'code' => 'ORPHAN', 'name' => 'No parent',
            'polygon' => GeoJson::rectangle(13.1, -59.6, 100, 100),
        ])->assertStatus(422)->assertJsonValidationErrors('parent_id');
    }
}
