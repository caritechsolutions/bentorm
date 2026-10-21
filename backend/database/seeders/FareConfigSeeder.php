<?php

namespace Database\Seeders;

use App\Models\FareConfig;
use Illuminate\Database\Seeder;

/**
 * Default BBD fare table. Placeholder figures — adjust from the admin panel.
 */
class FareConfigSeeder extends Seeder
{
    public function run(): void
    {
        $types = [
            [
                'vehicle_type' => 'standard', 'display_name' => 'Standard', 'description' => 'Everyday rides, up to 4 passengers',
                'base_fare' => 5.00, 'per_km' => 2.50, 'per_minute' => 0.50, 'minimum_fare' => 10.00,
                'booking_fee' => 1.00, 'cancellation_fee' => 5.00, 'max_passengers' => 4, 'sort_order' => 1,
            ],
            [
                'vehicle_type' => 'xl', 'display_name' => 'XL', 'description' => 'Larger vehicles, up to 6 passengers',
                'base_fare' => 8.00, 'per_km' => 3.50, 'per_minute' => 0.70, 'minimum_fare' => 15.00,
                'booking_fee' => 1.00, 'cancellation_fee' => 8.00, 'max_passengers' => 6, 'sort_order' => 2,
            ],
            [
                'vehicle_type' => 'premium', 'display_name' => 'Premium', 'description' => 'Top-rated drivers, premium vehicles',
                'base_fare' => 12.00, 'per_km' => 4.50, 'per_minute' => 0.90, 'minimum_fare' => 25.00,
                'booking_fee' => 2.00, 'cancellation_fee' => 10.00, 'max_passengers' => 4, 'sort_order' => 3,
            ],
        ];

        foreach ($types as $type) {
            FareConfig::query()->firstOrCreate(['vehicle_type' => $type['vehicle_type']], $type + ['currency' => 'BBD']);
        }
    }
}
