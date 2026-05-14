<?php

namespace App\Filament\Admin\Resources\Accounts\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AccountEntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['transaction:id,created_at,type,amount,notes']))
            ->columns([
                TextColumn::make('transaction.created_at')
                    ->label('تاريخ العملية')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('transaction.id')
                    ->label('معاملة #')
                    ->sortable(),
                TextColumn::make('debit')
                    ->label('مدين')
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('credit')
                    ->label('دائن')
                    ->numeric(decimalPlaces: 2),
                TextColumn::make('balance_after')
                    ->label('رصيد بعد')
                    ->numeric(decimalPlaces: 2),
            ])
            ->defaultSort('id', 'desc');
    }
}
