<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Shop phase 2: customer orders (card only), their items, and card payments that belong to an order. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('store_id')->constrained()->restrictOnDelete();
            $t->foreignId('service_area_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            // pending_payment → placed → accepted → ready (→ phase 3: delivering → delivered)
            // or declined / cancelled / expired
            $t->string('status', 20)->default('pending_payment')->index();
            $t->char('currency', 3);
            $t->decimal('items_subtotal', 10, 2);
            $t->decimal('items_tax', 10, 2)->default(0);       // VAT added on top when the store's prices exclude it
            $t->decimal('delivery_fee', 10, 2);                // incl. VAT (delivery rate card)
            $t->decimal('delivery_fee_ex_tax', 10, 2);
            $t->decimal('service_fee', 10, 2)->default(0);     // incl. VAT
            $t->decimal('total', 10, 2);
            $t->decimal('commission', 10, 2)->default(0);      // kept from the items
            $t->decimal('store_payout', 10, 2)->default(0);
            $t->decimal('delivery_platform_share', 10, 2)->default(0);
            $t->decimal('driver_earnings', 10, 2)->default(0);
            $t->json('breakdown')->nullable();                 // display lines + rates used
            $t->decimal('dropoff_lat', 10, 7);
            $t->decimal('dropoff_lng', 10, 7);
            $t->string('dropoff_address')->nullable();
            $t->unsignedInteger('distance_m')->nullable();
            $t->unsignedInteger('duration_s')->nullable();
            $t->string('note', 500)->nullable();               // for the store / driver ("gate code 12")
            $t->unsignedSmallInteger('prep_minutes')->nullable();
            $t->json('change_request')->nullable();            // store asked to remove items; customer answers
            $t->string('decline_reason')->nullable();
            $t->string('cancelled_by', 16)->nullable();        // customer | store | system | admin
            $t->string('payment_status', 16)->default('pending');
            $t->timestamp('placed_at')->nullable();
            $t->timestamp('expires_at')->nullable()->index();  // store must accept by then
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('ready_by')->nullable();
            $t->timestamp('ready_at')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->index(['store_id', 'status']);
            $t->index(['customer_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('order_id')->constrained()->cascadeOnDelete();
            $t->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 120);                           // copied: the order keeps what was bought
            $t->unsignedSmallInteger('quantity');
            $t->decimal('unit_price', 10, 2);                  // product + chosen options
            $t->decimal('line_total', 10, 2);
            $t->json('options')->nullable();                   // [{group, choices: [{name, price}]}]
            $t->string('size', 8)->default('small');
            $t->string('note', 200)->nullable();
            $t->boolean('removed')->default(false);            // taken off with the customer's OK
            $t->timestamps();
        });

        Schema::table('payments', function (Blueprint $t) {
            $t->foreignId('trip_id')->nullable()->change();
            $t->foreignId('order_id')->nullable()->after('trip_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            $t->dropConstrainedForeignId('order_id');
        });
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
