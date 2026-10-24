<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Automatic surge per service area: recent requests vs free drivers, worked out every minute (needs App features → Automatic surge). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_surge_settings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('service_area_id')->unique()->constrained()->cascadeOnDelete();
            $t->boolean('enabled')->default(true);
            $t->unsignedSmallInteger('window_minutes')->default(10);   // count requests over this long
            $t->unsignedSmallInteger('min_requests')->default(3);      // no surge below this many requests
            $t->decimal('start_ratio', 5, 2)->default(1.5);            // requests per free driver where surge starts
            $t->decimal('per_ratio', 5, 2)->default(0.25);             // + multiplier for each extra request per driver
            $t->decimal('max_multiplier', 4, 2)->default(1.8);         // cap
            $t->decimal('current_multiplier', 4, 2)->default(1);
            $t->unsignedInteger('demand')->default(0);
            $t->unsignedInteger('supply')->default(0);
            $t->timestamp('computed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_surge_settings');
    }
};
