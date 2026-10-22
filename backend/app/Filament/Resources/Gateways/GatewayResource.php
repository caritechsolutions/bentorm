<?php

namespace App\Filament\Resources\Gateways;

use App\Filament\Resources\Gateways\Pages\CreateGateway;
use App\Filament\Resources\Gateways\Pages\EditGateway;
use App\Filament\Resources\Gateways\Pages\ListGateways;
use App\Models\Gateway;
use App\Models\ServiceArea;
use App\Plugins\PluginManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Payment / SMS / email gateways — configured instances of plugins. */
class GatewayResource extends Resource
{
    protected static ?string $model = Gateway::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Payment, SMS & email';

    protected static ?string $modelLabel = 'gateway';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        $plugins = app(PluginManager::class);
        $options = collect($plugins->available())->groupBy('type')
            ->mapWithKeys(fn ($items, $type) => [ucfirst($type) => $items->pluck('name', 'key')->all()])->all();

        return $schema->components([
            Section::make('Gateway')->columns(2)->columnSpanFull()->schema([
                Select::make('plugin')->required()->live()->options($options)->disabledOn('edit')
                    ->helperText(fn (Get $get) => $get('plugin') ? collect($plugins->available())->firstWhere('key', $get('plugin'))['description'] ?? null : null),
                TextInput::make('name')->required()->maxLength(128),
                Select::make('service_area_id')->label('Used in')->placeholder('Every area')
                    ->options(fn () => ServiceArea::query()->where('type', 'service')->pluck('name', 'id'))
                    ->helperText('An area-specific gateway takes precedence over an all-areas one.'),
                TextInput::make('priority')->numeric()->default(0)->helperText('Higher wins among gateways of the same type'),
                Toggle::make('is_active')->label('Active'),
            ]),
            Section::make('Settings')->columns(2)->columnSpanFull()
                ->visible(fn (Get $get) => filled($get('plugin')) && self::fields($get('plugin')) !== [])
                ->schema(fn (Get $get) => self::fields($get('plugin'))),
        ]);
    }

    /** Build form fields from the plugin's configSchema(). */
    public static function fields(?string $plugin): array
    {
        if (! $plugin) {
            return [];
        }
        try {
            $schema = app(PluginManager::class)->classFor($plugin)::configSchema();
        } catch (\Throwable) {
            return [];
        }

        return array_map(function (array $f) {
            $name = "config.{$f['key']}";
            $c = match ($f['type'] ?? 'string') {
                'bool' => Toggle::make($name),
                'select' => Select::make($name)->options(array_combine($f['options'] ?? [], $f['options'] ?? [])),
                'text' => Textarea::make($name)->rows(3)->columnSpanFull(),
                'secret' => TextInput::make($name)->password()->revealable()->placeholder('Leave blank to keep the saved value'),
                'number' => TextInput::make($name)->numeric(),
                'url' => TextInput::make($name)->url(),
                'email' => TextInput::make($name)->email(),
                default => TextInput::make($name),
            };
            $c = $c->label($f['label'] ?? $f['key'])->helperText($f['help'] ?? null);
            if (array_key_exists('default', $f) && ($f['type'] ?? '') !== 'secret') {
                $c = $c->default($f['default']);
            }

            return ($f['required'] ?? false) && ($f['type'] ?? '') !== 'secret' ? $c->required() : $c;
        }, $schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('type')
            ->columns([
                TextColumn::make('type')->badge()->color('gray'),
                TextColumn::make('name')->weight('medium')->description(fn (Gateway $record) => $record->plugin),
                TextColumn::make('serviceArea.name')->label('Area')->placeholder('All areas'),
                TextColumn::make('priority'),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('last_test_result')->label('Last test')->placeholder('Never tested')->wrap()
                    ->color(fn ($state) => str_starts_with((string) $state, 'OK') ? 'success' : 'danger')
                    ->description(fn (Gateway $record) => $record->last_tested_at?->diffForHumans()),
            ])
            ->recordActions([self::testAction(), EditAction::make()]);
    }

    public static function testAction(): Action
    {
        return Action::make('test')->label('Test')->icon(Heroicon::OutlinedBeaker)->color('gray')
            ->schema(fn (Gateway $record) => match ($record->type->value) {
                'sms' => [TextInput::make('to')->label('Send a test SMS to')->tel()->placeholder('+1246…')],
                'email' => [TextInput::make('to')->label('Send a test email to')->email()],
                default => [],
            })
            ->action(function (Gateway $record, array $data): void {
                try {
                    $result = app(PluginManager::class)->make($record)->test(array_filter(['to' => $data['to'] ?? null]));
                } catch (\Throwable $e) {
                    $result = ['ok' => false, 'message' => $e->getMessage()];
                }
                $record->forceFill(['last_tested_at' => now(), 'last_test_result' => mb_substr(($result['ok'] ? 'OK: ' : 'FAIL: ').$result['message'], 0, 255)])->save();
                Notification::make()->title($result['ok'] ? 'Test passed' : 'Test failed')->body($result['message'])
                    ->{$result['ok'] ? 'success' : 'danger'}()->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGateways::route('/'),
            'create' => CreateGateway::route('/create'),
            'edit' => EditGateway::route('/{record}/edit'),
        ];
    }
}
