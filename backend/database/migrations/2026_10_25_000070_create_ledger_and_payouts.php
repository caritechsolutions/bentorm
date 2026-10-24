<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What Ride Coop owes drivers and stores (and what drivers owe from cash rides). Every finished ride, delivery,
 * tip and order adds an entry; a payout (bank transfer) or a collection adds the opposite. Balance = sum.
 * + = Ride Coop owes the payee · − = the payee owes Ride Coop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payouts', function (Blueprint $t) {
            $t->id();
            $t->string('payee_type', 8);            // driver | store
            $t->unsignedBigInteger('payee_id');     // users.id for drivers, stores.id for stores
            $t->decimal('amount', 10, 2);           // + paid out to them · − collected from them
            $t->char('currency', 3)->default('BBD');
            $t->string('method', 16)->default('bank_transfer'); // bank_transfer | cash | other
            $t->string('reference')->nullable();
            $t->string('note')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['payee_type', 'payee_id']);
        });
        Schema::create('ledger_entries', function (Blueprint $t) {
            $t->id();
            $t->string('payee_type', 8);
            $t->unsignedBigInteger('payee_id');
            $t->string('kind', 20);                 // ride_card | ride_cash | cancellation | delivery | tip | order | payout | collection | adjustment
            $t->decimal('amount', 10, 2);
            $t->char('currency', 3)->default('BBD');
            $t->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('payout_id')->nullable()->constrained()->nullOnDelete();
            $t->string('description');
            $t->timestamps();
            $t->index(['payee_type', 'payee_id', 'created_at']);
            $t->index(['kind', 'trip_id']); // LedgerService checks these so an event is booked once
            $t->index(['kind', 'order_id']);
        });
        // Book what happened before the ledger existed.
        \App\Models\Trip::query()->whereNotNull('driver_id')->whereIn('status', ['completed', 'cancelled'])->orderBy('id')
            ->each(fn ($t) => app(\App\Services\LedgerService::class)->bookTrip($t));
        \App\Models\Order::query()->where('status', 'delivered')->orderBy('id')->each(fn ($o) => app(\App\Services\LedgerService::class)->bookOrder($o));

        foreach (['driver_profiles', 'stores'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('bank_name', 80)->nullable();
                $t->string('bank_account_name', 120)->nullable();
                $t->text('bank_account_number')->nullable(); // encrypted
                $t->string('bank_branch', 80)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['driver_profiles', 'stores'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['bank_name', 'bank_account_name', 'bank_account_number', 'bank_branch']));
        }
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('payouts');
    }
};
