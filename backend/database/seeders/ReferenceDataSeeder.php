<?php

namespace Database\Seeders;

use App\Enums\AreaType;
use App\Enums\FareRuleKind;
use App\Models\DocumentType;
use App\Models\FareConfig;
use App\Models\FareRule;
use App\Models\Gateway;
use App\Models\ServiceArea;
use App\Models\VehicleType;
use App\Support\GeoJson;
use Illuminate\Database\Seeder;

/**
 * Starting reference data. Idempotent: only creates what's missing, never overwrites admin edits.
 * All figures are PLACEHOLDERS to be reviewed in the admin panel before launch.
 */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        // ---------------------------------------------------------------- vehicle types
        foreach ([
            ['key' => 'standard', 'name' => 'Standard', 'description' => 'Everyday rides', 'max_passengers' => 4, 'sort_order' => 1],
            ['key' => 'xl', 'name' => 'XL', 'description' => 'Larger vehicles for groups', 'max_passengers' => 6, 'sort_order' => 2],
            ['key' => 'premium', 'name' => 'Premium', 'description' => 'Top-rated drivers, premium vehicles', 'max_passengers' => 4, 'sort_order' => 3],
        ] as $vt) {
            VehicleType::query()->firstOrCreate(['key' => $vt['key']], $vt);
        }

        // ---------------------------------------------------------------- Barbados
        // Generous outline of the island (sea margin is harmless); refine in the admin map editor.
        $bb = ServiceArea::query()->firstOrCreate(['code' => 'BB'], [
            'type' => AreaType::Service, 'name' => 'Barbados', 'country_code' => 'BB', 'currency' => 'BBD',
            'timezone' => 'America/Barbados', 'tax_rate' => 0.175, 'tax_label' => 'VAT', 'commission_rate' => 0.20,
            'rounding_increment' => 0.50, 'priority' => 0,
            'polygon' => ['type' => 'Polygon', 'coordinates' => [[
                [-59.660, 13.345], [-59.600, 13.345], [-59.500, 13.240], [-59.405, 13.170], [-59.420, 13.100],
                [-59.520, 13.025], [-59.580, 13.050], [-59.625, 13.075], [-59.665, 13.160], [-59.670, 13.280],
            ]]],
        ]);

        $airport = ServiceArea::query()->firstOrCreate(['code' => 'BB-GAIA'], [
            'type' => AreaType::Zone, 'parent_id' => $bb->id, 'name' => 'Grantley Adams International Airport',
            'polygon' => GeoJson::rectangle(13.0746, -59.4925, 2200, 1400), 'priority' => 10,
        ]);
        ServiceArea::query()->firstOrCreate(['code' => 'BB-PORT'], [
            'type' => AreaType::Zone, 'parent_id' => $bb->id, 'name' => 'Bridgetown Port (cruise terminal)',
            'polygon' => GeoJson::rectangle(13.1040, -59.6310, 1200, 900), 'priority' => 10,
        ]);

        // ---------------------------------------------------------------- BB rate cards (ex-VAT)
        foreach ([
            'standard' => ['base_fare' => 5.00, 'per_km' => 2.50, 'per_minute' => 0.50, 'minimum_fare' => 10.00, 'booking_fee' => 1.00, 'cancellation_fee' => 5.00, 'wait_per_minute' => 0.50],
            'xl' => ['base_fare' => 8.00, 'per_km' => 3.50, 'per_minute' => 0.70, 'minimum_fare' => 15.00, 'booking_fee' => 1.00, 'cancellation_fee' => 8.00, 'wait_per_minute' => 0.70],
            'premium' => ['base_fare' => 12.00, 'per_km' => 4.50, 'per_minute' => 0.90, 'minimum_fare' => 25.00, 'booking_fee' => 2.00, 'cancellation_fee' => 10.00, 'wait_per_minute' => 1.00],
        ] as $type => $rates) {
            FareConfig::query()->firstOrCreate(['service_area_id' => $bb->id, 'vehicle_type' => $type], $rates + ['free_wait_minutes' => 3]);
        }

        // ---------------------------------------------------------------- BB fare rules
        FareRule::query()->firstOrCreate(['service_area_id' => $bb->id, 'name' => 'Airport pickup fee'], [
            'kind' => FareRuleKind::ZoneFee, 'amount' => 10.00, 'pickup_zone_id' => $airport->id, 'zone_applies_to' => 'pickup',
        ]);
        FareRule::query()->firstOrCreate(['service_area_id' => $bb->id, 'name' => 'Late night'], [
            'kind' => FareRuleKind::TimeMultiplier, 'multiplier' => 1.25, 'start_time' => '23:00', 'end_time' => '05:00',
        ]);

        // ---------------------------------------------------------------- document types
        $docs = [
            // driver
            ['key' => 'driver_photo', 'name' => 'Profile photo', 'applies_to' => 'driver', 'is_image_only' => true, 'visible_to_riders' => true, 'sort_order' => 1,
                'description' => 'A clear, recent photo of your face. Riders see this.'],
            ['key' => 'drivers_licence', 'name' => "Driver's licence", 'applies_to' => 'driver', 'requires_number' => true, 'requires_expiry' => true, 'sort_order' => 2],
            ['key' => 'police_certificate', 'name' => 'Police Certificate of Character', 'applies_to' => 'driver', 'sort_order' => 3,
                'description' => 'Issued within the last 6 months.'],
            ['key' => 'psv_permit', 'name' => 'PSV / taxi permit', 'applies_to' => 'driver', 'service_area_id' => $bb->id, 'requires_number' => true, 'requires_expiry' => true, 'sort_order' => 4],
            // vehicle
            ['key' => 'vehicle_registration', 'name' => 'Vehicle registration', 'applies_to' => 'vehicle', 'requires_number' => true, 'sort_order' => 10],
            ['key' => 'vehicle_insurance', 'name' => 'Insurance certificate', 'applies_to' => 'vehicle', 'requires_expiry' => true, 'sort_order' => 11,
                'description' => 'Must cover hire and reward.'],
            ['key' => 'vehicle_photo_front', 'name' => 'Vehicle photo — front (plate visible)', 'applies_to' => 'vehicle', 'is_image_only' => true, 'visible_to_riders' => true, 'sort_order' => 12],
            ['key' => 'vehicle_photo_rear', 'name' => 'Vehicle photo — rear', 'applies_to' => 'vehicle', 'is_image_only' => true, 'sort_order' => 13],
            ['key' => 'vehicle_photo_side', 'name' => 'Vehicle photo — side', 'applies_to' => 'vehicle', 'is_image_only' => true, 'sort_order' => 14],
            ['key' => 'vehicle_photo_interior', 'name' => 'Vehicle photo — interior', 'applies_to' => 'vehicle', 'is_image_only' => true, 'sort_order' => 15],
            ['key' => 'vehicle_inspection', 'name' => 'Inspection / fitness certificate', 'applies_to' => 'vehicle', 'is_required' => false, 'requires_expiry' => true, 'sort_order' => 16],
        ];
        foreach ($docs as $d) {
            DocumentType::query()->firstOrCreate(['key' => $d['key']], $d);
        }

        // ---------------------------------------------------------------- default gateways (development)
        Gateway::query()->firstOrCreate(['plugin' => 'log_sms'], ['type' => 'sms', 'name' => 'Log SMS (dev)', 'config' => [], 'is_active' => true, 'priority' => -100]);
        Gateway::query()->firstOrCreate(['plugin' => 'log_email'], ['type' => 'email', 'name' => 'Log email (dev)', 'config' => [], 'is_active' => true, 'priority' => -100]);
        Gateway::query()->firstOrCreate(['plugin' => 'test_payment'], ['type' => 'payment', 'name' => 'Test card gateway', 'config' => ['mode' => 'hosted_page'], 'service_area_id' => $bb->id, 'is_active' => true, 'priority' => -100]);
    }
}
