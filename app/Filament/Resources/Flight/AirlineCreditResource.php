<?php

namespace App\Filament\Resources\Flight;

use App\Filament\Resources\Flight\AirlineCreditResource\Pages;
use App\Models\Flight\AirlineCredit;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AirlineCreditResource extends Resource
{
    protected static ?string $model = AirlineCredit::class;

    protected static ?string $navigationIcon = 'heroicon-o-ticket';

    protected static ?string $navigationLabel = 'أرصدة الطيران المستردة';

    protected static ?string $modelLabel = 'رصيد طيران';

    protected static ?string $pluralModelLabel = 'أرصدة الطيران المستردة';

    protected static string|\UnitEnum|null $navigationGroup = 'الطيران';

    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('معلومات رصيد الطيران')
                    ->schema([
                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('flight_carrier_id')
                                    ->label('شركة الطيران (الناقل)')
                                    ->relationship('carrier', 'name')
                                    ->required()
                                    ->searchable(),

                                Forms\Components\Select::make('customer_id')
                                    ->label('العميل')
                                    ->relationship('customer', 'full_name')
                                    ->searchable()
                                    ->nullable(),
                            ]),

                        Forms\Components\Grid::make(3)
                            ->schema([
                                Forms\Components\TextInput::make('currency')
                                    ->label('العملة')
                                    ->required()
                                    ->maxLength(3),

                                Forms\Components\TextInput::make('amount')
                                    ->label('المبلغ')
                                    ->required()
                                    ->numeric(),

                                Forms\Components\DatePicker::make('expiry_date')
                                    ->label('تاريخ الصلاحية')
                                    ->nullable(),
                            ]),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\Select::make('flight_booking_id')
                                    ->label('الحجز الأصلي')
                                    ->relationship('booking', 'booking_number')
                                    ->required()
                                    ->searchable(),

                                Forms\Components\Select::make('status')
                                    ->label('الحالة')
                                    ->options([
                                        'active' => 'نشط ومتاح للاستخدام',
                                        'used' => 'مستعمل بالكامل',
                                        'expired' => 'منتهي الصلاحية',
                                    ])
                                    ->default('active')
                                    ->required(),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('carrier.name')
                    ->label('شركة الطيران')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer.full_name')
                    ->label('العميل')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('booking.booking_number')
                    ->label('رقم الحجز')
                    ->searchable()
                    ->sortable()
                    ->url(fn (AirlineCredit $record): string => FlightBookingResource::getUrl('edit', ['record' => $record->flight_booking_id]))
                    ->color('primary'),

                Tables\Columns\TextColumn::make('amount')
                    ->label('المبلغ')
                    ->numeric(2)
                    ->sortable()
                    ->description(fn (AirlineCredit $record): string => $record->currency),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('الحالة')
                    ->colors([
                        'success' => 'active',
                        'warning' => 'used',
                        'danger' => 'expired',
                    ])
                    ->formatStateUsing(fn (string $state): string => match ($state) {                        'active' => 'نشط',                        'used' => 'مستعمل',                        'expired' => 'منتهي',                        default => $state,
                    }),

                Tables\Columns\TextColumn::make('expiry_date')
                    ->label('تاريخ الانتهاء')
                    ->date('d M Y')
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('flight_carrier_id')
                    ->label('شركة الطيران')
                    ->relationship('carrier', 'name'),

                Tables\Filters\SelectFilter::make('status')
                    ->label('الحالة')
                    ->options([
                        'active' => 'نشط',
                        'used' => 'مستعمل',
                        'expired' => 'منتهي',
                    ]),
            ])
            ->actions([
                EditAction::make(),
            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAirlineCredits::route('/'),
            'create' => Pages\CreateAirlineCredit::route('/create'),
            'edit' => Pages\EditAirlineCredit::route('/{record}/edit'),
        ];
    }
}
