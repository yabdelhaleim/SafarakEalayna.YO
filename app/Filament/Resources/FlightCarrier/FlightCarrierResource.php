<?php

namespace App\Filament\Resources\FlightCarrier;

use App\Filament\Resources\FlightCarrier\Pages;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class FlightCarrierResource extends Resource
{
    protected static ?string $model = FlightCarrier::class;

    protected static ?string $navigationIcon = 'heroicon-o-airplane';

    protected static ?string $navigationLabel = 'شركات الطيران';

    protected static ?string $modelLabel = 'شركة طيران';

    protected static ?string $pluralModelLabel = 'شركات الطيران';

    protected static ?string $navigationGroup = 'الطيران';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('معلومات الشركة')
                    ->schema([
                        Forms\Components\Select::make('flight_system_id')
                            ->label('النظام')
                            ->options(FlightSystem::all()->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->reactive()
                            ->afterStateUpdated(fn (callable $set) => $set('currency', 'KWD')),

                        Forms\Components\TextInput::make('name')
                            ->label('اسم الشركة')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('مثال: الجزيرة، العربية للطيران، نسما'),

                        Forms\Components\TextInput::make('code')
                            ->label('الكود')
                            ->required()
                            ->maxLength(10)
                            ->unique(ignoreRecord: true)
                            ->placeholder('مثال: JZ, SV, NS'),

                        Forms\Components\TextInput::make('iata_code')
                            ->label('كود IATA')
                            ->maxLength(3)
                            ->placeholder('مثال: KWI, JED, CAI')
                            ->helperText('كود IATA العالمي للشركة'),
                    ])->columns(2),

                Forms\Components\Section::make('المعلومات المالية')
                    ->schema([
                        Forms\Components\Select::make('currency')
                            ->label('العملة')
                            ->options([
                                'EGP' => 'جنيه مصري (EGP)',
                                'KWD' => 'دينار كويتي (KWD)',
                                'SAR' => 'ريال سعودي (SAR)',
                                'USD' => 'دولار أمريكي (USD)',
                            ])
                            ->default('KWD')
                            ->required()
                            ->live()
                            ->afterStateUpdated(function ($state, callable $set) {
                                // Update balance display based on currency
                                $set('balance', 0);
                            }),

                        Forms\Components\TextInput::make('balance')
                            ->label('الرصيد الحالي')
                            ->numeric()
                            ->prefix(fn ($get) => match($get('currency')) {
                                'EGP' => 'ج.م',
                                'KWD' => 'د.ك',
                                'SAR' => 'ر.س',
                                'USD' => '$',
                                default => '',
                            })
                            ->default(0)
                            ->disabled()
                            ->dehydrated(false)
                            ->helperText('هذا الـ Resource قديم. الرجاء استخدام "FlightCarriers" الجديد من القائمة الإدارية لشحن الرصيد وتسجيل القيد المحاسبي.'),

                        Forms\Components\TextInput::make('credit_limit')
                            ->label('حد الائتمان')
                            ->numeric()
                            ->prefix(fn ($get) => match($get('currency')) {
                                'EGP' => 'ج.م',
                                'KWD' => 'د.ك',
                                'SAR' => 'ر.س',
                                'USD' => '$',
                                default => '',
                            })
                            ->default(0)
                            ->helperText('الحد الأقصى للرصيد السلبي'),
                    ])->columns(3),

                Forms\Components\Section::make('معلومات إضافية')
                    ->schema([
                        Forms\Components\Textarea::make('notes')
                            ->label('ملاحظات')
                            ->rows(3)
                            ->placeholder('ملاحظات إضافية عن الشركة...'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('نشط')
                            ->default(true)
                            ->inline(false),
                    ])->columns(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->label('الكود')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                Tables\Columns\TextColumn::make('name')
                    ->label('اسم الشركة')
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('system.name')
                    ->label('النظام')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('currency')
                    ->label('العملة')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'EGP' => 'success',
                        'KWD' => 'warning',
                        'SAR' => 'info',
                        'USD' => 'primary',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('balance')
                    ->label('الرصيد')
                    ->money(fn ($record) => $record->currency)
                    ->sortable()
                    ->color(fn ($record) => $record->balance < 0 ? 'danger' : 'success'),

                Tables\Columns\TextColumn::make('available_balance')
                    ->label('الرصيد المتاح')
                    ->money(fn ($record) => $record->currency)
                    ->sortable()
                    ->badge()
                    ->color(fn ($record) => $record->available_balance > 0 ? 'success' : 'danger')
                    ->tooltip('الرصيد + حد الائتمان'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('نشط')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-x-circle')
                    ->trueColor('success')
                    ->falseColor('danger'),

                Tables\Columns\TextColumn::make('groups_count')
                    ->label('المجموعات')
                    ->counts('groups')
                    ->badge()
                    ->color('info'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('flight_system_id')
                    ->label('النظام')
                    ->relationship('system', 'name')
                    ->searchable()
                    ->preload(),

                Tables\Filters\SelectFilter::make('currency')
                    ->label('العملة')
                    ->options([
                        'EGP' => 'جنيه مصري',
                        'KWD' => 'دينار كويتي',
                        'SAR' => 'ريال سعودي',
                        'USD' => 'دولار أمريكي',
                    ]),

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
            ->defaultSort('name', 'asc');
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
            'index' => Pages\ListFlightCarriers::route('/'),
            'create' => Pages\CreateFlightCarrier::route('/create'),
            'edit' => Pages\EditFlightCarrier::route('/{record}/edit'),
        ];
    }
}
