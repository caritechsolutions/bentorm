<?php

namespace App\Filament\Store\Resources\Products;

use App\Filament\Store\Resources\Products\Pages\ManageProducts;
use App\Models\MenuSection;
use App\Models\Product;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?int $navigationSort = 2;

    private static function sections(): array
    {
        return MenuSection::query()->whereBelongsTo(Filament::getTenant(), 'store')->orderBy('sort_order')->pluck('name', 'id')->all();
    }

    public static function form(Schema $schema): Schema
    {
        $currency = Filament::getTenant()?->serviceArea?->currency ?? 'BBD';

        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(120)->columnSpanFull(),
                Textarea::make('description')->rows(2)->maxLength(1000)->columnSpanFull(),
                TextInput::make('price')->required()->numeric()->minValue(0)->prefix($currency),
                Select::make('menu_section_id')->label('Section')->placeholder('No section')->options(fn () => self::sections()),
                FileUpload::make('photo_path')->label('Photo')->image()->disk('public')->directory('products')->visibility('public')
                    ->maxSize(4096)->imageEditor()->imageAspectRatio('4:3')->columnSpanFull(),
                Radio::make('size')->label('Size when packed')->options(Product::SIZES)->default('small')->required()
                    ->helperText('Decides which vehicles can deliver it.')->columnSpanFull(),
                Toggle::make('is_available')->label('Available (switch off when sold out)')->default(true)->columnSpanFull(),
            ]),
            Section::make('Options')->description('Choices customers make, e.g. Size (Small/Large) or Extras (Cheese +$2). Optional.')
                ->collapsible()->collapsed(fn (?Product $record) => empty($record?->options))->schema([
                    Repeater::make('options')->hiddenLabel()->defaultItems(0)->addActionLabel('Add an option group')
                        ->itemLabel(fn (array $state) => $state['name'] ?? null)->collapsible()
                        ->schema([
                            Grid::make(3)->schema([
                                TextInput::make('name')->label('Group name')->required()->placeholder('Size'),
                                Toggle::make('required')->label('Customer must choose')->inline(false),
                                Toggle::make('multiple')->label('Can pick several')->inline(false),
                            ]),
                            Repeater::make('choices')->required()->minItems(1)->addActionLabel('Add a choice')->columns(2)
                                ->schema([
                                    TextInput::make('name')->required()->placeholder('Large'),
                                    TextInput::make('price')->label('Extra cost')->numeric()->default(0)->prefix($currency),
                                ]),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                ImageColumn::make('photo_path')->label('')->disk('public')->imageHeight(48)->square(),
                TextColumn::make('name')->searchable()->weight('medium')->description(fn (Product $r) => $r->description ? str($r->description)->limit(60) : null),
                TextColumn::make('section.name')->label('Section')->placeholder('—'),
                TextColumn::make('price')->numeric(2)->sortable(),
                TextColumn::make('size')->badge()->color('gray'),
                ToggleColumn::make('is_available')->label('Available'),
            ])
            ->filters([
                SelectFilter::make('menu_section_id')->label('Section')->options(fn () => self::sections()),
                TernaryFilter::make('is_available')->label('Available'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No products yet')
            ->emptyStateDescription('Add what you sell, with a photo and price. Customers see it once your store is approved and open.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageProducts::route('/')];
    }
}
