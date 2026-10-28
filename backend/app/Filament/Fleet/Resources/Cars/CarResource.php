<?php

namespace App\Filament\Fleet\Resources\Cars;

use App\Enums\VehicleStatus;
use App\Filament\Fleet\Resources\Cars\Pages\ManageCars;
use App\Filament\Support\Ui;
use App\Models\DocumentType;
use App\Models\Fleet;
use App\Models\Vehicle;
use App\Models\VehicleType;
use App\Services\ComplianceService;
use App\Services\DocumentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

/**
 * The fleet's cars. Each one (and its documents) is checked by Ride Coop before it can be driven; changing an
 * approved car sends it back for a check. Who drives which car is set on Drivers.
 */
class CarResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static ?string $tenantRelationshipName = 'vehicles';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Cars';

    protected static ?string $modelLabel = 'car';

    protected static ?string $slug = 'cars';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'plate';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextInput::make('plate')->label('Number plate')->required()->maxLength(16)
                ->dehydrateStateUsing(fn ($state) => strtoupper(preg_replace('/[\s-]+/', '', (string) $state)))
                ->rule(fn (?Vehicle $record) => function (string $attr, $value, $fail) use ($record) {
                    $plate = strtoupper(preg_replace('/[\s-]+/', '', (string) $value));
                    if (Vehicle::query()->where('plate', $plate)->when($record, fn ($q) => $q->whereKeyNot($record->id))->exists()) {
                        $fail('This plate is already registered with Ride Coop. If it is your car and a driver added it, ask Ride Coop to move it into your fleet.');
                    }
                }),
            TextInput::make('make')->required()->maxLength(64),
            TextInput::make('model')->required()->maxLength(64),
            TextInput::make('year')->numeric()->minValue(1990)->maxValue(now()->year + 1),
            TextInput::make('colour')->maxLength(32),
            Select::make('vehicle_type')->label('Type')->required()
                ->options(fn () => VehicleType::query()->rides()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'key')),
            TextInput::make('seats')->label('Passenger seats')->numeric()->required()->minValue(1)->maxValue(16)->default(4),
            TextInput::make('luggage_capacity')->label('Luggage (bags)')->numeric()->required()->minValue(0)->maxValue(20)->default(2),
            CheckboxList::make('features')->options(Ui::features())->columns(2)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('documents'))
            ->defaultSort('plate')
            ->columns([
                TextColumn::make('plate')->weight('bold')->searchable(),
                TextColumn::make('make')->label('Car')->formatStateUsing(fn ($s, Vehicle $r) => trim("{$r->make} {$r->model}".($r->year ? " ({$r->year})" : '')))
                    ->description(fn (Vehicle $r) => $r->colour)->searchable(['make', 'model']),
                TextColumn::make('vehicle_type')->label('Type')->badge()->color('gray'),
                TextColumn::make('status')->badge()->color(fn ($state) => Ui::color($state))->formatStateUsing(fn ($state) => match ($state) {
                    VehicleStatus::Pending => 'Being checked', default => Ui::label($state),
                })->description(fn (Vehicle $r) => $r->status_note),
                TextColumn::make('documents_count')->label('Documents')
                    ->formatStateUsing(fn ($s, Vehicle $r) => self::docSummary($r)[0])
                    ->color(fn ($s, Vehicle $r) => self::docSummary($r)[1])
                    ->tooltip(fn (Vehicle $r) => implode(' ', app(ComplianceService::class)->vehicleIssues($r)) ?: null),
                TextColumn::make('id')->label('Driving now')->formatStateUsing(fn ($s, Vehicle $r) => $r->inUseBy()?->name ?? '—'),
            ])
            ->recordActions([
                self::uploadAction(),
                EditAction::make()->using(fn (Vehicle $record, array $data) => self::save($record, $data)),
                DeleteAction::make()->label('Remove')
                    ->modalDescription('The car is taken out of your fleet. Its trips stay on your statement.')
                    ->visible(fn (Vehicle $r) => ! $r->inUseBy()),
            ]);
    }

    /** "3 needed · 1 being checked" / "All approved", and its colour. @return array{0: string, 1: string} */
    public static function docSummary(Vehicle $car): array
    {
        $rows = collect(app(ComplianceService::class)->checklist($car))->filter(fn ($r) => $r['type']->is_required);
        $needed = $rows->filter(fn ($r) => in_array($r['state'], ['missing', 'rejected', 'expired'], true))->count();
        $checking = $rows->where('state', 'pending')->count();
        if (! $needed && ! $checking) {
            return ['All approved', 'success'];
        }

        return [implode(' · ', array_filter([$needed ? "{$needed} needed" : null, $checking ? "{$checking} being checked" : null])), $needed ? 'danger' : 'warning'];
    }

    /** Editing an approved car sends it back for a check. */
    public static function save(Vehicle $car, array $data): Vehicle
    {
        $car->fill($data);
        if ($car->isDirty() && $car->status === VehicleStatus::Approved) {
            abort_if($car->inUseBy() !== null, 409, 'Someone is driving this car. Change it when it is off the road.');
            $car->status = VehicleStatus::Pending;
        }
        $car->save();

        return $car;
    }

    public static function uploadAction(): Action
    {
        return Action::make('upload')->label('Documents')->icon(Heroicon::OutlinedArrowUpTray)->color('gray')
            ->modalHeading(fn (Vehicle $record) => "Documents for {$record->plate}")
            ->modalDescription('Photos or PDFs. Ride Coop checks these before the car can be driven.')
            ->schema(fn (Vehicle $record) => [
                Select::make('type')->label('Document')->required()->live()
                    ->options(fn () => collect(app(ComplianceService::class)->checklist($record))->mapWithKeys(fn ($row) => [$row['type']->id => $row['type']->name.' — '.match ($row['state']) {
                        'missing' => $row['type']->is_required ? 'needed' : 'not uploaded',
                        'pending' => 'being checked', 'approved' => 'approved', 'expiring_soon' => 'expires soon',
                        'expired' => 'expired', 'rejected' => 'not accepted'.($row['document']?->review_note ? ': '.$row['document']->review_note : ''),
                        default => $row['state'],
                    }])->all()),
                FileUpload::make('file')->label('Photo or PDF')->required()->storeFiles(false)->maxSize(10240)->acceptedFileTypes(['image/*', 'application/pdf']),
                TextInput::make('number')->label('Document number')->maxLength(64)
                    ->visible(fn (Get $get) => (bool) DocumentType::query()->find($get('type'))?->requires_number)
                    ->required(fn (Get $get) => (bool) DocumentType::query()->find($get('type'))?->requires_number),
                DatePicker::make('expires_at')->label('Expiry date')->minDate(now())
                    ->visible(fn (Get $get) => (bool) DocumentType::query()->find($get('type'))?->requires_expiry)
                    ->required(fn (Get $get) => (bool) DocumentType::query()->find($get('type'))?->requires_expiry),
            ])
            ->action(function (Vehicle $record, array $data): void {
                $type = DocumentType::query()->where('applies_to', 'vehicle')->findOrFail($data['type']);
                abort_unless($data['file'] instanceof UploadedFile, 422);
                app(DocumentService::class)->upload($record, $type, $data['file'], $data['number'] ?? null, $data['expires_at'] ?? null, Filament::auth()->user());
                Notification::make()->title('Uploaded — we\'ll check it shortly')->success()->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => ManageCars::route('/')];
    }
}
