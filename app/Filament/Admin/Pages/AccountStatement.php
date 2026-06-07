<?php

namespace App\Filament\Admin\Pages;

use App\Models\Account;
use App\Models\AccountEntry;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AccountStatement extends Page implements HasTable
{
    use InteractsWithTable;

    protected static BackedEnum|string|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'كشف حساب';

    protected string $view = 'filament.admin.pages.account-statement';

    public ?int $accountId = null;

    public function mount(?int $accountId = null): void
    {
        $this->accountId = $accountId;

        if ($this->accountId) {
            $account = Account::find($this->accountId);
            if ($account) {
                static::$title = 'كشف حساب: '.$account->name;
            }
        }
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                AccountEntry::query()
                    ->with('transaction')
                    ->where('account_id', $this->accountId)
                    ->orderByDesc('created_at')
            )
            ->columns([
                TextColumn::make('created_at')
                    ->label('التاريخ')
                    ->dateTime('d/m/Y H:i')
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
                            ->when($data['from'], fn ($q) => $q->whereDate('account_entries.created_at', '>=', $data['from']))
                            ->when($data['to'], fn ($q) => $q->whereDate('account_entries.created_at', '<=', $data['to']));
                    }),
            ]);
    }
}
