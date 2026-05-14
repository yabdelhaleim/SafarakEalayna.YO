<?php

namespace App\Filament\Admin\Resources\Transactions\Pages;

use App\Models\Account;
use App\Models\AccountEntry;
use Filament\Resources\Pages\Page;
use Filament\Tables\Table;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Builder;

class AccountStatement extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string $resource = \App\Filament\Admin\Resources\Transactions\TransactionResource::class;

    protected string $view = 'filament.admin.resources.transactions.pages.account-statement';

    protected static ?string $title = 'كشف حساب';

    public ?int $accountId = null;

    public function mount(?int $accountId = null): void
    {
        $this->accountId = $accountId;
        
        if ($this->accountId) {
            $account = Account::find($this->accountId);
            if ($account) {
                static::$title = 'كشف حساب: ' . $account->name;
            }
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AccountEntry::query()
                    ->where('account_id', $this->accountId)
                    ->orderBy('created_at', 'desc')
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('transaction.notes')
                    ->label('البيان')
                    ->wrap()
                    ->searchable(),
                TextColumn::make('debit')
                    ->label('مدين')
                    ->money('egp')
                    ->color('danger')
                    ->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()->label('إجمالي المدين')),
                TextColumn::make('credit')
                    ->label('دائن')
                    ->money('egp')
                    ->color('success')
                    ->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()->label('إجمالي الدائن')),
                TextColumn::make('balance_after')
                    ->label('الرصيد')
                    ->money('egp'),
            ])
            ->filters([
                \Filament\Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from')->label('من'),
                        \Filament\Forms\Components\DatePicker::make('to')->label('إلى'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when($data['from'], fn ($q) => $q->whereDate('created_at', '>=', $data['from']))
                            ->when($data['to'], fn ($q) => $q->whereDate('created_at', '<=', $data['to']));
                    })
            ]);
    }
}
