<?php

namespace App\Filament\Pages;

use App\Support\Features;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** On/off switches and numbers for app features. The apps pick changes up on their next start — no app update. */
class FeatureSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'App features';

    protected static ?string $title = 'App features';

    protected static ?string $slug = 'app-features';

    protected static ?int $navigationSort = 1;

    public ?array $data = [];

    /** Form field name ↔ Features key (dots aren't allowed in Livewire state paths). */
    private static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function mount(): void
    {
        $state = [];
        foreach (array_keys(Features::DEFAULTS) as $k) {
            $state[self::field($k)] = is_bool(Features::DEFAULTS[$k]) ? Features::on($k) : Features::number($k);
        }
        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        $on = fn (string $k, string $label, ?string $help = null) => Toggle::make(self::field($k))->label($label)->helperText($help);
        $num = fn (string $k, string $label, string $suffix, ?string $help = null) => TextInput::make(self::field($k))->label($label)
            ->numeric()->minValue(0)->required()->suffix($suffix)->helperText($help);

        return $schema->statePath('data')->components([
            Section::make('Booking')->columns(2)->schema([
                $on('saved_places', 'Saved places (Home, Work…)'),
                $on('scheduled_rides', 'Book rides for later'),
                $num('scheduled.min_minutes', 'Earliest booking ahead', 'min'),
                $num('scheduled.max_days', 'Furthest booking ahead', 'days'),
                $num('scheduled.dispatch_lead_minutes', 'Start looking for a driver before pickup', 'min'),
            ]),
            Section::make('Tips')->columns(2)->schema([
                $on('tips', 'Riders and customers can tip drivers', '100% of tips go to the driver, paid by card.'),
                $num('tips.window_hours', 'Tip allowed for', 'hours after the ride'),
                $num('tips.max_amount', 'Largest tip', '$'),
            ]),
            Section::make('Promotions')->columns(2)->schema([
                $on('promos', 'Promo codes', 'Create codes under Marketing → Promo codes.'),
                $on('referrals', 'Invite a friend', 'Both get ride credit after the friend\'s first completed ride or order.'),
                $num('referral.referrer_credit', 'Credit for the person who invited', '$'),
                $num('referral.referee_credit', 'Credit for the new customer', '$'),
                $num('credit.max_share', 'Credit can pay up to', '× the fare (1 = all of it)'),
            ]),
            Section::make('Safety')->columns(2)->schema([
                $on('share_trip', 'Share my trip link'),
                $on('sos', 'SOS button (alerts admins, shows 211 / 511 / 311)'),
                $on('chat', 'Rider ↔ driver chat'),
                $on('masked_calls', 'Masked phone calls', 'Ride Coop rings the caller and connects them, so numbers stay private. Needs Twilio voice.'),
            ]),
            Section::make('Pickup')->columns(2)->schema([
                $num('pickup.max_distance_m', 'Driver must be within', 'm of the rider or the pickup',
                    'Before they can tap Arrived or Start. Measured to where the rider\'s phone is (they may have walked from the pin), or to the pickup pin. 0 = no check.'),
            ]),
            Section::make('Help & pricing')->columns(2)->schema([
                $on('support', 'Help pages and support tickets in the apps'),
                $on('auto_surge', 'Automatic surge', 'Per-area limits under Settings → Automatic surge.'),
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
        foreach (array_keys(Features::DEFAULTS) as $k) {
            if (array_key_exists(self::field($k), $d)) {
                Features::put($k, $d[self::field($k)]);
            }
        }
        Notification::make()->title('Saved — the apps use this from their next start.')->success()->send();
    }
}
