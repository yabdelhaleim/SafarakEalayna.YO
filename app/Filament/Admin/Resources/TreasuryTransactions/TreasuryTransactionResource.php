<?php

namespace App\Filament\Admin\Resources\TreasuryTransactions;

use App\Filament\Admin\Resources\TreasuryTransactions\Pages\ManageTreasuryTransactions;
use App\Models\TreasuryTransaction;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TreasuryTransactionResource extends Resource
{
    protected static ?string $model = TreasuryTransaction::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static string|\UnitEnum|null $navigationGroup = 'الحسابات والمالية';

    protected static ?string $navigationLabel = 'حركات الخزينة';
    protected static ?string $pluralLabel = 'حركات الخزينة';
    protected static ?string $modelLabel = 'حركة خزينة';

    protected static ?string $recordTitleAttribute = 'reason';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('from_treasury')
                    ->label('من خزينة')
                    ->options(\App\Enums\TreasuryAccount::class)
                    ->nullable(),
                Select::make('to_treasury')
                    ->label('إلى خزينة')
                    ->options(\App\Enums\TreasuryAccount::class)
                    ->nullable(),
                TextInput::make('amount')
                    ->label('المبلغ')
                    ->numeric()
                    ->required()
                    ->prefix('EGP'),
                TextInput::make('reason')
                    ->label('السبب / البيان')
                    ->required()
                    ->maxLength(255),
                Select::make('flight_booking_id')
                    ->label('حجز الطيران (اختياري)')
                    ->relationship('flightBooking', 'booking_reference')
                    ->searchable()
                    ->nullable(),
                TextInput::make('agent_name')
                    ->label('اسم الموظف / الوكيل')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('reason')
            ->columns([
                TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('from_treasury')
                    ->label('من')
                    ->badge()
                    ->color('danger'),
                TextColumn::make('to_treasury')
                    ->label('إلى')
                    ->badge()
                    ->color('success'),
                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->money('EGP')
                    ->sortable(),
                TextColumn::make('reason')
                    ->label('السبب')
                    ->searchable(),
                TextColumn::make('agent_name')
                    ->label('بواسطة')
                    ->searchable(),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                \Filament\Actions\ViewAction::make(),
            ])
            ->toolbarActions([
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTreasuryTransactions::route('/'),
        ];
    }
}
