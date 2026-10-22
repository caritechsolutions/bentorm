<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('service_area_id')->nullable()->constrained('service_areas')->nullOnDelete();
            $table->string('licence_number', 64)->nullable();
            $table->date('licence_expiry')->nullable();
            $table->string('psv_permit_number', 64)->nullable();
            $table->enum('status', ['pending', 'approved', 'suspended', 'rejected'])->default('pending')->index();
            $table->text('status_note')->nullable();
            $table->boolean('is_online')->default(false)->index();
            $table->foreignId('current_vehicle_id')->nullable();
            $table->decimal('rating_avg', 3, 2)->default(5.00);
            $table->unsignedInteger('rating_count')->default(0);
            $table->unsignedInteger('completed_trips')->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('users')->cascadeOnDelete();
            $table->string('make', 64);
            $table->string('model', 64);
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('colour', 32)->nullable();
            $table->string('plate', 16)->unique();
            $table->unsignedTinyInteger('seats')->default(4);
            $table->string('vehicle_type', 32)->default('standard')->index();
            $table->enum('status', ['pending', 'approved', 'rejected', 'suspended'])->default('pending')->index();
            $table->text('status_note')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->foreign('vehicle_type')->references('key')->on('vehicle_types')->cascadeOnUpdate();
        });

        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->foreign('current_vehicle_id')->references('id')->on('vehicles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('driver_profiles', function (Blueprint $table) {
            $table->dropForeign(['current_vehicle_id']);
        });
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('driver_profiles');
    }
};
