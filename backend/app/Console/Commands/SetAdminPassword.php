<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Set (or generate) the web admin panel password for an admin.
 *   php artisan bentorm:admin-password 12462530487            # generates and prints one
 *   php artisan bentorm:admin-password 12462530487 --password=...
 *   php artisan bentorm:admin-password --if-missing           # installer: only when the seeded admin has none
 */
class SetAdminPassword extends Command
{
    protected $signature = 'bentorm:admin-password {phone? : Admin phone (defaults to ADMIN_PHONE)} {--password= : Use this password} {--if-missing : Only set when no password exists}';

    protected $description = 'Set the admin panel password for an admin user';

    public function handle(): int
    {
        $raw = $this->argument('phone') ?: config('bentorm.admin_phone');
        if (! $raw) {
            $this->error('No phone given and ADMIN_PHONE is not set.');

            return self::FAILURE;
        }
        $user = User::query()->where('phone', Phone::normalise((string) $raw))->first();
        if (! $user || $user->role !== UserRole::Admin) {
            $this->error("No admin with phone {$raw}.");

            return self::FAILURE;
        }
        if ($this->option('if-missing') && $user->password) {
            $this->line("Admin {$user->phone} already has a panel password (unchanged).");

            return self::SUCCESS;
        }

        $password = $this->option('password') ?: Str::password(16, symbols: false);
        $user->forceFill(['password' => $password])->save();

        $this->info("Admin panel login for {$user->name}:");
        $this->line("  phone    : {$user->phone}");
        $this->line("  password : {$password}");
        if (! $this->option('password')) {
            $this->warn('Store this password now — it is not shown again. Re-run this command to change it.');
        }

        return self::SUCCESS;
    }
}
