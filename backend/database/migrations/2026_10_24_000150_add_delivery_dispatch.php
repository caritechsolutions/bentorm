<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop phase 3: a delivery is a trip linked to its order (store → customer). Drivers opt in to deliveries.
 * The order keeps the customer's hand-over PIN and when a driver was first asked for / staff were alerted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $t) {
            $t->foreignId('order_id')->nullable()->after('rider_id')->constrained('orders')->nullOnDelete();
        });
        Schema::table('driver_profiles', function (Blueprint $t) {
            $t->boolean('accepts_deliveries')->default(false)->after('is_online');
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->string('delivery_pin', 8)->nullable()->after('prep_minutes');
            $t->timestamp('driver_requested_at')->nullable()->after('ready_at');   // first delivery job sent to drivers
            $t->timestamp('driver_alert_at')->nullable()->after('driver_requested_at'); // nobody took it for 10 min: staff told
            $t->timestamp('picked_up_at')->nullable()->after('driver_alert_at');
            $t->timestamp('delivered_at')->nullable()->after('picked_up_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['delivery_pin', 'driver_requested_at', 'driver_alert_at', 'picked_up_at', 'delivered_at']));
        Schema::table('driver_profiles', fn (Blueprint $t) => $t->dropColumn('accepts_deliveries'));
        Schema::table('trips', function (Blueprint $t) {
            $t->dropConstrainedForeignId('order_id');
        });
    }
};
