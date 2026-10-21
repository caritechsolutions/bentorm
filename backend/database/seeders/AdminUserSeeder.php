<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Creates the first admin from ADMIN_PHONE / ADMIN_NAME in .env (idempotent).
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $phone = config('bentorm.admin_phone');
        if (! $phone) {
            $this->command?->warn('ADMIN_PHONE not set in .env — skipping admin user.');

            return;
        }

        $user = User::query()->firstOrCreate(
            ['phone' => $phone],
            ['name' => config('bentorm.admin_name'), 'role' => UserRole::Admin, 'phone_verified_at' => now()],
        );

        if (! $user->isAdmin()) {
            $user->forceFill(['role' => UserRole::Admin])->save();
        }

        $this->command?->info("Admin user ready: {$user->name} ({$user->phone})");
    }
}
