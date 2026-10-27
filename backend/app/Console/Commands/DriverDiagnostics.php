<?php

namespace App\Console\Commands;

use App\Models\DriverProfile;
use App\Services\DriverLocationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Who can get rides right now? Every driver marked online, with what dispatch sees: last ping, vehicle type,
 * idle/busy and whether they're on the map. "NOT dispatchable" explains why a driver gets no offers.
 */
class DriverDiagnostics extends Command
{
    protected $signature = 'bentorm:drivers {--all : include drivers marked offline who pinged in the last hour}';

    protected $description = 'Show online drivers and whether dispatch can offer them rides';

    public function handle(DriverLocationService $locations): int
    {
        $q = DriverProfile::query()->with(['user', 'currentVehicle']);
        $q->when(! $this->option('all'), fn ($w) => $w->where('is_online', true));
        $rows = [];
        foreach ($q->get() as $p) {
            if (! $p->user) {
                continue;
            }
            $id = $p->user_id;
            $seen = $locations->lastSeen($id);
            if (! $p->is_online && ($seen === null || $seen < now()->subHour()->timestamp)) {
                continue;
            }
            $h = (array) Redis::hgetall("driver:{$id}");
            $alive = $locations->isOnline($id);
            $onMap = is_numeric(Redis::zscore('drivers:geo', (string) $id));
            $type = $h['vehicle_type'] ?? null;
            $car = $p->currentVehicle?->vehicle_type;
            $trip = $p->user->activeTrip();
            $why = match (true) {
                ! $p->is_online => 'marked offline',
                ! $alive => 'no ping for over '.(int) config('dispatch.heartbeat_ttl_seconds', 90).' s',
                ! $onMap => 'not on the map yet (waiting for a ping)',
                $trip !== null => 'on a job',
                ($h['state'] ?? 'idle') !== 'idle' => 'marked busy',
                $type !== $car => "vehicle type {$type} ≠ car {$car}",
                default => null,
            };
            $rows[] = [
                $p->user->name,
                $car ?? '-',
                $seen ? now()->timestamp - $seen.' s ago' : 'never',
                $h['state'] ?? '-',
                $trip ? $trip->status->value : '-',
                $why ? "NOT dispatchable: {$why}" : 'dispatchable ✓',
            ];
        }
        if (! $rows) {
            $this->info('No drivers are marked online.');

            return self::SUCCESS;
        }
        $this->table(['Driver', 'Car', 'Last ping', 'State', 'Job', 'Rides?'], $rows);

        return self::SUCCESS;
    }
}
