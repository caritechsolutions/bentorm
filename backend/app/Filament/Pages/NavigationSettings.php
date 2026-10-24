<?php

namespace App\Filament\Pages;

use App\Models\AppSetting;
use App\Models\NavigationUsage;
use App\Services\NavigationPolicy;
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
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/** Switch in-app turn-by-turn on/off, cap it per month, and watch how many billed destinations were used. */
class NavigationSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'In-app navigation';

    protected static ?string $title = 'In-app navigation';

    protected static ?string $slug = 'navigation-settings';

    protected static ?int $navigationSort = 4;

    public ?array $data = [];

    public function mount(): void
    {
        $p = app(NavigationPolicy::class);
        $this->form->fill(['in_app' => $p->enabled(), 'monthly_limit' => $p->monthlyLimit()]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema->statePath('data')->components([
            Section::make('Turn-by-turn inside the driver app')->columns(2)->schema([
                Toggle::make('in_app')->label('Use in-app navigation')->columnSpanFull()
                    ->helperText('Off: the driver app opens the Google Maps app to navigate (free). On: guidance runs inside the driver app (Google Navigation SDK, billed per destination).'),
                TextInput::make('monthly_limit')->label('Monthly limit (destinations)')->numeric()->minValue(1)->nullable()
                    ->placeholder('No limit')
                    ->helperText('When reached, drivers go back to the Google Maps app until the 1st of next month. Each pickup, stop and drop-off is one destination.'),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        $p = app(NavigationPolicy::class);
        $used = $p->usedThisMonth();
        $limit = $p->monthlyLimit();
        $byType = NavigationUsage::query()->where('created_at', '>=', now()->startOfMonth())
            ->selectRaw('destination, count(*) as n')->groupBy('destination')->pluck('n', 'destination');
        $status = match ($p->blockedReason()) {
            null => 'On — drivers navigate inside the app.',
            'monthly_limit' => 'Monthly limit reached — drivers are using the Google Maps app until next month.',
            default => 'Off — drivers use the Google Maps app.',
        };

        return $schema->components([
            Section::make('Usage')->columns(3)->schema([
                Text::make('Status: '.$status)->columnSpanFull(),
                Text::make('This month: '.number_format($used).' destinations'.($limit ? ' of '.number_format($limit) : ''))->weight('bold'),
                Text::make('Last month: '.number_format($p->usedLastMonth())),
                Text::make('This month by type — pickup '.($byType['pickup'] ?? 0).' · stop '.($byType['stop'] ?? 0).' · drop-off '.($byType['dropoff'] ?? 0)),
                Text::make('Google gives '.number_format(NavigationPolicy::FREE_PER_MONTH).' destinations a month free (pricing from May 2025); above that each one is billed. Your Google Cloud billing page shows the actual charge.')->color('gray')->columnSpanFull(),
            ]),
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Save')->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        AppSetting::put('navigation.in_app', (bool) ($data['in_app'] ?? false));
        AppSetting::put('navigation.monthly_limit', filled($data['monthly_limit'] ?? null) ? (int) $data['monthly_limit'] : null);
        Notification::make()->title('Saved')->success()->send();
    }
}
