<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DriverProfile;
use App\Services\ComplianceService;
use App\Services\DriverLocationService;
use Illuminate\Console\Command;

/** Marks expired documents and takes non-compliant drivers offline. Scheduled hourly. */
class ExpireDocuments extends Command
{
    protected $signature = 'documents:expire';

    protected $description = 'Expire out-of-date documents and take non-compliant drivers offline';

    public function handle(ComplianceService $compliance, DriverLocationService $locations): int
    {
        $count = Document::query()->where('status', DocumentStatus::Approved->value)
            ->whereNotNull('expires_at')->whereDate('expires_at', '<', today())
            ->update(['status' => DocumentStatus::Expired->value]);

        $offlined = 0;
        DriverProfile::query()->where('is_online', true)->with(['user', 'currentVehicle'])->each(function (DriverProfile $p) use ($compliance, $locations, &$offlined) {
            if ($p->user && $compliance->canGoOnline($p->user) && ! $p->user->activeTrip()) {
                $p->forceFill(['is_online' => false])->save();
                $locations->goOffline($p->user);
                $offlined++;
            }
        });

        $this->info("Expired {$count} document(s); took {$offlined} driver(s) offline.");

        return self::SUCCESS;
    }
}
