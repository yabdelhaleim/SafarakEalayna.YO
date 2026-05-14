<?php

namespace App\Filament\Admin\Resources\Transactions\Pages;

use App\Filament\Admin\Resources\Transactions\TransactionResource;
use App\Models\Account;
use Filament\Resources\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TrialBalance extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = TransactionResource::class;

    protected static ?string $title = 'ميزان المراجعة';

    protected string $view = 'filament.admin.resources.transactions.pages.trial-balance';

    public function table(Table $table): Table
    {
        return $table
            ->query(Account::query())
            ->columns([
                TextColumn::make('id')
                    ->label('رقم الحساب')
                    ->sortable(),
                TextColumn::make('name')
                    ->label('اسم الحساب')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('type')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state->label()),
                TextColumn::make('total_debit')
                    ->label('إجمالي المدين')
                    ->state(fn (Account $record) => $record->entries()->sum('debit'))
                    ->money('egp'),
                TextColumn::make('total_credit')
                    ->label('إجمالي الدائن')
                    ->state(fn (Account $record) => $record->entries()->sum('credit'))
                    ->money('egp'),
                TextColumn::make('balance')
                    ->label('الرصيد النهائي')
                    ->money('egp')
                    ->color(fn ($state) => (float) $state >= 0 ? 'success' : 'danger'),
            ])
            ->filters([
                //
            ])
            ->actions([
                //
            ])
            ->bulkActions([
                //
            ]);
    }
}
