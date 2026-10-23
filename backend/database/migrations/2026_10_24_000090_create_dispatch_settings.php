<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How ride requests reach drivers. The row with no service area is the default; a row per area overrides it.
        Schema::create('dispatch_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('service_area_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $t->string('strategy', 16)->default('waves');     // waves | broadcast
            $t->string('ranking', 16)->default('distance');   // distance | eta | longest_idle
            $t->unsignedSmallInteger('batch_size')->default(3);
            $t->unsignedSmallInteger('broadcast_limit')->default(25);
            $t->unsignedSmallInteger('offer_ttl_seconds')->default(20);
            $t->unsignedSmallInteger('max_waves')->default(4);
            $t->unsignedInteger('radius_start_m')->default(3000);
            $t->unsignedInteger('radius_step_m')->default(3000);
            $t->unsignedInteger('radius_max_m')->default(12000);
            $t->unsignedSmallInteger('eta_candidates')->default(10);
            $t->timestamps();
        });

        DB::table('dispatch_settings')->insert([
            'service_area_id' => null, 'strategy' => 'waves', 'ranking' => 'distance',
            'batch_size' => 3, 'broadcast_limit' => 25, 'offer_ttl_seconds' => 20, 'max_waves' => 4,
            'radius_start_m' => 3000, 'radius_step_m' => 3000, 'radius_max_m' => 12000, 'eta_candidates' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Schema::table('trip_offers', function (Blueprint $t) {
            $t->unsignedInteger('eta_s')->nullable()->after('distance_m');
            $t->unsignedInteger('radius_m')->nullable()->after('eta_s');
            $t->unsignedSmallInteger('wave')->nullable()->after('radius_m');
        });
    }

    public function down(): void
    {
        Schema::table('trip_offers', function (Blueprint $t) {
            $t->dropColumn(['eta_s', 'radius_m', 'wave']);
        });
        Schema::dropIfExists('dispatch_settings');
    }
};
