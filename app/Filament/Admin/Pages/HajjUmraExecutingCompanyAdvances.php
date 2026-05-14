<?php

namespace App\Filament\Admin\Pages;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class HajjUmraExecutingCompanyAdvances extends Page implements HasTable
{
    use InteractsWithTable;

    protected static BackedEnum|string|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'سحب/سداد (الشركة المنفذة)';

    protected string $view = 'filament.admin.pages.hajj-umra-executing-company-advances';

    public ?int $companyId = null;

    public ?int $accountId = null;

    public float $netDue = 0.0;

    public function mount(?int $companyId = null): void
    {
        $this->companyId = $companyId;

        if (! $this->companyId) {
            return;
        }

        $company = HajjUmraExecutingCompany::find($this->companyId);
        $this->accountId = (int) ($company?->account_id ?? 0) ?: null;

        if ($company) {
            static::$title = 'سحب/سداد: '.$company->name;
        }

        if ($this->accountId) {
            $totals = $this->baseQuery()
                ->selectRaw('COALESCE(SUM(debit), 0) as total_debit, COALESCE(SUM(credit), 0) as total_credit')
                ->first();

            $debit = (float) ($totals?->total_debit ?? 0);
            $credit = (float) ($totals?->total_credit ?? 0);
            $this->netDue = $debit - $credit;
        }
    }

    protected function getHeaderActions(): array
    {
        if (! $this->companyId || ! $this->accountId) {
            return [];
        }

        $company = HajjUmraExecutingCompany::find($this->companyId);
        $companyAccountId = $this->accountId;

        return [
            Action::make('withdraw')
                ->label('سحب من الشركة')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('warning')
                ->form($this->transferFormSchema())
                ->action(function (array $data) use ($company, $companyAccountId): void {
                    try {
                        $toAccountId = (int) $data['to_account_id'];

                        app(\App\Services\Finance\TransactionService::class)->recordJournalTransfer([
                            'amount' => (float) $data['amount'],
                            'from_account_id' => $companyAccountId,
                            'to_account_id' => $toAccountId,
                            'module' => TransactionModule::HajjUmra->value,
                            'notes' => 'سحب من الشركة المنفذة ['.($company?->name ?? '—').']: '.($data['notes'] ?? ''),
                            'created_by' => auth()->id(),
                        ]);

                        Notification::make()->title('تم تسجيل السحب')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('فشل تسجيل السحب')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('repay')
                ->label('سداد للشركة')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('success')
                ->form($this->repayFormSchema())
                ->action(function (array $data) use ($company, $companyAccountId): void {
                    try {
                        $fromAccountId = (int) $data['from_account_id'];

                        app(\App\Services\Finance\TransactionService::class)->recordJournalTransfer([
                            'amount' => (float) $data['amount'],
                            'from_account_id' => $fromAccountId,
                            'to_account_id' => $companyAccountId,
                            'module' => TransactionModule::HajjUmra->value,
                            'notes' => 'سداد للشركة المنفذة ['.($company?->name ?? '—').']: '.($data['notes'] ?? ''),
                            'created_by' => auth()->id(),
                        ]);

                        Notification::make()->title('تم تسجيل السداد')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('فشل تسجيل السداد')->body($e->getMessage())->danger()->send();
                    }
                }),
            Action::make('statement')
                ->label('كشف حساب (الحساب المالي)')
                ->icon('heroicon-o-document-text')
                ->color('info')
                ->url(fn (): string => \App\Filament\Admin\Resources\Transactions\Pages\AccountStatement::getUrl([
                    'accountId' => $companyAccountId,
                ])),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->baseQuery())
            ->defaultSort('created_at', 'desc')
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
                    ->label('سحب (مدين)')
                    ->money('egp')
                    ->color('warning')
                    ->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()->label('إجمالي السحب')),
                TextColumn::make('credit')
                    ->label('سداد (دائن)')
                    ->money('egp')
                    ->color('success')
                    ->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()->label('إجمالي السداد')),
                TextColumn::make('balance_after')
                    ->label('رصيد الحساب')
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

    private function baseQuery(): Builder
    {
        return AccountEntry::query()
            ->with('transaction')
            ->select('account_entries.*')
            ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
            ->where('account_entries.account_id', $this->accountId)
            ->where('transactions.module', TransactionModule::HajjUmra->value);
    }

    private function transferFormSchema(): array
    {
        return [
            \Filament\Forms\Components\TextInput::make('amount')
                ->label('المبلغ')
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->prefix('ج.م'),
            \Filament\Forms\Components\Select::make('to_account_id')
                ->label('تحويل إلى (حساب الحج والعمرة)')
                ->options(fn () => Account::query()
                    ->where('module_type', 'hajj_umra')
                    ->where('is_active', true)
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->required(),
            \Filament\Forms\Components\Textarea::make('notes')
                ->label('ملاحظات')
                ->rows(2),
        ];
    }

    private function repayFormSchema(): array
    {
        return [
            \Filament\Forms\Components\TextInput::make('amount')
                ->label('المبلغ')
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->prefix('ج.م')
                ->default(fn () => $this->netDue > 0 ? $this->netDue : null),
            \Filament\Forms\Components\Select::make('from_account_id')
                ->label('السداد من (حساب الحج والعمرة)')
                ->options(fn () => Account::query()
                    ->where('module_type', 'hajj_umra')
                    ->where('is_active', true)
                    ->pluck('name', 'id')
                    ->all())
                ->searchable()
                ->preload()
                ->required(),
            \Filament\Forms\Components\Textarea::make('notes')
                ->label('ملاحظات')
                ->rows(2),
        ];
    }
}

