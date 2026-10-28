<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per fleet (admin setting): tips on trips in the fleet's cars go to the driver (default) or to the fleet. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleets', function (Blueprint $t) {
            $t->boolean('tips_to_driver')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('fleets', fn (Blueprint $t) => $t->dropColumn('tips_to_driver'));
    }
};
