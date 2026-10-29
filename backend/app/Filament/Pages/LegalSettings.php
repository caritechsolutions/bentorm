<?php

namespace App\Filament\Pages;

use App\Models\AppSetting;
use App\Support\LegalPages;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TextInput;
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

/** Terms of Use and Privacy Policy (public at /terms and /privacy; linked from the apps and the store listings). */
class LegalSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Legal pages';

    protected static ?string $title = 'Terms & privacy';

    protected static ?string $slug = 'legal-pages';

    protected static ?int $navigationSort = 9;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'contact_email' => AppSetting::get('legal.contact_email'),
            'terms' => LegalPages::markdown('terms'),
            'privacy' => LegalPages::markdown('privacy'),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        $draft = fn (string $p) => LegalPages::isDraft($p) ? 'Draft text — have your lawyer check it, then Save to publish your version.' : 'Published.';

        return $schema->statePath('data')->components([
            Section::make('Contact')->schema([
                TextInput::make('contact_email')->label('Email for privacy and legal questions')->email()->maxLength(120)
                    ->helperText('Shown at the end of both pages. Leave empty to point people to Help in the app.'),
                Text::make('Public links: '.url('/terms').' · '.url('/privacy').' · account deletion '.url('/account/delete'))->color('gray'),
            ]),
            Section::make('Terms of Use')->description($draft('terms'))->collapsible()->schema([
                MarkdownEditor::make('terms')->hiddenLabel()->required()
                    ->helperText('{{date}} = date last saved, {{delete_url}} = the delete-account page, {{contact}} = the contact line.'),
            ]),
            Section::make('Privacy Policy')->description($draft('privacy'))->collapsible()->schema([
                MarkdownEditor::make('privacy')->hiddenLabel()->required(),
            ]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])->id('form')->livewireSubmitHandler('save')
                ->footer([Actions::make([Action::make('save')->label('Save and publish')->submit('save')])]),
        ]);
    }

    public function save(): void
    {
        $d = $this->form->getState();
        AppSetting::put('legal.contact_email', filled($d['contact_email'] ?? null) ? trim($d['contact_email']) : null);
        foreach (array_keys(LegalPages::PAGES) as $p) {
            if (trim((string) $d[$p]) !== trim(LegalPages::markdown($p)) || LegalPages::isDraft($p)) {
                AppSetting::put("legal.{$p}", (string) $d[$p]);
                AppSetting::put("legal.{$p}_updated", now()->format('j F Y'));
            }
        }
        Notification::make()->title('Saved — /terms and /privacy show it now.')->success()->send();
    }
}
