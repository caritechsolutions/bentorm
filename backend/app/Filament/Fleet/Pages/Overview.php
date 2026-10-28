<?php

namespace App\Filament\Fleet\Pages;

use App\Enums\FleetStatus;
use App\Models\Fleet;
use App\Services\FleetService;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** The fleet's home: approval status, today's trips and earnings, and a live map of the cars and who's driving. */
class Overview extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $slug = 'overview';

    protected static ?int $navigationSort = 0;

    protected static ?string $title = 'Overview';

    protected string $view = 'filament.fleet.overview';

    public function fleet(): Fleet
    {
        /** @var Fleet $f */
        $f = Filament::getTenant();

        return $f->refresh();
    }

    public function getSubheading(): ?string
    {
        return $this->fleet()->name;
    }

    /** @return array{text: string, color: string, todo: string[]} */
    public function status(): array
    {
        $f = $this->fleet();
        $todo = [];
        if (! $f->vehicles()->exists()) {
            $todo[] = 'Add your cars (Cars → Add a car) and upload their documents.';
        } elseif ($f->vehicles()->where('status', 'pending')->exists()) {
            $todo[] = 'Some cars are waiting for Ride Coop to check them and their documents.';
        }
        if ($f->isApproved() && ! $f->drivers()->where('status', 'active')->exists()) {
            $todo[] = 'Invite your drivers (Drivers → Invite a driver) and choose which cars each may drive.';
        }
        if (blank($f->bank_account_number) && Filament::auth()->user()?->isFleetOwner($f)) {
            $todo[] = 'Add the bank account for your payouts (Fleet settings → Payouts).';
        }

        return match ($f->status) {
            FleetStatus::Pending => ['text' => 'Waiting for approval. Ride Coop will review your fleet shortly — you can add your cars meanwhile.', 'color' => '#b45309', 'todo' => $todo],
            FleetStatus::Approved => ['text' => 'Approved. Your drivers can drive the cars you give them.', 'color' => '#15803d', 'todo' => $todo],
            FleetStatus::Rejected => ['text' => 'Not approved'.($f->review_note ? ': '.$f->review_note : '.').' Contact Ride Coop.', 'color' => '#b91c1c', 'todo' => []],
            FleetStatus::Suspended => ['text' => 'Suspended'.($f->review_note ? ': '.$f->review_note : '.').' Your cars can\'t be driven.', 'color' => '#b91c1c', 'todo' => []],
        };
    }

    /** @return array{trips: int, earnings: float} */
    public function today(): array
    {
        return app(FleetService::class)->today($this->fleet());
    }

    public function currency(): string
    {
        return $this->fleet()->serviceArea?->currency ?? 'BBD';
    }
}
