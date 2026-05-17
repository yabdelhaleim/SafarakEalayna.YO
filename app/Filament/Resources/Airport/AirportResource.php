<?php

namespace App\Filament\Resources\Airport;

use App\Models\Airport;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class AirportResource extends Resource
{
    protected static ?string $model = Airport::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $navigationLabel = 'المطارات';

    protected static ?string $modelLabel = 'مطار';

    protected static ?string $pluralModelLabel = 'المطارات';

    protected static string|\UnitEnum|null $navigationGroup = 'الطيران';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Schemas\Components\Section::make('الكودات')
                    ->schema([
                        Forms\Components\TextInput::make('iata_code')
                            ->label('كود IATA')
                            ->required()
                            ->maxLength(4)
                            ->unique(ignoreRecord: true)
                            ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null && $state !== '' ? strtoupper(trim($state)) : $state)
                            ->placeholder('مثال: CAI, JED, KWI, DXB')
                            ->helperText('كود IATA مكون من 3 أحرف'),

                        Forms\Components\TextInput::make('icao_code')
                            ->label('كود ICAO')
                            ->maxLength(4)
                            ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null && $state !== '' ? strtoupper(trim($state)) : $state)
                            ->placeholder('مثال: HECA, OEJN, OKBK, OMDB')
                            ->helperText('كود ICAO مكون من 4 أحرف'),
                    ])->columns(2),

                \Filament\Schemas\Components\Section::make('اسم المدينة')
                    ->schema([
                        Forms\Components\TextInput::make('city_name_ar')
                            ->label('اسم المدينة (عربي)')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('مثال: القاهرة، جدة، الكويت'),

                        Forms\Components\TextInput::make('city_name_en')
                            ->label('اسم المدينة (إنجليزي)')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('مثال: Cairo, Jeddah, Kuwait'),
                    ])->columns(2),

                \Filament\Schemas\Components\Section::make('اسم المطار')
                    ->schema([
                        Forms\Components\TextInput::make('airport_name_ar')
                            ->label('اسم المطار (عربي)')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('مثال: مطار القاهرة الدولي'),

                        Forms\Components\TextInput::make('airport_name_en')
                            ->label('اسم المطار (إنجليزي)')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('مثال: Cairo International Airport'),
                    ])->columns(2),

                \Filament\Schemas\Components\Section::make('الدولة')
                    ->schema([
                        Forms\Components\TextInput::make('country_code')
                            ->label('كود الدولة')
                            ->required()
                            ->maxLength(2)
                            ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null && $state !== '' ? strtoupper(trim($state)) : $state)
                            ->placeholder('مثال: EG, SA, KW, AE')
                            ->helperText('كود الدولة مكون من حرفين'),

                        Forms\Components\TextInput::make('country_name_ar')
                            ->label('اسم الدولة (عربي)')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('مثال: مصر، السعودية، الكويت'),

                        Forms\Components\TextInput::make('country_name_en')
                            ->label('اسم الدولة (إنجليزي)')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('مثال: Egypt, Saudi Arabia, Kuwait'),
                    ])->columns(3),

                \Filament\Schemas\Components\Section::make('معلومات إضافية')
                    ->schema([
                        Forms\Components\TextInput::make('latitude')
                            ->label('خط العرض')
                            ->numeric()
                            ->step(0.00000001)
                            ->placeholder('مثال: 30.121943')
                            ->helperText('إحداثيات المطار'),

                        Forms\Components\TextInput::make('longitude')
                            ->label('خط الطول')
                            ->numeric()
                            ->step(0.00000001)
                            ->placeholder('مثال: 31.405553')
                            ->helperText('إحداثيات المطار'),

                        Forms\Components\TextInput::make('timezone')
                            ->label('المنطقة الزمنية')
                            ->placeholder('مثال: Africa/Cairo')
                            ->helperText('منقة التوقيت حسب IANA'),
                    ])->columns(3),

                \Filament\Schemas\Components\Section::make('الحالة')
                    ->schema([
                        Forms\Components\Toggle::make('is_active')
                            ->label('نشط')
                            ->default(true)
                            ->inline(false),
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
                    ->color('primary')
                    ->size('lg'),

                Tables\Columns\TextColumn::make('city_name_ar')
                    ->label('المدينة')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('airport_name_ar')
                    ->label('المطار')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('country_name_ar')
                    ->label('الدولة')
                    ->badge()
                    ->color('info')
                    ->searchable(),

                Tables\Columns\TextColumn::make('icao_code')
                    ->label('ICAO')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإضافة')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('country_code')
                    ->label('الدولة')
                    ->options([
                        'EG' => 'مصر',
                        'SA' => 'السعودية',
                        'KW' => 'الكويت',
                        'AE' => 'الإمارات',
                        'QA' => 'قطر',
                        'BH' => 'البحرين',
                        'OM' => 'عمان',
                        'JO' => 'الأردن',
                        'LB' => 'لبنان',
                        'TR' => 'تركيا',
                        'GB' => 'بريطانيا',
                        'US' => 'أمريكا',
                        'DE' => 'ألمانيا',
                        'FR' => 'فرنسا',
                        'IT' => 'إيطاليا',
                    ])
                    ->searchable()
                    ->preload(),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('نشط')
                    ->placeholder('الكل')
                    ->trueLabel('نشط فقط')
                    ->falseLabel('غير نشط فقط'),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make(),
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('city_name_ar', 'asc')
            ->searchPlaceholder('ابحث بالكود أو اسم المدينة...');
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
}
