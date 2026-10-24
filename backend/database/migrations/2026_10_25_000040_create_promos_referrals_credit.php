<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promo codes (made in admin), invite-a-friend referrals, and ride credit (a per-user ledger; the balance is the sum).
 * Discounts and credit are paid by Ride Coop: drivers and stores are paid on the full price.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $t) {
            $t->id();
            $t->string('code', 32)->unique();
            $t->string('description')->nullable();
            $t->string('kind', 8)->default('percent');      // percent | amount
            $t->decimal('value', 10, 2);                    // 15 (%) or 10.00
            $t->decimal('max_discount', 10, 2)->nullable();  // cap for percent codes
            $t->string('applies_to', 8)->default('both');    // rides | shop | both
            $t->decimal('min_spend', 10, 2)->default(0);
            $t->boolean('first_order_only')->default(false); // new customers only
            $t->foreignId('service_area_id')->nullable()->constrained('service_areas')->nullOnDelete();
            $t->timestamp('starts_at')->nullable();
            $t->timestamp('ends_at')->nullable();
            $t->unsignedInteger('max_uses')->nullable();
            $t->unsignedInteger('max_uses_per_user')->default(1);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('promo_redemptions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('promo_code_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 10, 2)->default(0);
            $t->string('status', 10)->default('applied'); // applied (reserved) | used | released
            $t->timestamps();
            $t->index(['promo_code_id', 'status']);
            $t->index(['user_id', 'promo_code_id']);
        });
        Schema::create('credit_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->decimal('amount', 10, 2);           // + earned / − used
            $t->char('currency', 3)->default('BBD');
            $t->string('reason', 24);                // referral_bonus | welcome | trip | order | refund | admin
            $t->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('note')->nullable();
            $t->timestamps();
            $t->index(['user_id', 'created_at']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('referral_code', 12)->nullable()->unique();
            $t->foreignId('referred_by_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('referral_rewarded_at')->nullable();
        });
        Schema::table('trips', function (Blueprint $t) {
            $t->foreignId('promo_code_id')->nullable()->after('surge_multiplier')->constrained('promo_codes')->nullOnDelete();
            $t->decimal('discount_amount', 10, 2)->default(0)->after('promo_code_id');
            $t->decimal('credit_amount', 10, 2)->default(0)->after('discount_amount');
            $t->boolean('use_credit')->default(false)->after('credit_amount');
        });
        Schema::table('orders', function (Blueprint $t) {
            $t->foreignId('promo_code_id')->nullable()->after('credit')->constrained('promo_codes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $t) => $t->dropConstrainedForeignId('promo_code_id'));
        Schema::table('trips', function (Blueprint $t) {
            $t->dropConstrainedForeignId('promo_code_id');
            $t->dropColumn(['discount_amount', 'credit_amount', 'use_credit']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropConstrainedForeignId('referred_by_id');
            $t->dropColumn(['referral_code', 'referral_rewarded_at']);
        });
        Schema::dropIfExists('credit_entries');
        Schema::dropIfExists('promo_redemptions');
        Schema::dropIfExists('promo_codes');
    }
};
