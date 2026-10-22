<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Console\Command;

/**
 * For scripts/smoke.sh on the server: sign a test account in without an SMS (once a real SMS
 * gateway is on, codes are no longer in the log). Shell access to the server is required anyway.
 */
class IssueTestToken extends Command
{
    protected $signature = 'bentorm:test-token {phone} {--name=Test user} {--role=rider} {--email=}';

    protected $description = 'Create (if needed) a test account and print an API token for it';

    public function handle(): int
    {
        $phone = Phone::normalise((string) $this->argument('phone'));
        $isAdmin = config('bentorm.admin_phone') && $phone === Phone::normalise((string) config('bentorm.admin_phone'));
        if (! str_starts_with($phone, '+1246555') && ! $isAdmin) {
            $this->error('Test tokens are only issued for +1 246 555 xxxx test numbers and the configured admin.');

            return self::FAILURE;
        }
        if ($isAdmin) {
            $admin = User::query()->where('phone', $phone)->firstOrFail();
            $this->line($admin->createToken('smoke')->plainTextToken);

            return self::SUCCESS;
        }
        $role = UserRole::tryFrom((string) $this->option('role')) ?? UserRole::Rider;
        $user = User::query()->firstOrCreate(['phone' => $phone], [
            'name' => $this->option('name'),
            'email' => $this->option('email') ?: null,
            'role' => $role->value,
            'phone_verified_at' => now(),
        ]);
        if ($user->isDriver() && ! $user->driverProfile) {
            $user->driverProfile()->create([]);
        }
        $this->line($user->createToken('smoke')->plainTextToken);

        return self::SUCCESS;
    }
}
