<?php

namespace App\Filament\Resources\ShopCategories;

use App\Filament\Resources\ShopCategories\Pages\ManageShopCategories;
use App\Models\ShopCategory;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/** What customers see first in the Shop. Drag to set the order. */
class ShopCategoryResource extends Resource
{
    protected static ?string $model = ShopCategory::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Shop';

    protected static ?string $navigationLabel = 'Categories';

    protected static ?string $modelLabel = 'shop category';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(64),
            TextInput::make('icon')->label('Emoji')->maxLength(16)->placeholder('🍔')->helperText('Shown when there is no picture.'),
            TextInput::make('description')->maxLength(255)->columnSpanFull()->placeholder('What customers can get here, e.g. "Meals, snacks and drinks"'),
            FileUpload::make('image_path')->label('Picture')->image()->disk('public')->directory('shop-categories')->visibility('public')->maxSize(4096)->columnSpanFull(),
            Toggle::make('is_active')->label('Shown to customers')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                ImageColumn::make('image_path')->label('')->disk('public')->imageHeight(40),
                TextColumn::make('icon')->label(''),
                TextColumn::make('name')->weight('medium')->description(fn (ShopCategory $r) => $r->description),
                TextColumn::make('stores_count')->counts('stores')->label('Stores'),
                ToggleColumn::make('is_active')->label('Shown'),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageShopCategories::route('/')];
    }
}
