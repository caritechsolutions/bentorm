<?php

namespace App\Filament\Store;

use App\Models\ShopCategory;
use App\Services\Google\PlacesService;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;
use App\Models\Store;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ViewField;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/** Store details, shared by "Register your store" and "Store settings". */
final class StoreForm
{
    /** @return array<int, \Filament\Schemas\Components\Component> */
    public static function details(): array
    {
        return [
            Section::make('Your store')->columns(2)->schema([
                TextInput::make('name')->label('Store name')->required()->maxLength(120),
                Select::make('shop_category_id')->label('What you sell')->required()
                    ->options(fn () => ShopCategory::query()->where('is_active', true)->orderBy('sort_order')->pluck('name', 'id')),
                Textarea::make('description')->label('Short description')->rows(2)->maxLength(500)->columnSpanFull()
                    ->helperText('Shown to customers under your store name.'),
                TextInput::make('phone')->label('Store phone')->tel()->required()->maxLength(32),
                TextInput::make('email')->label('Store email')->email()->maxLength(255),
                FileUpload::make('logo_path')->label('Logo')->image()->disk('public')->directory('stores/logos')->visibility('public')->maxSize(4096)->imageEditor(),
                FileUpload::make('cover_path')->label('Cover photo')->image()->disk('public')->directory('stores/covers')->visibility('public')->maxSize(6144),
            ]),
            Section::make('Where you are')->schema([
                // Same Google address search as the rider app: pick a result and the address and pin fill in.
                Select::make('place_search')->label('Find your store')
                    ->placeholder('Type your store name or street, e.g. "Courts Oistins"')
                    ->searchable()->live()->dehydrated(false)
                    ->searchPrompt('Start typing your store name or street')
                    ->noSearchResultsMessage('Nothing found — type the address below and tap the map instead.')
                    ->getSearchResultsUsing(fn (string $search, Get $get) => self::placeOptions($search, $get('place_session')))
                    ->getOptionLabelUsing(fn ($value) => (string) $value) // cleared as soon as it's picked
                    ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                        if (! $state) {
                            return;
                        }
                        if ($place = self::placeDetails($state, $get('place_session'))) {
                            $set('address', $place['address'] ?: $place['name']);
                            $set('lat', round($place['lat'], 7));
                            $set('lng', round($place['lng'], 7));
                        }
                        $set('place_search', null);
                        $set('place_session', (string) Str::uuid()); // one Google session per search
                    })
                    ->helperText('Or tap the map below. You can drag the pin to the exact door.')
                    ->visible(fn () => PlacesService::configured()),
                Hidden::make('place_session')->default(fn () => (string) Str::uuid())->dehydrated(false),
                TextInput::make('address')->label('Address')->required()->maxLength(255),
                ViewField::make('lat')->label('Pin on the map')->view('filament.forms.location-pin')->required()
                    ->validationMessages(['required' => 'Tap the map where your store is.']),
                Hidden::make('lng')->required(),
            ]),
        ];
    }

    /** @return array<int, \Filament\Schemas\Components\Component> */
    public static function operations(): array
    {
        return [
            Section::make('Orders')->columns(3)->schema([
                TextInput::make('prep_minutes')->label('Usual preparation time')->numeric()->minValue(1)->maxValue(240)->default(15)->suffix('min')->required()
                    ->helperText('We send a driver to arrive about when the order is ready.'),
                TextInput::make('busy_extra_minutes')->label('Extra time when busy')->numeric()->minValue(0)->maxValue(120)->default(15)->suffix('min')->required(),
                TextInput::make('min_order')->label('Minimum order')->numeric()->minValue(0)->default(0)->prefix('$')->required(),
                TextInput::make('delivery_radius_km')->label('Deliver up to')->numeric()->minValue(1)->maxValue(60)->default(10)->suffix('km')->required(),
            ]),
            Section::make('Tax')->columns(2)->schema([
                Toggle::make('vat_registered')->label('We are VAT registered')->live(),
                Toggle::make('prices_include_vat')->label('Our prices include VAT')->default(true)
                    ->visible(fn (Get $get) => (bool) $get('vat_registered')),
            ]),
            Section::make('Opening hours')->description('Leave a day empty if you are closed that day. No hours at all = open whenever you switch the store open.')
                ->collapsible()->schema(array_map(fn (string $key, string $day) => Repeater::make("hours.{$key}")->label($day)
                    ->schema([Grid::make(2)->schema([
                        TimePicker::make('from')->seconds(false)->required(),
                        TimePicker::make('to')->seconds(false)->required(),
                    ])])
                    ->defaultItems(0)->maxItems(3)->addActionLabel("Add hours for {$day}")->reorderable(false), array_keys(Store::DAYS), Store::DAYS)),
        ];
    }

    /** Where Ride Coop pays the store's share of orders (owners only, on Store settings). */
    public static function payouts(): Section
    {
        return Section::make('Payouts')->description('Ride Coop pays your share of delivered orders to this account by bank transfer. See Statement for what is owed.')
            ->columns(2)->collapsible()->schema([
                TextInput::make('bank_name')->label('Bank')->maxLength(80),
                TextInput::make('bank_branch')->label('Branch')->maxLength(80),
                TextInput::make('bank_account_name')->label('Name on the account')->maxLength(120),
                TextInput::make('bank_account_number')->label('Account number')->maxLength(40)->regex('/^[0-9 \-]{4,40}$/'),
            ]);
    }

    /** @return array<string, string> place id => "Name — area" */
    public static function placeOptions(string $search, ?string $session): array
    {
        if (mb_strlen(trim($search)) < 2) {
            return [];
        }
        try {
            return collect(app(PlacesService::class)->autocomplete(trim($search), null, null, $session))
                ->mapWithKeys(fn (array $p) => [$p['place_id'] => trim($p['main_text'].($p['secondary_text'] ? ' — '.$p['secondary_text'] : ''))])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array{name: string, address: ?string, lat: float, lng: float}|null */
    public static function placeDetails(string $placeId, ?string $session): ?array
    {
        try {
            return app(PlacesService::class)->details($placeId, $session);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Repeater rows come keyed by random ids; store plain lists, dropping empty days. */
    public static function cleanHours(array $data): array
    {
        if (isset($data['hours']) && is_array($data['hours'])) {
            $clean = [];
            foreach (Store::DAYS as $key => $_) {
                $rows = array_values(array_filter((array) ($data['hours'][$key] ?? []), fn ($r) => filled($r['from'] ?? null) && filled($r['to'] ?? null)));
                if ($rows) {
                    $clean[$key] = array_map(fn ($r) => ['from' => substr((string) $r['from'], 0, 5), 'to' => substr((string) $r['to'], 0, 5)], $rows);
                }
            }
            $data['hours'] = $clean ?: null;
        }

        return $data;
    }
}
