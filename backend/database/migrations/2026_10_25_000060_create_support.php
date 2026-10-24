<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Help pages (edited in admin, shown inside the apps) and support tickets (incl. lost items) with replies. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('help_articles', function (Blueprint $t) {
            $t->id();
            $t->string('audience', 8)->default('all'); // all | rider | driver | store
            $t->string('category', 60)->default('General');
            $t->string('title');
            $t->string('slug')->unique();
            $t->text('body');                          // Markdown
            $t->unsignedInteger('sort_order')->default(0);
            $t->boolean('is_published')->default(true);
            $t->timestamps();
        });
        Schema::create('support_tickets', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $t->string('category', 16)->default('other'); // trip | payment | lost_item | delivery | account | safety | other
            $t->string('subject');
            $t->string('status', 10)->default('open');   // open | answered | closed
            $t->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('last_message_at')->nullable();
            $t->timestamps();
            $t->index(['status', 'last_message_at']);
            $t->index(['user_id', 'id']);
        });
        Schema::create('support_messages', function (Blueprint $t) {
            $t->id();
            $t->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('is_staff')->default(false);
            $t->text('body');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('help_articles');
    }
};
