<?php

namespace App\Filament\Resources\HelpArticles;

use App\Filament\Resources\HelpArticles\Pages\ManageHelpArticles;
use App\Models\HelpArticle;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** The Help pages riders and drivers see inside the apps. Changes show straight away — no app update. */
class HelpArticleResource extends Resource
{
    protected static ?string $model = HelpArticle::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Help pages';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextInput::make('title')->required()->maxLength(255)->columnSpan(2),
            Select::make('audience')->options(['all' => 'Everyone', 'rider' => 'Riders & customers', 'driver' => 'Drivers'])->default('all')->required(),
            TextInput::make('category')->required()->maxLength(60)->default('General')
                ->datalist(fn () => HelpArticle::query()->distinct()->pluck('category')->all()),
            TextInput::make('sort_order')->numeric()->default(0),
            Toggle::make('is_published')->label('Published')->default(true)->inline(false),
            MarkdownEditor::make('body')->required()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('category')
            ->columns([
                TextColumn::make('title')->searchable()->weight('medium')->description(fn (HelpArticle $r) => $r->category),
                TextColumn::make('audience')->badge()->formatStateUsing(fn ($state) => ['all' => 'Everyone', 'rider' => 'Riders', 'driver' => 'Drivers'][$state] ?? $state),
                TextColumn::make('updated_at')->label('Updated')->since(),
                ToggleColumn::make('is_published')->label('Published'),
            ])
            ->filters([SelectFilter::make('audience')->options(['all' => 'Everyone', 'rider' => 'Riders', 'driver' => 'Drivers'])])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageHelpArticles::route('/')];
    }
}
