<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('name', 64);
            $table->string('description')->nullable();
            $table->unsignedTinyInteger('max_passengers')->default(4);
            $table->unsignedTinyInteger('max_luggage')->default(2); // standard suitcases
            $table->string('icon_url')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        /*
         * One table for every geofence:
         *   service — an operating region (an island/country). Carries currency, tax, commission, timezone.
         *   zone    — a pricing zone inside a service area (airport, cruise port). parent_id = service area.
         *   blocked — no pickups or drop-offs. parent_id optional.
         * polygon holds GeoJSON geometry (Polygon or MultiPolygon, [lng, lat] order). The bbox columns
         * are derived on save and used to prefilter in SQL before the exact point-in-polygon test.
         */
        Schema::create('service_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('service_areas')->nullOnDelete();
            $table->enum('type', ['service', 'zone', 'blocked'])->index();
            $table->string('code', 32)->unique();
            $table->string('name', 128);
            $table->char('country_code', 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('timezone', 64)->nullable();
            $table->decimal('tax_rate', 6, 4)->nullable();          // 0.1750 = 17.5%
            $table->string('tax_label', 32)->nullable();             // "VAT"
            $table->decimal('commission_rate', 6, 4)->nullable();   // 0.2000 = 20%
            $table->decimal('rounding_increment', 6, 2)->nullable(); // 0.50
            $table->decimal('hold_buffer_rate', 5, 4)->nullable();   // 0.2000 = hold estimate + 20%
            $table->json('polygon');
            $table->decimal('min_lat', 10, 7);
            $table->decimal('max_lat', 10, 7);
            $table->decimal('min_lng', 10, 7);
            $table->decimal('max_lng', 10, 7);
            $table->string('message')->nullable(); // shown to users, e.g. why an area is blocked
            $table->smallInteger('priority')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->index(['min_lat', 'max_lat', 'min_lng', 'max_lng'], 'service_areas_bbox_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_areas');
        Schema::dropIfExists('vehicle_types');
    }
};
