<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('gateway_id')->nullable()->constrained('gateways')->nullOnDelete();
            $table->foreignId('payment_method_id')->nullable()->constrained('payment_methods')->nullOnDelete();
            // hold = authorisation placed at request; trip_fare / cancellation_fee / top_up = direct charges
            $table->enum('purpose', ['hold', 'trip_fare', 'cancellation_fee', 'top_up'])->default('trip_fare');
            $table->enum('method', ['cash', 'card']);
            $table->string('gateway_ref', 191)->nullable()->index();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3);
            $table->decimal('authorized_amount', 10, 2)->nullable();
            $table->decimal('captured_amount', 10, 2)->nullable();
            $table->enum('status', ['pending', 'authorized', 'captured', 'voided', 'failed', 'cancelled', 'refunded'])->default('pending')->index();
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
    }
};
