<?php

namespace App\Filament\Pages;

use App\Models\AppSetting;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** What Ride Coop earns on shop orders. Delivery fees themselves come from each area's "Delivery" rate card. */
class ShopSettings extends Page
{
    public const DEFAULTS = ['shop.commission_rate' => 0.15, 'shop.delivery_share' => 0.20, 'shop.service_fee_flat' => 0.0, 'shop.service_fee_rate' => 0.0,
        'shop.driver_lead_minutes' => 10, 'shop.driver_alert_minutes' => 10];

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Shop';

    protected static ?string $navigationLabel = 'Fees & commission';

    protected static ?string $title = 'Shop fees & commission';

    protected static ?string $slug = 'shop-settings';

    protected static ?int $navigationSort = 3;

    public ?array $data = [];

    public static function value(string $key): float
    {
        return (float) AppSetting::get($key, self::DEFAULTS[$key]);
    }

    public function mount(): void
    {
        $this->form->fill([
            'commission_rate' => round(self::value('shop.commission_rate') * 100, 2),
            'delivery_share' => round(self::value('shop.delivery_share') * 100, 2),
            'service_fee_flat' => self::value('shop.service_fee_flat'),
            'service_fee_rate' => round(self::value('shop.service_fee_rate') * 100, 2),
            'driver_lead_minutes' => (int) self::value('shop.driver_lead_minutes'),
            'driver_alert_minutes' => (int) self::value('shop.driver_alert_minutes'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $pct = fn (string $name, string $label, string $help) => TextInput::make($name)->label($label)->numeric()->minValue(0)->maxValue(100)->suffix('%')->required()->helperText($help);

        return $schema->statePath('data')->components([
            Section::make('From the store')->schema([
                $pct('commission_rate', 'Commission on items', 'Kept from the item total before paying the store. A store can have its own rate (Stores → Edit).'),
            ]),
            Section::make('From the delivery fee')->schema([
                $pct('delivery_share', 'Ride Coop share of the delivery fee', 'The rest goes to the driver. The fee itself is set in Areas & zones → rate cards → Delivery.'),
            ]),
            Section::make('From the customer')->columns(2)->schema([
                TextInput::make('service_fee_flat')->label('Service fee per order')->numeric()->minValue(0)->prefix('$')->required(),
                $pct('service_fee_rate', 'Service fee (% of items)', 'Added to the customer\'s total. Use either or both; 0 for none.'),
            ]),
            Section::make('Drivers for deliveries')->columns(2)->schema([
                TextInput::make('driver_lead_minutes')->label('Send a driver this many minutes before the food is ready')->numeric()->integer()->minValue(0)->maxValue(120)->suffix('min')->required()
                    ->helperText('About how long a driver takes to reach a store. "Ready" and "Need driver now" in the store portal send one straight away.'),
                TextInput::make('driver_alert_minutes')->label('Alert if no driver has taken it after')->numeric()->integer()->minValue(1)->maxValue(120)->suffix('min')->required()
                    ->helperText('The store, admins and customer are told; the customer may then cancel and nothing is charged. We keep looking.'),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Save')->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $d = $this->form->getState();
        AppSetting::put('shop.commission_rate', round(((float) $d['commission_rate']) / 100, 4));
        AppSetting::put('shop.delivery_share', round(((float) $d['delivery_share']) / 100, 4));
        AppSetting::put('shop.service_fee_flat', round((float) $d['service_fee_flat'], 2));
        AppSetting::put('shop.service_fee_rate', round(((float) $d['service_fee_rate']) / 100, 4));
        AppSetting::put('shop.driver_lead_minutes', (int) $d['driver_lead_minutes']);
        AppSetting::put('shop.driver_alert_minutes', (int) $d['driver_alert_minutes']);
        Notification::make()->title('Saved')->success()->send();
    }
}
