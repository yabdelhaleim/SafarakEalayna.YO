<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AirportResource\Pages;
use App\Models\Airport;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class AirportResource extends Resource
{
    protected static ?string $model = Airport::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?string $navigationLabel = 'المطارات';

    protected static ?string $modelLabel = 'مطار';

    protected static ?string $pluralModelLabel = 'المطارات';

    protected static string|\UnitEnum|null $navigationGroup = 'الطيران';

    protected static ?int $navigationSort = 5;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Schemas\Components\Section::make('معلومات المطار')
                    ->description('بيانات المطار الأساسية')
                    ->schema([
                        Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('iata_code')
                                    ->label('كود IATA')
                                    ->required()
                                    ->maxLength(4)
                                    ->unique(ignoreRecord: true)
                                    ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                                    ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null && $state !== '' ? strtoupper(trim($state)) : $state)
                                    ->helperText('مثال: CAI, JED, DXB'),

                                Forms\Components\TextInput::make('icao_code')
                                    ->label('كود ICAO')
                                    ->maxLength(4)
                                    ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                                    ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null && $state !== '' ? strtoupper(trim($state)) : $state)
                                    ->helperText('مثال: HECA, OEJN, OMDB'),

                                Forms\Components\Toggle::make('is_active')
                                    ->label('نشط')
                                    ->default(true)
                                    ->inline(false),
                            ]),

                        Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('city_name_en')
                                    ->label('اسم المدينة (إنجليزي)')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('city_name_ar')
                                    ->label('اسم المدينة (عربي)')
                                    ->required()
                                    ->maxLength(255),
                            ]),

                        Grid::make(2)
                            ->schema([
                                Forms\Components\TextInput::make('airport_name_en')
                                    ->label('اسم المطار (إنجليزي)')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('airport_name_ar')
                                    ->label('اسم المطار (عربي)')
                                    ->required()
                                    ->maxLength(255),
                            ]),

                        Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('country_code')
                                    ->label('كود الدولة')
                                    ->required()
                                    ->maxLength(2)
                                    ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                                    ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null && $state !== '' ? strtoupper(trim($state)) : $state)
                                    ->helperText('مثال: EG, SA, KW'),

                                Forms\Components\TextInput::make('country_name_en')
                                    ->label('اسم الدولة (إنجليزي)')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('country_name_ar')
                                    ->label('اسم الدولة (عربي)')
                                    ->required()
                                    ->maxLength(255),
                            ]),

                        Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('latitude')
                                    ->label('خط العرض')
                                    ->numeric()
                                    ->step(0.00000001)
                                    ->maxValue(90)
                                    ->minValue(-90),

                                Forms\Components\TextInput::make('longitude')
                                    ->label('خط الطول')
                                    ->numeric()
                                    ->step(0.00000001)
                                    ->maxValue(180)
                                    ->minValue(-180),

                                Forms\Components\TextInput::make('timezone')
                                    ->label('التوقيت')
                                    ->helperText('مثال: Africa/Cairo')
                                    ->maxLength(255),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('iata_code')
                    ->label('IATA')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('city_name_en')
                    ->label('المدينة')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Airport $record): string => $record->city_name_ar),

                Tables\Columns\TextColumn::make('country_name_en')
                    ->label('الدولة')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('gray')
                    ->description(fn (Airport $record): string => $record->country_name_ar),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d M Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('نشط'),

                Tables\Filters\SelectFilter::make('country_code')
                    ->label('الدولة')
                    ->options(fn (): array => Airport::select('country_code', 'country_name_en')
                        ->distinct()
                        ->pluck('country_name_en', 'country_code')
                        ->toArray()),
            ])
            ->actions([
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('country_code')
            ->reorderable('sort');
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAirports::route('/'),
            'create' => Pages\CreateAirport::route('/create'),
            'edit' => Pages\EditAirport::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return cache()->remember('airports_active_count', now()->addMinutes(5), fn () => static::getModel()::active()->count());
    }
}
