<?php

namespace App\Filament\Store;

use App\Models\ShopCategory;
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
