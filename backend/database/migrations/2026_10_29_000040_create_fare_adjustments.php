<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Fare changes made by an admin after a ride (disputes): old and new amounts, the reason, and how the rider was refunded. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fare_adjustments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $t->decimal('old_fare', 10, 2);
            $t->decimal('new_fare', 10, 2);
            $t->decimal('old_earnings', 10, 2);
            $t->decimal('new_earnings', 10, 2);
            $t->string('refund_as', 16); // card | credit | cash | owed (fare went up) | none
            $t->decimal('refund_amount', 10, 2)->default(0);
            $t->string('reason');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fare_adjustments');
    }
};
