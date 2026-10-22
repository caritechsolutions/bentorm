<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // What must be uploaded. service_area_id NULL = required everywhere; set it for country-specific items.
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->string('name', 128);
            $table->string('description')->nullable();
            $table->enum('applies_to', ['driver', 'vehicle'])->index();
            $table->foreignId('service_area_id')->nullable()->constrained('service_areas')->nullOnDelete();
            $table->boolean('is_required')->default(true);
            $table->boolean('requires_expiry')->default(false);
            $table->boolean('requires_number')->default(false);
            $table->boolean('is_image_only')->default(false);   // photos: reject PDFs
            $table->boolean('visible_to_riders')->default(false); // driver photo, vehicle front photo
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->morphs('documentable'); // DriverProfile or Vehicle
            $table->foreignId('document_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('number', 64)->nullable();
            $table->date('expires_at')->nullable()->index();
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 128)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'expired', 'superseded'])->default('pending')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_types');
    }
};
