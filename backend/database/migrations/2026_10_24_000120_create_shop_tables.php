<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop (delivery marketplace), phase 1: categories, stores and their staff, catalogue.
 * Orders and delivery dispatch come in later phases.
 */
return new class extends Migration
{
    public function up(): void
    {
        // What customers browse first: Food, Groceries, Electronics… (admin-managed).
        Schema::create('shop_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name', 64);
            $t->string('slug', 64)->unique();
            $t->string('description')->nullable();
            $t->string('icon', 16)->nullable();          // an emoji, shown when there's no picture
            $t->string('image_path')->nullable();        // public disk
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('stores', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('slug', 140)->unique();
            $t->foreignId('shop_category_id')->constrained()->restrictOnDelete();
            $t->foreignId('service_area_id')->nullable()->constrained()->nullOnDelete();
            $t->text('description')->nullable();
            $t->string('logo_path')->nullable();
            $t->string('cover_path')->nullable();
            $t->string('phone', 32)->nullable();
            $t->string('email')->nullable();
            $t->string('address')->nullable();
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->json('hours')->nullable();                 // {"mon": [["08:00","17:00"]], …}; missing day = closed
            $t->string('status', 16)->default('pending')->index(); // pending | approved | rejected | suspended
            $t->string('review_note')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $t->boolean('is_open')->default(false);       // the store's own switch
            $t->boolean('is_busy')->default(false);       // adds busy_extra_minutes to prep time
            $t->unsignedSmallInteger('prep_minutes')->default(15);
            $t->unsignedSmallInteger('busy_extra_minutes')->default(15);
            $t->decimal('delivery_radius_km', 5, 1)->default(10);
            $t->decimal('min_order', 10, 2)->default(0);
            $t->boolean('vat_registered')->default(false);
            $t->boolean('prices_include_vat')->default(true);
            $t->decimal('commission_rate', 5, 4)->nullable(); // null = the default in Shop settings
            $t->timestamps();
            $t->index(['status', 'is_open']);
        });

        Schema::create('store_users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 16)->default('staff');     // owner | staff
            $t->timestamps();
            $t->unique(['store_id', 'user_id']);
        });

        Schema::create('menu_sections', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->string('name', 80);
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
        });

        Schema::create('products', function (Blueprint $t) {
            $t->id();
            $t->foreignId('store_id')->constrained()->cascadeOnDelete();
            $t->foreignId('menu_section_id')->nullable()->constrained()->nullOnDelete();
            $t->string('name', 120);
            $t->text('description')->nullable();
            $t->decimal('price', 10, 2);
            $t->string('photo_path')->nullable();
            $t->string('size', 8)->default('small');      // small | medium | large — large needs a car/XL to deliver
            $t->json('options')->nullable();               // [{name, required, multiple, choices: [{name, price}]}]
            $t->boolean('is_available')->default(true);    // the "sold out" switch
            $t->unsignedSmallInteger('sort_order')->default(0);
            $t->timestamps();
            $t->index(['store_id', 'is_available']);
        });

        // Store documents (business registration, permits) use the existing document system.
        Schema::table('document_types', function (Blueprint $t) {
            $t->string('applies_to', 16)->change();       // driver | vehicle | store
            $t->foreignId('shop_category_id')->nullable()->after('service_area_id')->constrained()->nullOnDelete();
        });

        // A "delivery" rate card prices deliveries the way trips are priced; it is never offered as a ride.
        Schema::table('vehicle_types', function (Blueprint $t) {
            $t->string('service', 16)->default('ride')->after('key')->index(); // ride | delivery
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_types', fn (Blueprint $t) => $t->dropColumn('service'));
        Schema::table('document_types', function (Blueprint $t) {
            $t->dropConstrainedForeignId('shop_category_id');
        });
        Schema::dropIfExists('products');
        Schema::dropIfExists('menu_sections');
        Schema::dropIfExists('store_users');
        Schema::dropIfExists('stores');
        Schema::dropIfExists('shop_categories');
    }
};
