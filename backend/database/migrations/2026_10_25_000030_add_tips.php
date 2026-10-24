<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tips (100% to the driver). Payment purposes become a plain string (tip, credit…). Orders gain tip/discount/credit. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->string('purpose', 20)->default('trip_fare')->change();
        });
        Schema::table('trips', function (Blueprint $t) {
            $t->decimal('tip_amount', 10, 2)->default(0)->after('driver_earnings');
            $t->timestamp('tipped_at')->nullable()->after('tip_amount');
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->decimal('tip', 10, 2)->default(0)->after('service_fee');
            $t->decimal('discount', 10, 2)->default(0)->after('tip');   // promo code (Ride Coop pays it)
            $t->decimal('credit', 10, 2)->default(0)->after('discount'); // ride credit used
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropColumn(['tip', 'discount', 'credit']));
        Schema::table('trips', fn (Blueprint $t) => $t->dropColumn(['tip_amount', 'tipped_at']));
    }
};
