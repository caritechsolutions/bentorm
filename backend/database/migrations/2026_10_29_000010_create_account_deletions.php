<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A record that an account was deleted (when, how, and anything Ride Coop still owed the person). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_deletions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->index();
            $t->string('role', 16);
            $t->string('via', 16); // app | web
            $t->decimal('owed_to_user', 10, 2)->default(0); // driver earnings still to pay out
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_deletions');
    }
};
