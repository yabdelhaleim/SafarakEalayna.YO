<?php

namespace App\Filament\Admin\Resources\FlightSystems\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FlightSystemTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'systemTransactions';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['flightBooking:id,booking_number', 'createdBy:id,name']))
            ->columns([
                TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => match ($state) {
                        'debit' => 'خصم',
                        'credit' => 'إضافة',
                        default => $state ?? '—',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'debit' => 'danger',
                        'credit' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('amount')
                    ->label('المبلغ')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),
                TextColumn::make('balance_after')
                    ->label('رصيد بعد')
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('flightBooking.booking_number')
                    ->label('الحجز')
                    ->placeholder('—'),
                TextColumn::make('description')
                    ->label('الوصف')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->description),
                TextColumn::make('createdBy.name')
                    ->label('المستخدم')
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
