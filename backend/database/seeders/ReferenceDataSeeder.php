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
            ['key' => 'standard', 'name' => 'Standard', 'description' => 'Everyday rides', 'max_passengers' => 4, 'max_luggage' => 2, 'sort_order' => 1],
            ['key' => 'xl', 'name' => 'XL', 'description' => 'Larger vehicles for groups and luggage', 'max_passengers' => 6, 'max_luggage' => 5, 'sort_order' => 2],
            ['key' => 'premium', 'name' => 'Premium', 'description' => 'Top-rated drivers, premium vehicles', 'max_passengers' => 4, 'max_luggage' => 3, 'sort_order' => 3],
        ] as $vt) {
            VehicleType::query()->firstOrCreate(['key' => $vt['key']], $vt);
        }
        // Prices shop deliveries (store → customer) like a trip; never offered as a ride.
        VehicleType::query()->firstOrCreate(['key' => 'delivery'], ['service' => 'delivery', 'name' => 'Delivery',
            'description' => 'Shop orders, store to customer', 'max_passengers' => 0, 'max_luggage' => 0, 'sort_order' => 90]);

        // ---------------------------------------------------------------- Barbados
        // Generous outline of the island (sea margin is harmless); refine in the admin map editor.
        $bb = ServiceArea::query()->firstOrCreate(['code' => 'BB'], [
            'type' => AreaType::Service, 'name' => 'Barbados', 'country_code' => 'BB', 'currency' => 'BBD',
            'timezone' => 'America/Barbados', 'tax_rate' => 0.175, 'tax_label' => 'VAT', 'commission_rate' => 0.20,
            'rounding_increment' => 0.50, 'hold_buffer_rate' => 0.20, 'priority' => 0,
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
            'standard' => ['base_fare' => 5.00, 'per_km' => 2.50, 'per_minute' => 0.50, 'minimum_fare' => 10.00, 'booking_fee' => 1.00, 'stop_fee' => 2.00, 'cancellation_fee' => 5.00, 'wait_per_minute' => 0.50],
            'xl' => ['base_fare' => 8.00, 'per_km' => 3.50, 'per_minute' => 0.70, 'minimum_fare' => 15.00, 'booking_fee' => 1.00, 'stop_fee' => 3.00, 'cancellation_fee' => 8.00, 'wait_per_minute' => 0.70],
            'premium' => ['base_fare' => 12.00, 'per_km' => 4.50, 'per_minute' => 0.90, 'minimum_fare' => 25.00, 'booking_fee' => 2.00, 'stop_fee' => 4.00, 'cancellation_fee' => 10.00, 'wait_per_minute' => 1.00],
        ] as $type => $rates) {
            FareConfig::query()->firstOrCreate(['service_area_id' => $bb->id, 'vehicle_type' => $type], $rates + ['free_wait_minutes' => 3]);
        }
        FareConfig::query()->firstOrCreate(['service_area_id' => $bb->id, 'vehicle_type' => 'delivery'], [
            'base_fare' => 4.00, 'per_km' => 1.50, 'per_minute' => 0.20, 'minimum_fare' => 6.00, 'booking_fee' => 0.00, 'stop_fee' => 0.00,
            'cancellation_fee' => 0.00, 'wait_per_minute' => 0.25, 'free_wait_minutes' => 5,
        ]);

        // ---------------------------------------------------------------- shop categories
        foreach ([
            ['slug' => 'food', 'name' => 'Food & Restaurants', 'icon' => '🍔', 'description' => 'Meals, snacks and drinks'],
            ['slug' => 'groceries', 'name' => 'Groceries', 'icon' => '🛒', 'description' => 'Supermarkets and corner shops'],
            ['slug' => 'pharmacy', 'name' => 'Pharmacy & Health', 'icon' => '💊', 'description' => 'Over-the-counter medicines and personal care'],
            ['slug' => 'electronics', 'name' => 'Electronics', 'icon' => '📱', 'description' => 'Phones, accessories and gadgets'],
            ['slug' => 'hardware', 'name' => 'Hardware & Home', 'icon' => '🔧', 'description' => 'Tools, household and garden'],
            ['slug' => 'fashion', 'name' => 'Fashion', 'icon' => '👕', 'description' => 'Clothes, shoes and accessories'],
            ['slug' => 'beauty', 'name' => 'Beauty', 'icon' => '💄', 'description' => 'Cosmetics, hair and skin care'],
            ['slug' => 'gifts', 'name' => 'Gifts & Flowers', 'icon' => '💐', 'description' => 'Flowers, cakes and presents'],
        ] as $i => $c) {
            \App\Models\ShopCategory::query()->firstOrCreate(['slug' => $c['slug']], $c + ['sort_order' => $i + 1]);
        }
        $food = \App\Models\ShopCategory::query()->where('slug', 'food')->value('id');

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
            // store
            ['key' => 'business_registration', 'name' => 'Business registration', 'applies_to' => 'store', 'requires_number' => true, 'sort_order' => 30,
                'description' => 'Certificate of incorporation or business name registration.'],
            ['key' => 'vat_certificate', 'name' => 'VAT certificate', 'applies_to' => 'store', 'is_required' => false, 'requires_number' => true, 'sort_order' => 31,
                'description' => 'If your business is VAT registered.'],
            ['key' => 'food_handling_permit', 'name' => 'Food handling / health permit', 'applies_to' => 'store', 'shop_category_id' => $food, 'requires_expiry' => true, 'sort_order' => 32,
                'description' => 'From the Ministry of Health, for businesses that prepare or sell food.'],
            ['key' => 'vehicle_inspection', 'name' => 'Inspection / fitness certificate', 'applies_to' => 'vehicle', 'is_required' => false, 'requires_expiry' => true, 'sort_order' => 16],
        ];
        foreach ($docs as $d) {
            DocumentType::query()->firstOrCreate(['key' => $d['key']], $d);
        }

        // ---------------------------------------------------------------- default gateways (development)
        Gateway::query()->firstOrCreate(['plugin' => 'log_sms'], ['type' => 'sms', 'name' => 'Log SMS (dev)', 'config' => [], 'is_active' => true, 'priority' => -100]);
        Gateway::query()->firstOrCreate(['plugin' => 'log_email'], ['type' => 'email', 'name' => 'Log email (dev)', 'config' => [], 'is_active' => true, 'priority' => -100]);
        Gateway::query()->firstOrCreate(['plugin' => 'test_payment'], ['type' => 'payment', 'name' => 'Test card gateway', 'config' => ['mode' => 'hosted_page'], 'service_area_id' => $bb->id, 'is_active' => true, 'priority' => -100]);

        // ---------------------------------------------------------------- starter help pages (only on a fresh install;
        // after that they're edited in admin → Support → Help pages, and deleted ones stay deleted)
        if (! \App\Models\HelpArticle::query()->exists()) {
            $articles = [
                ['rider', 'Rides', 'How do I book a ride?', "Tap **Where to?**, pick your destination, choose a vehicle and tap **Request**.\n\nYou'll see the fare before you book. Your driver's name, car and plate show once someone accepts."],
                ['rider', 'Rides', 'What is my ride PIN?', "When a driver accepts, your app shows a 4-digit **PIN**. Tell it to the driver when you get in — they can't start the trip without it. It makes sure you're in the right car.\n\nNever share your PIN before you're at the car."],
                ['rider', 'Rides', 'Booking a ride for later', "On the booking screen tap **Later**, choose the day and time (from 30 minutes to 7 days ahead). We start finding your driver about 15 minutes before pickup. Cancelling before then is free."],
                ['all', 'Payments', 'How do card payments work?', "When you book with a card we **hold** an amount a little above the estimate. At the end of the ride we charge the actual fare and release the rest of the hold. Holds usually drop off your statement within a few days."],
                ['all', 'Payments', 'Promo codes and ride credit', "Enter a promo code when you book or check out. Ride credit (from inviting friends, or from us) can pay part of a ride or order — switch on **Use credit**."],
                ['rider', 'Safety', 'Share my trip', "During a ride tap **Share** to send family or friends a live link. They can follow your car on a map without the app. The link stops an hour after the trip ends."],
                ['all', 'Safety', 'What does the SOS button do?', "**SOS** alerts the Ride Coop team straight away with where you are and your trip details, and shows the emergency numbers: **Police 211, Ambulance 511, Fire 311**.\n\nIf you are in danger, call the police first."],
                ['all', 'Safety', 'Calling and messaging', "You can message or call your driver (or rider) inside the app — neither of you sees the other's phone number."],
                ['rider', 'Lost items', 'I left something in the car', "Go to **Help → I lost something**, choose the trip and describe the item. We let the driver know and put you in touch."],
                ['rider', 'Shop', 'Ordering from a store', "Tap **Shop**, pick a store, add items and check out with your card. You can follow your order and your driver, and you'll get a PIN to give the driver at the door."],
                ['driver', 'Driving', 'Taking deliveries as well as rides', "Turn on **Shop deliveries** on your home screen to get delivery jobs as well as rides. Go to the store, the store taps **Picked up** when they hand it over, then the customer gives you their PIN at the door."],
                ['driver', 'Earnings', 'How and when am I paid?', "Your earnings and tips are in **Earnings**. Your **Statement** shows what Ride Coop owes you (card rides, deliveries, tips) and what you owe (commission on cash rides). Payouts are made by bank transfer."],
            ];
            foreach ($articles as $n => [$aud, $cat, $title, $body]) {
                \App\Models\HelpArticle::query()->create(['audience' => $aud, 'category' => $cat, 'title' => $title, 'body' => $body, 'sort_order' => $n]);
            }
        }
    }
}
