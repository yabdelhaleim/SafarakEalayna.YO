<?php

namespace App\Filament\Admin\Resources\Airports;

use App\Filament\Admin\Concerns\BelongsToFlightModuleNavigation;
use App\Filament\Admin\Resources\Airports\Pages\CreateAirport;
use App\Filament\Admin\Resources\Airports\Pages\EditAirport;
use App\Filament\Admin\Resources\Airports\Pages\ListAirports;
use App\Models\Airport;
use App\Services\Airports\TravelpayoutsAirportAutocomplete;
use BackedEnum;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AirportResource extends Resource
{
    use BelongsToFlightModuleNavigation;

    protected static ?string $model = Airport::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-map-pin';

    protected static string|\UnitEnum|null $navigationGroup = 'الطيران';

    protected static ?string $navigationLabel = 'المطارات';

    protected static ?string $pluralLabel = 'المطارات';

    protected static ?string $modelLabel = 'مطار';

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'iata_code';

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::query()->where('is_active', true)->count();

        return (string) $count;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('airportTabs')
                    ->contained(true)
                    ->tabs([
                        Tab::make('import')
                            ->label('بحث أونلاين')
                            ->icon(Heroicon::OutlinedGlobeAlt)
                            ->schema([
                                Section::make('جلب بيانات من الإنترنت')
                                    ->description('اكتب اسم مدينة، مطار، أو رمز IATA (مثل CAI، DXB). المصدر: Travelpayouts — تُملأ التبويب التالي تلقائيًا؛ راجع الأسماء العربية قبل الحفظ.')
                                    ->schema([
                                        Select::make('_online_pick')
                                            ->label('نتائج البحث')
                                            ->searchable()
                                            ->searchDebounce(450)
                                            ->noSearchResultsMessage('لا توجد نتائج — جرّب حرفين على الأقل')
                                            ->getSearchResultsUsing(function (string $search): array {
                                                if (strlen(trim($search)) < 2) {
                                                    return [];
                                                }

                                                return app(TravelpayoutsAirportAutocomplete::class)->searchLabels($search);
                                            })
                                            ->getOptionLabelUsing(fn (?string $value): ?string => $value)
                                            ->dehydrated(false)
                                            ->live()
                                            ->afterStateUpdated(function (callable $set, $state): void {
                                                if (! is_string($state) || $state === '') {
                                                    return;
                                                }

                                                $row = app(TravelpayoutsAirportAutocomplete::class)->detailsByIata($state);
                                                if ($row === null) {
                                                    return;
                                                }

                                                foreach ($row as $key => $value) {
                                                    $set($key, $value);
                                                }

                                                $set('_online_pick', null);
                                            })
                                            ->columnSpanFull(),
                                    ]),
                            ]),
                        Tab::make('data')
                            ->label('البيانات الكاملة')
                            ->icon(Heroicon::OutlinedBuildingOffice2)
                            ->schema([
                                Section::make('الرموز والحالة')
                                    ->schema([
                                        TextInput::make('iata_code')
                                            ->label('كود IATA')
                                            ->required()
                                            ->maxLength(4)
                                            ->unique(ignoreRecord: true)
                                            ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                                            ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null && $state !== '' ? strtoupper(trim($state)) : $state)
                                            ->placeholder('CAI، JED، DXB'),
                                        TextInput::make('icao_code')
                                            ->label('كود ICAO')
                                            ->maxLength(4)
                                            ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                                            ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null && $state !== '' ? strtoupper(trim($state)) : $state)
                                            ->placeholder('HECA، OEJN'),
                                        Toggle::make('is_active')
                                            ->label('نشط')
                                            ->default(true)
                                            ->inline(false),
                                    ])
                                    ->columns(3),
                                Section::make('المدينة والمطار')
                                    ->schema([
                                        TextInput::make('city_name_en')
                                            ->label('المدينة (إنجليزي)')
                                            ->required()
                                            ->maxLength(255),
                                        TextInput::make('city_name_ar')
                                            ->label('المدينة (عربي)')
                                            ->required()
                                            ->maxLength(255),
                                        TextInput::make('airport_name_en')
                                            ->label('المطار (إنجليزي)')
                                            ->required()
                                            ->maxLength(255),
                                        TextInput::make('airport_name_ar')
                                            ->label('المطار (عربي)')
                                            ->required()
                                            ->maxLength(255),
                                    ])
                                    ->columns(2),
                                Section::make('الدولة')
                                    ->schema([
                                        TextInput::make('country_code')
                                            ->label('كود الدولة')
                                            ->required()
                                            ->maxLength(2)
                                            ->extraInputAttributes(['style' => 'text-transform: uppercase'])
                                            ->dehydrateStateUsing(fn (?string $state): ?string => $state !== null && $state !== '' ? strtoupper(trim($state)) : $state)
                                            ->placeholder('EG، SA'),
                                        TextInput::make('country_name_en')
                                            ->label('الدولة (إنجليزي)')
                                            ->required()
                                            ->maxLength(255),
                                        TextInput::make('country_name_ar')
                                            ->label('الدولة (عربي)')
                                            ->required()
                                            ->maxLength(255),
                                    ])
                                    ->columns(3),
                                Section::make('إحداثيات وتوقيت (اختياري)')
                                    ->schema([
                                        TextInput::make('latitude')
                                            ->label('خط العرض')
                                            ->numeric()
                                            ->step(0.00000001)
                                            ->minValue(-90)
                                            ->maxValue(90),
                                        TextInput::make('longitude')
                                            ->label('خط الطول')
                                            ->numeric()
                                            ->step(0.00000001)
                                            ->minValue(-180)
                                            ->maxValue(180),
                                        TextInput::make('timezone')
                                            ->label('المنطقة الزمنية')
                                            ->maxLength(255)
                                            ->placeholder('Africa/Cairo'),
                                    ])
                                    ->columns(3),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('iata_code')
            ->columns([
                TextColumn::make('iata_code')
                    ->label('IATA')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),
                TextColumn::make('city_name_en')
                    ->label('المدينة')
                    ->searchable()
                    ->sortable()
                    ->description(fn (Airport $record): string => $record->city_name_ar),
                TextColumn::make('airport_name_en')
                    ->label('المطار')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('country_name_en')
                    ->label('الدولة')
                    ->badge()
                    ->color('gray')
                    ->description(fn (Airport $record): string => $record->country_name_ar),
                IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label('تاريخ الإضافة')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('نشط')
                    ->placeholder('الكل')
                    ->trueLabel('نشط')
                    ->falseLabel('غير نشط'),
                SelectFilter::make('country_code')
                    ->label('الدولة')
                    ->options(fn (): array => Airport::query()
                        ->select('country_code', 'country_name_en')
                        ->distinct()
                        ->orderBy('country_name_en')
                        ->pluck('country_name_en', 'country_code')
                        ->all()),
            ])
            ->defaultSort('country_code')
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->modal(false),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                CreateAction::make(),
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAirports::route('/'),
            'create' => CreateAirport::route('/create'),
            'edit' => EditAirport::route('/{record}/edit'),
        ];
    }
}
