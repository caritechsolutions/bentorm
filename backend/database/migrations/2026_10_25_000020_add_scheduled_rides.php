<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Rides booked for later: status "scheduled" until it's time to find a driver. Status becomes a plain string. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $t) {
            $t->string('status', 20)->default('requested')->change();
            $t->timestamp('scheduled_at')->nullable()->after('requested_at');
            $t->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::table('trips', function (Blueprint $t) {
            $t->dropIndex(['status', 'scheduled_at']);
            $t->dropColumn('scheduled_at');
        });
    }
};
