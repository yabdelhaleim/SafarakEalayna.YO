<?php

namespace App\Filament\Admin\Pages;

use App\Enums\BusInventoryPaymentType;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Services\Bus\BusInventoryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BusCompanyDebtStatement extends Page implements HasTable
{
    use InteractsWithTable;

    protected static BackedEnum|string|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'مديونيات الشركة';

    protected string $view = 'filament.admin.pages.bus-company-debt-statement';

    public ?int $companyId = null;

    public function mount(?int $companyId = null): void
    {
        $this->companyId = $companyId;

        if (! $this->companyId) {
            return;
        }

        $company = BusCompany::find($this->companyId);
        if ($company) {
            static::$title = 'مديونيات الشركة: '.$company->name;
        }
    }

    protected function getHeaderActions(): array
    {
        if (! $this->companyId) {
            return [];
        }

        $company = BusCompany::with('account')->find($this->companyId);
        $accountId = (int) ($company?->account_id ?? 0);

        return [
            Action::make('accountStatement')
                ->label('كشف حساب (الحساب المالي)')
                ->icon('heroicon-o-document-text')
                ->color('info')
                ->visible(fn (): bool => $accountId > 0)
                ->url(fn (): string => \App\Filament\Admin\Pages\AccountStatement::getUrl([
                    'accountId' => $accountId,
                ])),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query($this->getInventoryDebtQuery())
            ->defaultSort('travel_date', 'desc')
            ->columns([
                TextColumn::make('route')
                    ->label('الرحلة / المسار')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('travel_date')
                    ->label('تاريخ السفر')
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('total_cost')
                    ->label('إجمالي التكلفة')
                    ->money('EGP')
                    ->sortable()
                    ->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()->label('الإجمالي')),
                TextColumn::make('amount_paid')
                    ->label('المسدد')
                    ->money('EGP')
                    ->color('success')
                    ->sortable()
                    ->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()->label('إجمالي المسدد')),
                TextColumn::make('remaining_debt')
                    ->label('المتبقي')
                    ->money('EGP')
                    ->color('danger')
                    ->sortable()
                    ->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()->label('إجمالي المتبقي')),
            ])
            ->filters([
                Filter::make('travel_date')
                    ->form([
                        DatePicker::make('from')->label('من'),
                        DatePicker::make('until')->label('إلى'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('travel_date', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('travel_date', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                Action::make('payDebt')
                    ->label('سداد')
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->visible(fn (BusInventory $record): bool => (float) $record->remaining_debt > 0)
                    ->form([
                        \Filament\Forms\Components\TextInput::make('amount')
                            ->label('المبلغ')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->maxValue(fn (BusInventory $record) => (float) $record->remaining_debt)
                            ->default(fn (BusInventory $record) => (float) $record->remaining_debt)
                            ->prefix('ج.م'),
                        \Filament\Forms\Components\Select::make('account_id')
                            ->label('حساب السداد (الخزينة)')
                            ->relationship('account', 'name', fn ($q) => $q->where('is_active', true))
                            ->searchable()
                            ->preload()
                            ->required(),
                        \Filament\Forms\Components\Textarea::make('notes')
                            ->label('ملاحظات')
                            ->rows(2),
                    ])
                    ->action(function (BusInventory $record, array $data): void {
                        try {
                            app(BusInventoryService::class)->payInventoryDebt($record, [
                                'amount' => (float) $data['amount'],
                                'account_id' => (int) $data['account_id'],
                                'notes' => $data['notes'] ?? null,
                            ]);

                            Notification::make()
                                ->title('تم تسجيل السداد')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('فشل السداد')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    private function getInventoryDebtQuery(): Builder
    {
        return BusInventory::query()
            ->with(['company'])
            ->where('company_id', $this->companyId)
            ->where('payment_type', BusInventoryPaymentType::Deferred->value)
            ->where('remaining_debt', '>', 0);
    }
}

