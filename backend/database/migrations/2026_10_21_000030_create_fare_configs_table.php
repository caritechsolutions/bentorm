<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Base rate card: one row per service area × vehicle type. Currency comes from the service area.
        Schema::create('fare_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_area_id')->constrained('service_areas')->cascadeOnDelete();
            $table->string('vehicle_type', 32);
            $table->decimal('base_fare', 10, 2);
            $table->decimal('per_km', 10, 2);
            $table->decimal('per_minute', 10, 2);
            $table->decimal('minimum_fare', 10, 2);
            $table->decimal('booking_fee', 10, 2)->default(0);
            $table->decimal('cancellation_fee', 10, 2)->default(0);
            $table->unsignedSmallInteger('free_wait_minutes')->default(3);
            $table->decimal('wait_per_minute', 10, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['service_area_id', 'vehicle_type']);
            $table->foreign('vehicle_type')->references('key')->on('vehicle_types')->cascadeOnUpdate()->cascadeOnDelete();
        });

        /*
         * Adjustments layered on the rate card:
         *   time_multiplier — e.g. 1.25× Fri/Sat 22:00–04:00 (local time of the service area; windows may wrap midnight)
         *   surge           — manual multiplier, optionally time-boxed with starts_at/ends_at
         *   zone_fee        — flat amount when pickup and/or drop-off is in a zone (airport fee)
         *   fixed_route     — flat fare from one zone to another, replaces the metered fare
         * vehicle_type NULL = applies to every vehicle type.
         */
        Schema::create('fare_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_area_id')->constrained('service_areas')->cascadeOnDelete();
            $table->string('vehicle_type', 32)->nullable();
            $table->string('name', 128);
            $table->enum('kind', ['time_multiplier', 'surge', 'zone_fee', 'fixed_route'])->index();
            $table->decimal('multiplier', 5, 2)->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->json('days')->nullable();          // [0..6], 0 = Sunday; NULL = every day
            $table->time('start_time')->nullable();
            $table->time('end_time')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('pickup_zone_id')->nullable()->constrained('service_areas')->cascadeOnDelete();
            $table->foreignId('dropoff_zone_id')->nullable()->constrained('service_areas')->cascadeOnDelete();
            $table->enum('zone_applies_to', ['pickup', 'dropoff', 'either'])->nullable();
            $table->boolean('bidirectional')->default(true); // fixed_route: also applies B→A
            $table->smallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fare_rules');
        Schema::dropIfExists('fare_configs');
    }
};
