<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A rider's saved places: one Home, one Work, and a few others. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_places', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 8)->default('other'); // home | work | other
            $t->string('label', 60);
            $t->string('address')->nullable();
            $t->decimal('lat', 10, 7);
            $t->decimal('lng', 10, 7);
            $t->string('place_id')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_places');
    }
};
