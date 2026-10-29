<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Drivers rate riders too: the rider's average, shown to drivers on ride offers. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->decimal('rider_rating_avg', 3, 2)->nullable()->after('is_active');
            $t->unsignedInteger('rider_rating_count')->default(0)->after('rider_rating_avg');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['rider_rating_avg', 'rider_rating_count']));
    }
};
