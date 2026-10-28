<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fleets: an owner with several cars lets other drivers drive them. The fleet portal (/fleet) is run by the owner
 * and their managers; the fleet's cars carry their own documents; drivers are invited and given chosen cars; the
 * fleet is paid for every trip in its cars, and cash the driver collected is tracked as owed to the fleet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleets', function (Blueprint $t) {
            $t->id();
            $t->string('name', 120);
            $t->string('slug', 140)->unique();
            $t->foreignId('service_area_id')->nullable()->constrained()->nullOnDelete();
            $t->string('phone', 32)->nullable();
            $t->string('email')->nullable();
            $t->string('status', 16)->default('pending')->index(); // pending | approved | rejected | suspended
            $t->text('review_note')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->string('bank_name', 80)->nullable();
            $t->string('bank_account_name', 120)->nullable();
            $t->text('bank_account_number')->nullable(); // encrypted
            $t->string('bank_branch', 80)->nullable();
            $t->timestamps();
        });

        // Who runs the fleet: owner (everything) or manager (cars, drivers, trips — not bank details or team).
        Schema::create('fleet_users', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fleet_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('role', 16)->default('manager');
            $t->timestamps();
            $t->unique(['fleet_id', 'user_id']);
        });

        // Drivers of the fleet: invited by phone → accepted / declined; removed when the owner takes access away.
        Schema::create('fleet_drivers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fleet_id')->constrained()->cascadeOnDelete();
            $t->foreignId('driver_id')->nullable()->constrained('users')->cascadeOnDelete(); // set once the phone has an account
            $t->string('phone', 32)->index();
            $t->string('name', 120)->nullable();
            $t->string('status', 16)->default('invited')->index(); // invited | active | declined | removed
            $t->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('responded_at')->nullable();
            $t->timestamp('removed_at')->nullable();
            $t->timestamps();
            $t->unique(['fleet_id', 'phone']);
        });

        // Which of the fleet's cars each driver may use.
        Schema::create('fleet_driver_vehicle', function (Blueprint $t) {
            $t->foreignId('fleet_driver_id')->constrained()->cascadeOnDelete();
            $t->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $t->primary(['fleet_driver_id', 'vehicle_id']);
        });

        // Fleet cars belong to the fleet, not a driver.
        Schema::table('vehicles', function (Blueprint $t) {
            $t->foreignId('fleet_id')->nullable()->after('driver_id')->constrained()->nullOnDelete();
        });
        Schema::table('vehicles', function (Blueprint $t) {
            $t->unsignedBigInteger('driver_id')->nullable()->change();
        });

        // Which fleet a trip earned for (fixed when the driver accepts, so later changes don't move money).
        Schema::table('trips', function (Blueprint $t) {
            $t->foreignId('fleet_id')->nullable()->after('vehicle_id')->constrained()->nullOnDelete();
        });

        // Why the server set a driver offline (e.g. access to a fleet car removed) — the app shows it instead of
        // signing back on by itself.
        Schema::table('driver_profiles', function (Blueprint $t) {
            $t->string('offline_reason')->nullable()->after('is_online');
        });

        // Cash a fleet's drivers collected in its cars (+) and handed over to the owner (−), per driver.
        Schema::create('fleet_cash', function (Blueprint $t) {
            $t->id();
            $t->foreignId('fleet_id')->constrained()->cascadeOnDelete();
            $t->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $t->decimal('amount', 10, 2);
            $t->char('currency', 3)->default('BBD');
            $t->string('description');
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['fleet_id', 'driver_id']);
            $t->index('trip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_cash');
        Schema::table('driver_profiles', fn (Blueprint $t) => $t->dropColumn('offline_reason'));
        Schema::table('trips', function (Blueprint $t) {
            $t->dropConstrainedForeignId('fleet_id');
        });
        Schema::table('vehicles', function (Blueprint $t) {
            $t->dropConstrainedForeignId('fleet_id');
        });
        Schema::dropIfExists('fleet_driver_vehicle');
        Schema::dropIfExists('fleet_drivers');
        Schema::dropIfExists('fleet_users');
        Schema::dropIfExists('fleets');
    }
};
