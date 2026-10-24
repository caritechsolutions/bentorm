<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Gateway types grow with plugins (push notifications now); a plain string keeps that a code change only.
    public function up(): void
    {
        Schema::table('gateways', function (Blueprint $t) {
            $t->string('type', 16)->change();
        });
        Schema::table('device_tokens', function (Blueprint $t) {
            $t->timestamp('last_used_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $t) {
            $t->dropColumn('last_used_at');
        });
    }
};
