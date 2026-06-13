<?php

namespace App\Filament\Admin\Resources\AccommodationTypes;

use App\Filament\Admin\Resources\AccommodationTypes\Pages\ManageAccommodationTypes;
use App\Models\HajjUmra\AccommodationType;
use BackedEnum;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AccommodationTypeResource extends Resource
{
    protected static ?string $model = AccommodationType::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-home-modern';

    protected static string|\UnitEnum|null $navigationGroup = 'الحج والعمرة';

    protected static ?string $navigationLabel = 'أنواع التسكين';
    protected static ?string $pluralLabel = 'أنواع التسكين';
    protected static ?string $modelLabel = 'نوع تسكين';
    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->label('الكود (إنجليزي مختصر)')
                ->required()
                ->maxLength(50)
                ->unique(ignoreRecord: true)
                ->helperText('مثال: single, double, triple, quad'),

            TextInput::make('name_ar')
                ->label('الاسم بالعربية')
                ->required()
                ->maxLength(100),

            TextInput::make('name_en')
                ->label('الاسم بالإنجليزية')
                ->maxLength(100),

            TextInput::make('capacity')
                ->label('السعة (عدد الأشخاص)')
                ->numeric()
                ->default(1)
                ->required(),

            TextInput::make('sort_order')
                ->label('ترتيب العرض')
                ->numeric()
                ->default(0),

            Toggle::make('is_active')
                ->label('مفعّل')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('code')->label('الكود')->searchable(),
                TextColumn::make('name_ar')->label('الاسم العربي')->searchable(),
                TextColumn::make('name_en')->label('الاسم الإنجليزي')->toggleable(),
                TextColumn::make('capacity')->label('السعة')->sortable(),
                TextColumn::make('sort_order')->label('الترتيب')->sortable()->toggleable(),
                IconColumn::make('is_active')->label('مفعّل')->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('مفعّل'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make(),
                BulkActionGroup::make([DeleteBulkAction::make()]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageAccommodationTypes::route('/'),
        ];
    }
}
