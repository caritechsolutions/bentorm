<?php

namespace App\Filament\Store\Resources\MenuSections;

use App\Filament\Store\Resources\MenuSections\Pages\ManageMenuSections;
use App\Models\MenuSection;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Groups on the store's menu: "Mains", "Drinks", "Phones"… Drag to reorder. */
class MenuSectionResource extends Resource
{
    protected static ?string $model = MenuSection::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Menu sections';

    protected static ?string $modelLabel = 'section';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(80)->placeholder('e.g. Mains, Drinks, Phones'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->weight('medium'),
                TextColumn::make('products_count')->counts('products')->label('Products'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading('No sections yet')
            ->emptyStateDescription('Sections group your products for customers, like "Mains" and "Drinks". Optional.');
    }

    public static function getPages(): array
    {
        return ['index' => ManageMenuSections::route('/')];
    }
}
