<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FlightResource\Pages;
use App\Models\Flight;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

class FlightResource extends Resource
{
    protected static ?string $model = Flight::class;
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-paper-airplane';
    protected static string|\UnitEnum|null $navigationGroup = 'إدارة الرحلات';
    protected static ?string $modelLabel = 'رحلة';
    protected static ?string $pluralModelLabel = 'الرحلات';
    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            \Filament\Schemas\Components\Section::make('معلومات الرحلة')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('flight_number')
                        ->label('رقم الرحلة')
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->placeholder('MS-701'),

                    Forms\Components\TextInput::make('airline')
                        ->label('شركة الطيران')
                        ->required(),

                    Forms\Components\Select::make('origin')
                        ->label('مطار المغادرة')
                        ->options(self::getAirports())
                        ->searchable()
                        ->required(),

                    Forms\Components\Select::make('destination')
                        ->label('مطار الوصول')
                        ->options(self::getAirports())
                        ->searchable()
                        ->required(),

                    Forms\Components\DateTimePicker::make('departure_at')
                        ->label('موعد الإقلاع')
                        ->required()
                        ->native(false),

                    Forms\Components\DateTimePicker::make('arrival_at')
                        ->label('موعد الوصول')
                        ->required()
                        ->after('departure_at')
                        ->native(false),
                ]),

            \Filament\Schemas\Components\Section::make('التسعير والمقاعد')
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('class')
                        ->label('درجة السفر')
                        ->options([
                            'economy'  => 'اقتصادية',
                            'business' => 'رجال أعمال',
                            'first'    => 'درجة أولى',
                        ])
                        ->required(),

                    Forms\Components\TextInput::make('total_seats')
                        ->label('إجمالي المقاعد')
                        ->numeric()
                        ->required(),

                    Forms\Components\TextInput::make('available_seats')
                        ->label('المقاعد المتاحة')
                        ->numeric()
                        ->required(),

                    Forms\Components\TextInput::make('base_price')
                        ->label('السعر الأساسي (ج.م)')
                        ->numeric()
                        ->prefix('ج.م')
                        ->required(),

                    Forms\Components\TextInput::make('tax_percent')
                        ->label('نسبة الضريبة %')
                        ->numeric()
                        ->suffix('%')
                        ->default(14),

                    Forms\Components\Select::make('status')
                        ->label('الحالة')
                        ->options([
                            'scheduled' => 'مجدولة',
                            'boarding'  => 'جاري الصعود',
                            'departed'  => 'أقلعت',
                            'arrived'   => 'وصلت',
                            'cancelled' => 'ملغاة',
                        ])
                        ->default('scheduled')
                        ->required(),
                ]),

            Forms\Components\Textarea::make('notes')
                ->label('ملاحظات')
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('flight_number')
                    ->label('رقم الرحلة')
                    ->searchable()
                    ->weight('bold')
                    ->color('primary'),

                Tables\Columns\TextColumn::make('airline')
                    ->label('شركة الطيران')
                    ->searchable(),

                Tables\Columns\TextColumn::make('route')
                    ->label('المسار')
                    ->getStateUsing(fn ($record) => "{$record->origin} ← {$record->destination}"),

                Tables\Columns\TextColumn::make('departure_at')
                    ->label('الإقلاع')
                    ->dateTime('d M Y - h:i A')
                    ->sortable(),

                Tables\Columns\TextColumn::make('base_price')
                    ->label('السعر')
                    ->money('EGP')
                    ->sortable(),

                Tables\Columns\TextColumn::make('available_seats')
                    ->label('مقاعد متاحة')
                    ->badge()
                    ->color(fn ($state) => match(true) {
                        $state > 20 => 'success',
                        $state > 5  => 'warning',
                        default     => 'danger',
                    }),

                Tables\Columns\TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match($state) {
                        'scheduled' => 'مجدولة',
                        'boarding'  => 'جاري الصعود',
                        'departed'  => 'أقلعت',
                        'arrived'   => 'وصلت',
                        'cancelled' => 'ملغاة',
                        default => $state,
                    })
                    ->color(fn ($state) => match($state) {
                        'scheduled' => 'info',
                        'boarding'  => 'warning',
                        'departed'  => 'primary',
                        'arrived'   => 'success',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'scheduled' => 'مجدولة',
                        'boarding'  => 'جاري الصعود',
                        'arrived'   => 'وصلت',
                        'cancelled' => 'ملغاة',
                    ]),

                Tables\Filters\Filter::make('departure_today')
                    ->label('رحلات اليوم')
                    ->query(fn ($query) => $query->whereDate('departure_at', today())),

                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                \Filament\Actions\ViewAction::make()->label('عرض'),
                \Filament\Actions\EditAction::make()->label('تعديل'),
                \Filament\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make()->label('حذف المحدد'),
                ]),
            ])
            ->defaultSort('departure_at', 'asc')
            ->striped();
    }

    private static function getAirports(): array
    {
        return [
            'CAI' => 'القاهرة الدولي (CAI)',
            'DXB' => 'دبي الدولي (DXB)',
            'IST' => 'إسطنبول (IST)',
            'LHR' => 'لندن هيثرو (LHR)',
            'JFK' => 'نيويورك (JFK)',
            'CDG' => 'باريس (CDG)',
            'BEY' => 'بيروت (BEY)',
            'RUH' => 'الرياض (RUH)',
            'KWI' => 'الكويت (KWI)',
            'AMM' => 'عمّان (AMM)',
        ];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListFlights::route('/'),
            'create' => Pages\CreateFlight::route('/create'),
            'view'   => Pages\ViewFlight::route('/{record}'),
            'edit'   => Pages\EditFlight::route('/{record}/edit'),
        ];
    }
}
