<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fare_configs', function (Blueprint $table) {
            // Flat fee per extra stop (ex tax), on top of the longer route through the stops.
            $table->decimal('stop_fee', 10, 2)->default(0)->after('booking_fee');
        });

        Schema::table('trips', function (Blueprint $table) {
            // Up to bentorm.max_stops intermediate stops: [{lat, lng, address, reached_at}], in order.
            $table->json('stops')->nullable()->after('dropoff_address');
            // A rider's mid-ride change waiting for the driver: {stops, estimated_fare, distance_m, duration_s, status, requested_at, responded_at}.
            $table->json('stop_change')->nullable()->after('stops');
            // 4-digit code the rider tells the driver; required to start the trip.
            $table->char('pin', 4)->nullable()->after('stop_change');
            $table->unsignedTinyInteger('pin_attempts')->default(0)->after('pin');
            $table->timestamp('pin_failed_at')->nullable()->after('pin_attempts');
            $table->timestamp('pin_verified_at')->nullable()->after('pin_failed_at');
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $table) {
            $table->dropColumn(['stops', 'stop_change', 'pin', 'pin_attempts', 'pin_failed_at', 'pin_verified_at']);
        });
        Schema::table('fare_configs', function (Blueprint $table) {
            $table->dropColumn('stop_fee');
        });
    }
};
