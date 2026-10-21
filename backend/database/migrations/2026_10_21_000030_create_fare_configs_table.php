<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fare_configs', function (Blueprint $table) {
            $table->id();
            $table->string('vehicle_type', 32)->unique();
            $table->string('display_name', 64);
            $table->string('description')->nullable();
            $table->string('currency', 3)->default('BBD');
            $table->decimal('base_fare', 10, 2);
            $table->decimal('per_km', 10, 2);
            $table->decimal('per_minute', 10, 2);
            $table->decimal('minimum_fare', 10, 2);
            $table->decimal('booking_fee', 10, 2)->default(0);
            $table->decimal('cancellation_fee', 10, 2)->default(0);
            $table->decimal('surge_multiplier', 4, 2)->default(1.00);
            $table->unsignedTinyInteger('max_passengers')->default(4);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fare_configs');
    }
};
