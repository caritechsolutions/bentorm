<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When the "your document expires soon / has expired" reminders went out (each is sent once). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $t) {
            $t->timestamp('reminded_30_at')->nullable();
            $t->timestamp('reminded_7_at')->nullable();
            $t->timestamp('reminded_expired_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn(['reminded_30_at', 'reminded_7_at', 'reminded_expired_at']));
    }
};
