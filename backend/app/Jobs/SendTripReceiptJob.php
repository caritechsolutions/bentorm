<?php

namespace App\Jobs;

use App\Models\Trip;
use App\Plugins\PluginManager;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendTripReceiptJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $tripId) {}

    public function handle(PluginManager $plugins): void
    {
        $trip = Trip::query()->with(['rider', 'driver', 'vehicle', 'serviceArea'])->find($this->tripId);
        if (! $trip?->rider?->email) {
            return;
        }
        $email = $plugins->email($trip->service_area_id);
        if (! $email) {
            return;
        }
        $html = view('emails.receipt', ['trip' => $trip, 'tz' => $trip->serviceArea?->timezone ?: config('app.timezone')])->render();
        $email->send($trip->rider->email, 'Your RideCoop receipt', $html);
    }
}
