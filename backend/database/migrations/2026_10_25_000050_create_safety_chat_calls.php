<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Safety: share-my-trip links, SOS alerts, rider ↔ driver chat, and in-app (WebRTC) calls with a phone-bridge fallback. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trips', function (Blueprint $t) {
            $t->string('share_token', 40)->nullable()->unique()->after('public_id');
        });
        Schema::create('safety_alerts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();   // who pressed SOS
            $t->string('role', 10);                                        // rider | driver | customer
            $t->decimal('lat', 10, 7)->nullable();
            $t->decimal('lng', 10, 7)->nullable();
            $t->string('status', 10)->default('open');                     // open | handling | resolved
            $t->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $t->text('notes')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'created_at']);
        });
        Schema::create('trip_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $t->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $t->string('body', 500);
            $t->timestamp('read_at')->nullable();
            $t->timestamps();
            $t->index(['trip_id', 'id']);
        });
        Schema::create('calls', function (Blueprint $t) {
            $t->id();
            $t->ulid('public_id')->unique();
            $t->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $t->foreignId('caller_id')->constrained('users')->cascadeOnDelete();
            $t->foreignId('callee_id')->constrained('users')->cascadeOnDelete();
            $t->boolean('video')->default(false);
            $t->string('status', 12)->default('ringing'); // ringing | accepted | declined | missed | ended | bridged
            $t->string('via', 8)->default('app');        // app (WebRTC) | phone (Twilio bridge)
            $t->timestamp('answered_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->timestamps();
            $t->index(['callee_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calls');
        Schema::dropIfExists('trip_messages');
        Schema::dropIfExists('safety_alerts');
        Schema::table('trips', fn (Blueprint $t) => $t->dropColumn('share_token'));
    }
};
