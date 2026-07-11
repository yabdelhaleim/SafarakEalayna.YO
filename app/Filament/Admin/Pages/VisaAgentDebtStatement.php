<?php

namespace App\Filament\Admin\Pages;

use App\Enums\VisaStatus;
use App\Models\HajjUmra\VisaAgent;
use App\Models\VisaBooking;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VisaAgentDebtStatement extends Page implements HasTable
{
    use InteractsWithTable;

    protected static BackedEnum|string|null $navigationIcon = null;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'مديونيات الوكيل';

    protected string $view = 'filament.admin.pages.visa-agent-debt-statement';

    public ?int $agentId = null;

    public function mount(?int $agentId = null): void
    {
        $this->agentId = $agentId;

        if (! $this->agentId) {
            return;
        }

        $agent = VisaAgent::find($this->agentId);
        if ($agent) {
            static::$title = 'مديونيات الوكيل: ' . $agent->company_name;
        }
    }

    protected function getHeaderActions(): array
    {
        if (! $this->agentId) {
            return [];
        }

        $agent = VisaAgent::with('account')->find($this->agentId);
        $accountId = (int) ($agent?->account_id ?? 0);

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
            ->query($this->getBookingDebtQuery())
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('رقم الحجز')
                    ->sortable(),
                TextColumn::make('customer.name')
                    ->label('العميل')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('visaDetail.country')
                    ->label('الدولة')
                    ->badge()
                    ->color('primary'),
                TextColumn::make('visaDetail.visa_type')
                    ->label('نوع التأشيرة')
                    ->formatStateUsing(fn ($state) => $state->label ?? $state)
                    ->badge()
                    ->color('info'),
                TextColumn::make('selling_price')
                    ->label('سعر البيع')
                    ->money('egp')
                    ->sortable()
                    ->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()->label('الإجمالي')),
                TextColumn::make('paid_amount')
                    ->label('المسدد')
                    ->money('egp')
                    ->color('success')
                    ->sortable()
                    ->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()->label('إجمالي المسدد')),
                TextColumn::make('remaining_amount')
                    ->label('المتبقي')
                    ->money('egp')
                    ->color('danger')
                    ->sortable()
                    ->summarize(\Filament\Tables\Columns\Summarizers\Sum::make()->label('إجمالي المتبقي')),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->color(fn ($state) => match($state) {
                        VisaStatus::Pending => 'warning',
                        VisaStatus::Processing => 'info',
                        VisaStatus::Approved => 'success',
                        VisaStatus::Rejected => 'danger',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state->label ?? $state),
                TextColumn::make('created_at')
                    ->label('تاريخ الحجز')
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(VisaStatus::forDropdown()),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('from')->label('من'),
                        DatePicker::make('until')->label('إلى'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['until'] ?? null,
                                fn (Builder $query, $date): Builder => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->recordActions([
                Action::make('payDebt')
                    ->label('تسديد')
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->visible(fn (VisaBooking $record): bool => (float) $record->remaining_amount > 0)
                    ->form([
                        \Filament\Forms\Components\TextInput::make('amount')
                            ->label('المبلغ')
                            ->numeric()
                            ->required()
                            ->minValue(0.01)
                            ->maxValue(fn (VisaBooking $record) => (float) $record->remaining_amount)
                            ->default(fn (VisaBooking $record) => (float) $record->remaining_amount)
                            ->prefix('ج.م'),
                        \Filament\Forms\Components\Select::make('account_id')
                            ->label('حساب السداد')
                            ->relationship(name: 'account', titleAttribute: 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->helperText('اختر الخزينة أو الحساب الذي سيتم السداد منه'),
                        \Filament\Forms\Components\Textarea::make('notes')
                            ->label('ملاحظات')
                            ->rows(2),
                    ])
                    ->action(function (VisaBooking $record, array $data): void {
                        try {
                            // Phase 2026-07-11 FIX:
                            // The original wire here used raw Transaction::create()
                            // with 'account_id' (which is NOT in Transaction::$fillable
                            // and was silently dropped), created NO AccountEntry rows,
                            // ran without lockForUpdate, and was wrapped only in a
                            // raw DB::transaction with no LedgerBalanceMutationGuard.
                            // Net effect: the booking's remaining_amount decreased in
                            // the UI, but no cashbox / customer account balance moved,
                            // and no entries appeared in customerStatement().
                            //
                            // Replaced with the canonical service-layer method
                            // VisaBookingService::addDebtPayment() — it posts a
                            // balanced recordIncome() (creating both cashbox + customer
                            // AccountEntry rows) and creates the VisaPayment in the
                            // same atomic transaction, with all the standard guards
                            // (LedgerBalanceMutationGuard, lockForUpdate on the booking
                            // via the income path's contra-account resolver).
                            app(\App\Services\Visa\VisaBookingService::class)
                                ->addDebtPayment($record, $data);

                            Notification::make()
                                ->title('تم تسجيل السداد بنجاح')
                                ->body('تم إنشاء قيد GL عكسي متوازن على الخزينة المحددة وحساب العميل.')
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
                Action::make('view')
                    ->label('عرض التفاصيل')
                    ->icon('heroicon-o-eye')
                    ->url(fn (VisaBooking $record): string => \App\Filament\Admin\Resources\VisaBookings\VisaBookingResource::getUrl('view', ['record' => $record->id])),
            ])
            ->emptyStateHeading('لا توجد مديونيات')
            ->emptyStateDescription('لا توجد حجوزات تأشيرات مع دفعات متبقية لهذا الوكيل')
            ->emptyStateIcon('heroicon-o-check-circle');
    }

    private function getBookingDebtQuery(): Builder
    {
        return VisaBooking::query()
            ->with(['customer', 'visaDetail', 'payments'])
            ->whereHas('visaDetail', function ($query) {
                $query->where('visa_agent_id', $this->agentId);
            })
            ->where(function ($query) {
                $query->where('status', '!=', VisaStatus::Cancelled->value)
                      ->whereRaw('(selling_price + COALESCE(service_fee, 0)) > (SELECT COALESCE(SUM(amount), 0) FROM visa_payments WHERE visa_booking_id = visa_bookings.id)');
            });
    }
}
