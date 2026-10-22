<?php

namespace App\Filament\Support;

use App\Enums\DriverStatus;
use App\Enums\VehicleStatus;
use App\Models\DriverProfile;
use App\Models\Vehicle;
use App\Services\ComplianceService;
use App\Services\DriverLocationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;

/** Approve / suspend / reject for drivers and vehicles, enforcing the document rules (with an explicit override). */
final class ApprovalActions
{
    /** @return Action[] */
    public static function forDriver(): array
    {
        return [
            Action::make('approve')->label('Approve driver')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (DriverProfile $record) => $record->status !== DriverStatus::Approved)
                ->modalHeading('Approve driver')
                ->modalDescription(fn (DriverProfile $record) => self::issuesHtml(app(ComplianceService::class)->driverIssues($record)))
                ->schema(fn (DriverProfile $record) => app(ComplianceService::class)->driverIssues($record)
                    ? [Toggle::make('force')->label('Approve anyway (override missing documents)')->required()->accepted()]
                    : [])
                ->action(function (DriverProfile $record): void {
                    $record->update(['status' => DriverStatus::Approved, 'status_note' => null, 'approved_at' => now(), 'approved_by' => auth()->id()]);
                    Notification::make()->title("{$record->user?->name} approved")->success()->send();
                }),
            self::statusAction('suspend', 'Suspend', 'warning', Heroicon::OutlinedPauseCircle, DriverStatus::Suspended),
            self::statusAction('reject', 'Reject application', 'danger', Heroicon::OutlinedNoSymbol, DriverStatus::Rejected),
        ];
    }

    /** @return Action[] */
    public static function forVehicle(): array
    {
        return [
            Action::make('approve')->label('Approve vehicle')->icon(Heroicon::OutlinedCheckBadge)->color('success')
                ->visible(fn (Vehicle $record) => $record->status !== VehicleStatus::Approved)
                ->modalDescription(fn (Vehicle $record) => self::issuesHtml(app(ComplianceService::class)->vehicleIssues($record),
                    'Check the photos match the declared seats, luggage space and features: '.(implode(', ', array_map(fn ($f) => Ui::features()[$f] ?? $f, $record->features ?? [])) ?: 'none').'.'))
                ->schema(fn (Vehicle $record) => app(ComplianceService::class)->vehicleIssues($record)
                    ? [Toggle::make('force')->label('Approve anyway (override missing documents)')->required()->accepted()]
                    : [])
                ->action(function (Vehicle $record): void {
                    $record->update(['status' => VehicleStatus::Approved, 'status_note' => null, 'approved_at' => now()]);
                    Notification::make()->title("{$record->plate} approved")->success()->send();
                }),
            Action::make('suspend')->label('Suspend')->icon(Heroicon::OutlinedPauseCircle)->color('warning')
                ->visible(fn (Vehicle $record) => $record->status === VehicleStatus::Approved)
                ->schema([Textarea::make('note')->label('Reason (shown to the driver)')->required()])
                ->action(fn (Vehicle $record, array $data) => $record->update(['status' => VehicleStatus::Suspended, 'status_note' => $data['note']])),
            Action::make('reject')->label('Reject')->icon(Heroicon::OutlinedNoSymbol)->color('danger')
                ->visible(fn (Vehicle $record) => $record->status === VehicleStatus::Pending)
                ->schema([Textarea::make('note')->label('Reason (shown to the driver)')->required()])
                ->action(fn (Vehicle $record, array $data) => $record->update(['status' => VehicleStatus::Rejected, 'status_note' => $data['note']])),
        ];
    }

    private static function statusAction(string $name, string $label, string $color, $icon, DriverStatus $to): Action
    {
        return Action::make($name)->label($label)->icon($icon)->color($color)
            ->visible(fn (DriverProfile $record) => $record->status !== $to)
            ->schema([Textarea::make('note')->label('Reason (shown to the driver)')->required()->maxLength(500)])
            ->action(function (DriverProfile $record, array $data) use ($to): void {
                $record->update(['status' => $to, 'status_note' => $data['note']]);
                if ($record->is_online && $record->user && ! $record->user->activeTrip()) {
                    $record->update(['is_online' => false]);
                    app(DriverLocationService::class)->goOffline($record->user);
                }
                Notification::make()->title(($record->user?->name ?? 'Driver').' '.strtolower(Ui::label($to)))->warning()->send();
            });
    }

    private static function issuesHtml(array $issues, ?string $extra = null): HtmlString
    {
        $html = $issues
            ? '<p style="margin-bottom:.5rem"><strong>Not everything required is approved:</strong></p><ul style="list-style:disc;padding-left:1.25rem">'
              .implode('', array_map(fn ($i) => '<li>'.e($i).'</li>', $issues)).'</ul>'
            : '<p>All required documents are approved.</p>';

        return new HtmlString($html.($extra ? '<p style="margin-top:.75rem">'.e($extra).'</p>' : ''));
    }
}
