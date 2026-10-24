<?php

namespace App\Filament\Resources\DocumentTypes;

use App\Filament\Resources\DocumentTypes\Pages\ManageDocumentTypes;
use App\Models\DocumentType;
use App\Models\ServiceArea;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class DocumentTypeResource extends Resource
{
    protected static ?string $model = DocumentType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Required documents';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->required()->maxLength(128),
            TextInput::make('key')->required()->maxLength(64)->alphaDash()->unique(ignoreRecord: true)->disabledOn('edit'),
            TextInput::make('description')->label('Instructions')->maxLength(255)->columnSpanFull(),
            Radio::make('applies_to')->label('Belongs to')->options(['driver' => 'Driver', 'vehicle' => 'Vehicle', 'store' => 'Store'])->inline()->required()->live(),
            Select::make('shop_category_id')->label('Only for stores in')->placeholder('Every store category')
                ->options(fn () => \App\Models\ShopCategory::query()->orderBy('sort_order')->pluck('name', 'id'))
                ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => $get('applies_to') === 'store'),
            Select::make('service_area_id')->label('Only in')->placeholder('Every area')
                ->options(fn () => ServiceArea::query()->where('type', 'service')->pluck('name', 'id')),
            Toggle::make('is_required')->label('Required')->default(true),
            Toggle::make('requires_number')->label('Needs a document number'),
            Toggle::make('requires_expiry')->label('Has an expiry date'),
            Toggle::make('is_image_only')->label('Must be a photo'),
            Toggle::make('visible_to_riders')->label('Riders can see it')->helperText('e.g. driver photo, vehicle front photo'),
            TextInput::make('sort_order')->numeric()->default(0),
            Toggle::make('is_active')->label('Active')->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')->weight('medium')->description(fn (DocumentType $record) => $record->description)->wrap(),
                TextColumn::make('applies_to')->badge()->color('gray'),
                TextColumn::make('serviceArea.code')->label('Area')->placeholder('All'),
                IconColumn::make('is_required')->label('Required')->boolean(),
                IconColumn::make('requires_expiry')->label('Expiry')->boolean(),
                IconColumn::make('requires_number')->label('Number')->boolean(),
                IconColumn::make('is_image_only')->label('Photo')->boolean(),
                IconColumn::make('visible_to_riders')->label('Rider-visible')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->filters([SelectFilter::make('applies_to')->options(['driver' => 'Driver', 'vehicle' => 'Vehicle', 'store' => 'Store'])])
            ->recordActions([EditAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageDocumentTypes::route('/')];
    }
}
