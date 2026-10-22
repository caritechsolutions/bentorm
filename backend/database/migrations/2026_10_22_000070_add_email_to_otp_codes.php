<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Email the code was also sent to (new sign-ups: becomes the account email). */
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('email')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropColumn('email');
        });
    }
};
