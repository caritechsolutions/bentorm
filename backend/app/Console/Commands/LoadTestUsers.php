<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\DriverProfile;
use App\Models\ServiceArea;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Accounts for the k6 load test (scripts/loadtest): approved drivers with a car, and riders, all on
 * +1 246 555 9xxx test numbers, with API tokens written to a JSON file. --clean removes them again.
 */
class LoadTestUsers extends Command
{
    protected $signature = 'bentorm:loadtest-users {--drivers=50} {--riders=50} {--out=storage/app/loadtest-tokens.json} {--clean} {--force : allow on production}';

    protected $description = 'Create (or --clean) load-test drivers and riders and write their API tokens for k6';

    private const PREFIX = '+12465559';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('This is a production install. Load-test on a copy of it; add --force only if you are sure.');

            return self::FAILURE;
        }
        $ids = User::query()->withTrashed()->where('phone', 'like', self::PREFIX.'%')->where('name', 'like', 'Load %')->pluck('id');
        if ($this->option('clean')) {
            DB::transaction(function () use ($ids) {
                \App\Models\Trip::query()->whereIn('rider_id', $ids)->orWhereIn('driver_id', $ids)->delete();
                $profiles = DriverProfile::query()->whereIn('user_id', $ids)->pluck('id');
                $vehicles = Vehicle::query()->whereIn('driver_id', $ids)->pluck('id');
                Document::query()->where(fn ($q) => $q->where('documentable_type', (new DriverProfile)->getMorphClass())->whereIn('documentable_id', $profiles))
                    ->orWhere(fn ($q) => $q->where('documentable_type', (new Vehicle)->getMorphClass())->whereIn('documentable_id', $vehicles))->delete();
                DriverProfile::query()->whereIn('user_id', $ids)->delete();
                Vehicle::query()->whereIn('driver_id', $ids)->delete();
                \Laravel\Sanctum\PersonalAccessToken::query()->where('tokenable_type', (new User)->getMorphClass())->whereIn('tokenable_id', $ids)->delete();
                User::query()->withTrashed()->whereIn('id', $ids)->forceDelete();
            });
            $this->info("Removed {$ids->count()} load-test accounts and their trips.");

            return self::SUCCESS;
        }

        $drivers = max(0, min(500, (int) $this->option('drivers')));
        $riders = max(0, min(499, (int) $this->option('riders')));
        $area = ServiceArea::query()->where('is_active', true)->orderBy('id')->first();
        $out = ['drivers' => [], 'riders' => []];
        for ($i = 0; $i < $drivers + $riders; $i++) {
            $isDriver = $i < $drivers;
            $phone = self::PREFIX.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
            $user = User::query()->firstOrCreate(['phone' => $phone], [
                'name' => ($isDriver ? 'Load driver ' : 'Load rider ').$i,
                'role' => $isDriver ? 'driver' : 'rider',
                'phone_verified_at' => now(),
            ]);
            if ($isDriver && ! $user->driverProfile) {
                $v = Vehicle::query()->create(['driver_id' => $user->id, 'make' => 'Toyota', 'model' => 'Corolla', 'plate' => 'LT'.$i,
                    'vehicle_type' => 'standard', 'status' => 'approved']);
                $profile = DriverProfile::query()->create(['user_id' => $user->id, 'service_area_id' => $area?->id, 'status' => 'approved',
                    'current_vehicle_id' => $v->id, 'approved_at' => now()]);
                // Approved placeholder documents (no files), so they may go online.
                foreach ([[$profile, 'driver'], [$v, 'vehicle']] as [$owner, $kind]) {
                    foreach (DocumentType::query()->applicable($kind, $area?->id)->get() as $type) {
                        $owner->documents()->create(['document_type_id' => $type->id, 'number' => $type->requires_number ? 'LOADTEST' : null,
                            'expires_at' => $type->requires_expiry ? now()->addYear() : null, 'disk' => 'local', 'path' => 'loadtest/none',
                            'original_name' => 'loadtest.txt', 'mime' => 'text/plain', 'size' => 0, 'status' => 'approved', 'reviewed_at' => now()]);
                    }
                }
            }
            $out[$isDriver ? 'drivers' : 'riders'][] = $user->createToken('loadtest')->plainTextToken;
        }
        $path = base_path((string) $this->option('out'));
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode($out));
        @chmod($path, 0600);
        $this->info("{$drivers} drivers and {$riders} riders ready. Tokens: {$path}");
        $this->line('Remove them afterwards with: php artisan bentorm:loadtest-users --clean');

        return self::SUCCESS;
    }
}
