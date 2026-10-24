<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Small admin-editable switches (key → JSON value).
        Schema::create('app_settings', function (Blueprint $t) {
            $t->string('key', 64)->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });

        // One row per in-app navigation start = one billed Navigation SDK destination.
        Schema::create('navigation_usages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $t->string('destination', 16); // pickup | stop | dropoff
            $t->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('navigation_usages');
        Schema::dropIfExists('app_settings');
    }
};
