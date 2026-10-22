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

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gateway_id')->nullable()->constrained('gateways')->nullOnDelete();
            $table->enum('purpose', ['trip_fare', 'cancellation_fee'])->default('trip_fare');
            $table->enum('method', ['cash', 'card']);
            $table->string('gateway_ref', 191)->nullable()->index();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3);
            $table->enum('status', ['pending', 'captured', 'failed', 'cancelled', 'refunded'])->default('pending')->index();
            $table->text('redirect_url')->nullable();
            $table->string('failure_reason')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('gateways');
    }
};
