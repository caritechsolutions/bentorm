<?php

namespace Tests\Feature;

use App\Models\FareRule;
use App\Models\ServiceArea;
use App\Support\GeoJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Midday Barbados time so the late-night rule is off unless a test moves the clock.
        $this->travelTo(Carbon::parse('2026-11-04 12:00:00', 'America/Barbados'));
    }

    private function quote(array $from, array $to, string $type = 'standard'): array
    {
        $res = $this->postJson('/api/v1/fares/estimate', $this->tripPayload($from, $to, ['vehicle_type' => $type]));
        $res->assertOk();

        return $res->json('quotes.0');
    }

    public function test_all_types_quoted_and_money_adds_up(): void
    {
        $this->seedReference();
        $res = $this->postJson('/api/v1/fares/estimate', $this->tripPayload(self::BRIDGETOWN, self::OISTINS, ['vehicle_type' => null]));
        $res->assertOk()->assertJsonCount(3, 'quotes');

        foreach ($res->json('quotes') as $q) {
            $this->assertSame('BBD', $q['currency']);
            $this->assertEqualsWithDelta(0.0, fmod($q['fare'] * 2, 1.0), 0.0001, 'rounded to 50 cents');
            $this->assertEqualsWithDelta($q['fare'], $q['subtotal'] + $q['tax'], 0.001, 'subtotal + tax = total');
            $lines = collect($q['breakdown']['lines']);
            $this->assertEqualsWithDelta($q['subtotal'], $lines->where('code', '!=', 'tax')->sum('amount'), 0.011, 'lines sum to subtotal');
            $this->assertArrayNotHasKey('platform_fee', $q, 'commission hidden from public');
            $this->assertArrayNotHasKey('lock', $q['breakdown']);
        }
    }

    public function test_minimum_fare_and_vat(): void
    {
        $this->seedReference();
        $q = $this->quote(self::BRIDGETOWN, [self::BRIDGETOWN[0] + 0.001, self::BRIDGETOWN[1]]);
        // (10.00 minimum + 1.00 booking) × 1.175 = 12.925 → 13.00
        $this->assertSame(13.0, (float) $q['fare']);
        $this->assertContains('minimum', array_column($q['breakdown']['lines'], 'code'));
        $this->assertGreaterThan(0, $q['tax']);
    }

    public function test_airport_pickup_fee_applies_only_on_pickup(): void
    {
        $this->seedReference();
        $from = $this->quote(self::AIRPORT, self::OISTINS);
        $to = $this->quote(self::OISTINS, self::AIRPORT);
        $this->assertContains('zone_fee', array_column($from['breakdown']['lines'], 'code'));
        $this->assertNotContains('zone_fee', array_column($to['breakdown']['lines'], 'code'));
    }

    public function test_late_night_multiplier_wraps_midnight(): void
    {
        $this->seedReference();
        $day = $this->quote(self::BRIDGETOWN, self::HOLETOWN)['fare'];

        $this->travelTo(Carbon::parse('2026-11-05 01:30:00', 'America/Barbados'));
        $night = $this->quote(self::BRIDGETOWN, self::HOLETOWN);
        $this->assertSame(1.25, (float) $night['surge_multiplier']);
        $this->assertGreaterThan($day, $night['fare']);

        $this->travelTo(Carbon::parse('2026-11-05 05:30:00', 'America/Barbados'));
        $this->assertSame(1.0, (float) $this->quote(self::BRIDGETOWN, self::HOLETOWN)['surge_multiplier']);
    }

    public function test_surge_stacks_with_time_multiplier_and_expires(): void
    {
        $bb = $this->seedReference();
        FareRule::query()->create(['service_area_id' => $bb->id, 'name' => 'Crop Over', 'kind' => 'surge', 'multiplier' => 1.5,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
        $this->assertSame(1.5, (float) $this->quote(self::BRIDGETOWN, self::HOLETOWN)['surge_multiplier']);

        $this->travelTo(now()->addHours(2));
        $this->assertSame(1.0, (float) $this->quote(self::BRIDGETOWN, self::HOLETOWN)['surge_multiplier']);
    }

    public function test_fixed_route_replaces_metered_fare_both_ways(): void
    {
        $bb = $this->seedReference();
        $airport = ServiceArea::query()->where('code', 'BB-GAIA')->first();
        $holetown = ServiceArea::query()->create(['type' => 'zone', 'parent_id' => $bb->id, 'code' => 'BB-HOLETOWN', 'name' => 'Holetown',
            'polygon' => GeoJson::rectangle(self::HOLETOWN[0], self::HOLETOWN[1], 1500, 1500)]);
        FareRule::query()->create(['service_area_id' => $bb->id, 'name' => 'Airport ⇄ Holetown', 'kind' => 'fixed_route',
            'amount' => 80, 'pickup_zone_id' => $airport->id, 'dropoff_zone_id' => $holetown->id]);

        foreach ([[self::AIRPORT, self::HOLETOWN], [self::HOLETOWN, self::AIRPORT]] as [$a, $b]) {
            $q = $this->quote($a, $b);
            $codes = array_column($q['breakdown']['lines'], 'code');
            $this->assertContains('fixed_route', $codes);
            $this->assertNotContains('distance', $codes);
            $this->assertNotContains('zone_fee', $codes, 'fixed fares are all-inclusive');
            // (80 + 1 booking) × 1.175 = 95.175 → 95.50
            $this->assertSame(95.5, (float) $q['fare']);
        }
    }

    public function test_admin_simulate_shows_commission_and_driver_share(): void
    {
        $this->seedReference();
        $res = $this->actingAs($this->makeUser('admin'))->postJson('/api/v1/admin/fares/simulate', [
            'pickup_lat' => self::BRIDGETOWN[0], 'pickup_lng' => self::BRIDGETOWN[1],
            'dropoff_lat' => self::OISTINS[0], 'dropoff_lng' => self::OISTINS[1],
        ])->assertOk();
        $q = $res->json('quotes.0');
        $this->assertEqualsWithDelta($q['subtotal'] * 0.2, $q['platform_fee'], 0.011);
        $this->assertEqualsWithDelta($q['subtotal'], $q['platform_fee'] + $q['driver_earnings'], 0.011);
    }
}
