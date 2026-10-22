<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A configured instance of a plugin. Credentials live in `config`, encrypted at rest.
        Schema::create('gateways', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['payment', 'sms', 'email'])->index();
            $table->string('plugin', 64);
            $table->string('name', 128);
            $table->text('config')->nullable();
            $table->foreignId('service_area_id')->nullable()->constrained('service_areas')->nullOnDelete();
            $table->smallInteger('priority')->default(0);
            $table->boolean('is_active')->default(false)->index();
            $table->timestamp('last_tested_at')->nullable();
            $table->string('last_test_result')->nullable();
            $table->timestamps();
        });

        // Saved cards. The gateway holds the card; we keep only its token and display details.
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gateway_id')->constrained('gateways')->cascadeOnDelete();
            $table->text('token')->nullable();          // encrypted
            $table->string('setup_ref', 191)->nullable()->index();
            $table->string('brand', 32)->nullable();
            $table->char('last4', 4)->nullable();
            $table->unsignedTinyInteger('exp_month')->nullable();
            $table->unsignedSmallInteger('exp_year')->nullable();
            $table->string('holder_name', 128)->nullable();
            $table->enum('status', ['pending', 'active', 'failed', 'removed'])->default('pending')->index();
            $table->boolean('is_default')->default(false);
            $table->text('redirect_url')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('gateways');
    }
};
