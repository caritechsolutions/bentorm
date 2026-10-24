<?php

use App\Models\Store;
use Illuminate\Database\Migrations\Migration;

/** Stores pinned on a repeated copy of the world map got longitudes like -779.57; move them back and re-check the area. */
return new class extends Migration
{
    public function up(): void
    {
        Store::query()->where(fn ($q) => $q->where('lng', '<', -180)->orWhere('lng', '>', 180))->get()
            ->each(fn (Store $s) => $s->save()); // saving() wraps lng and re-resolves the service area
    }

    public function down(): void {}
};
