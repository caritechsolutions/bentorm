<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('rider_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->foreignId('service_area_id')->nullable()->constrained('service_areas')->nullOnDelete();
            $table->foreignId('pickup_zone_id')->nullable()->constrained('service_areas')->nullOnDelete();
            $table->foreignId('dropoff_zone_id')->nullable()->constrained('service_areas')->nullOnDelete();
            $table->string('vehicle_type', 32)->default('standard');
            $table->enum('status', [
                'requested', 'accepted', 'arrived', 'in_progress', 'completed', 'cancelled', 'no_drivers',
            ])->default('requested')->index();

            $table->decimal('pickup_lat', 10, 7);
            $table->decimal('pickup_lng', 10, 7);
            $table->string('pickup_address')->nullable();
            $table->decimal('dropoff_lat', 10, 7);
            $table->decimal('dropoff_lng', 10, 7);
            $table->string('dropoff_address')->nullable();

            $table->unsignedInteger('estimated_distance_m')->nullable();
            $table->unsignedInteger('estimated_duration_s')->nullable();
            $table->decimal('estimated_fare', 10, 2)->nullable();
            $table->unsignedInteger('actual_distance_m')->nullable();
            $table->unsignedInteger('actual_duration_s')->nullable();
            $table->unsignedInteger('wait_duration_s')->nullable();

            // Money — all in `currency`. total = subtotal + tax.
            $table->char('currency', 3)->default('BBD');
            $table->decimal('subtotal', 10, 2)->nullable();
            $table->decimal('tax_amount', 10, 2)->nullable();
            $table->decimal('final_fare', 10, 2)->nullable();
            $table->decimal('platform_fee', 10, 2)->nullable();
            $table->decimal('driver_earnings', 10, 2)->nullable();
            $table->json('fare_breakdown')->nullable();
            $table->decimal('surge_multiplier', 5, 2)->default(1.00);

            $table->enum('payment_method', ['cash', 'card'])->default('cash');
            $table->enum('payment_status', ['pending', 'paid', 'failed', 'refunded', 'waived'])->default('pending')->index();
            $table->decimal('amount_due', 10, 2)->default(0);

            $table->unsignedTinyInteger('passengers')->default(1);
            $table->text('rider_note')->nullable();

            $table->timestamp('requested_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->enum('cancelled_by', ['rider', 'driver', 'system', 'admin'])->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->decimal('cancellation_fee', 10, 2)->default(0);

            $table->timestamps();

            $table->index(['rider_id', 'status']);
            $table->index(['driver_id', 'status']);
            $table->index('requested_at');
        });

        Schema::create('trip_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('distance_m')->nullable();
            $table->enum('response', ['pending', 'accepted', 'declined', 'expired', 'withdrawn'])->default('pending')->index();
            $table->timestamp('offered_at');
            $table->timestamp('expires_at');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['trip_id', 'driver_id']);
        });

        Schema::create('trip_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('speed_mps', 6, 2)->nullable();
            $table->decimal('heading', 5, 1)->nullable();
            $table->timestamp('recorded_at')->index();
        });

        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rater_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('ratee_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('score');
            $table->string('comment')->nullable();
            $table->timestamps();
            $table->unique(['trip_id', 'rater_id']);
            $table->index('ratee_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
        Schema::dropIfExists('trip_locations');
        Schema::dropIfExists('trip_offers');
        Schema::dropIfExists('trips');
    }
};
