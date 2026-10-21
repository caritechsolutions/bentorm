<?php

namespace Tests\Feature;

use App\Services\PricingService;
use Database\Seeders\FareConfigSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PricingTest extends TestCase
{
    use RefreshDatabase;

    public function test_estimate_endpoint_returns_quotes_for_all_types(): void
    {
        $this->seed(FareConfigSeeder::class);

        // Bridgetown → Oistins, roughly 8 km by road
        $res = $this->postJson('/api/v1/fares/estimate', [
            'pickup_lat' => 13.0969, 'pickup_lng' => -59.6145,
            'dropoff_lat' => 13.0667, 'dropoff_lng' => -59.5333,
        ]);

        $res->assertOk();
        $quotes = $res->json('quotes');
        $this->assertCount(3, $quotes);
        $this->assertSame('standard', $quotes[0]['vehicle_type']);
        $this->assertGreaterThan(5000, $quotes[0]['distance_m']);
        $this->assertGreaterThanOrEqual(10.0, $quotes[0]['fare']); // minimum fare
        $this->assertSame(0.0, fmod($quotes[0]['fare'] * 2, 1.0)); // rounded to 50 cents
    }

    public function test_short_trip_charges_minimum_fare(): void
    {
        $this->seed(FareConfigSeeder::class);
        $q = app(PricingService::class)->estimate(13.1, -59.6, 13.1005, -59.6, 'standard');
        $this->assertSame(11.0, $q['fare']); // 10.00 minimum + 1.00 booking fee
    }
}
