<?php

namespace App\Filament\Admin\Resources\FlightPayments;

use App\Filament\Admin\Resources\FlightPayments\Pages\ManageFlightPayments;
use App\Models\FlightPayment;
use BackedEnum;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FlightPaymentResource extends Resource
{
    protected static ?string $model = FlightPayment::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-credit-card';

    protected static string|\UnitEnum|null $navigationGroup = 'الحسابات والمالية';

    protected static ?string $navigationLabel = 'المدفوعات';
    protected static ?string $pluralLabel = 'المدفوعات';
    protected static ?string $modelLabel = 'مدفوعة';

    protected static ?string $recordTitleAttribute = 'transaction_reference';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('flight_booking_id')
                    ->label('حجز الطيران')
                    ->relationship('booking', 'booking_reference')
                    ->searchable()
                    ->required(),
                Select::make('payment_method')
                    ->label('طريقة الدفع')
                    ->options([
                        'CASH' => 'نقدي',
                        'BANK_TRANSFER' => 'تحويل بنكي',
                        'VODAFONE_CASH' => 'فودافون كاش',
                        'OFFICE_SAFE' => 'خزينة المكتب',
                    ])
                    ->required(),
                TextInput::make('amount')
                    ->label('المبلغ')
                    ->numeric()
                    ->required()
                    ->prefix('EGP'),
                Select::make('treasury_account')
                    ->label('حساب الخزينة')
                    ->options(\App\Enums\TreasuryAccount::class)
                    ->required(),
                TextInput::make('transaction_reference')
                    ->label('رقم العملية / المرجع')
                    ->maxLength(255),
                DateTimePicker::make('payment_date')
                    ->label('تاريخ الدفع')
                    ->default(now())
                    ->required(),
                TextInput::make('paid_by')
                    ->label('بواسطة (اسم الدافع)')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('transaction_reference')
            ->columns([
                TextColumn::make('booking.booking_reference')
                    ->label('رقم الحجز')
                    ->searchable(),
                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->money('EGP')
                    ->sortable(),
                TextColumn::make('payment_method')
                    ->label('الطريقة')
                    ->badge(),
                TextColumn::make('treasury_account')
                    ->label('الخزينة')
                    ->badge(),
                TextColumn::make('payment_date')
                    ->label('التاريخ')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('paid_by')
                    ->label('الدافع')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                \Filament\Tables\Actions\ViewAction::make(),
            ])
            ->toolbarActions([
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFlightPayments::route('/'),
        ];
    }
}
