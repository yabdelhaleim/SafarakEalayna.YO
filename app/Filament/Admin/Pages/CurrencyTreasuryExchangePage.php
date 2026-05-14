<?php

namespace App\Filament\Admin\Pages;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Services\Finance\TransactionService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;

class CurrencyTreasuryExchangePage extends Page
{
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-arrows-right-left';

    protected static ?string $navigationLabel = 'شراء عملة / خزائن العملات';

    protected static ?string $title = 'شراء عملة وتعبئة خزائن متعددة';

    protected static string|\UnitEnum|null $navigationGroup = 'المالية';

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'currency-exchange';

    protected string $view = 'filament.pages.currency-treasury-exchange';

    /** @var array<int, array{currency: string, total: float, accounts: array<int, array{name: string, balance: float, type: string}>}> */
    public array $summaryByCurrency = [];

    public function mount(): void
    {
        $this->refreshSummary();
    }

    public function refreshSummary(): void
    {
        $types = [
            AccountType::Cashbox->value,
            AccountType::Wallet->value,
            AccountType::Bank->value,
            AccountType::Treasury->value,
        ];

        $accounts = Account::query()
            ->where('is_active', true)
            ->where('module_type', 'tourism')
            ->whereIn('type', $types)
            ->orderBy('currency')
            ->orderBy('name')
            ->get();

        $this->summaryByCurrency = $accounts
            ->groupBy(fn (Account $a) => strtoupper((string) $a->currency))
            ->map(function (Collection $group, string $currency): array {
                $typeVal = fn (Account $a): string => $a->type instanceof BackedEnum ? $a->type->value : (string) $a->type;

                return [
                    'currency' => $currency,
                    'total' => (float) $group->sum(fn (Account $a) => (float) $a->balance),
                    'accounts' => $group->map(fn (Account $a) => [
                        'name' => $a->name,
                        'balance' => (float) $a->balance,
                        'type' => $typeVal($a),
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, string>
     */
    protected function treasuryAccountSelectOptions(): array
    {
        $types = [
            AccountType::Cashbox->value,
            AccountType::Wallet->value,
            AccountType::Bank->value,
            AccountType::Treasury->value,
        ];

        return Account::query()
            ->where('is_active', true)
            ->where('module_type', 'tourism')
            ->whereIn('type', $types)
            ->orderBy('currency')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Account $a) => [
                $a->id => $a->name.' ('.$a->currency.') — رصيد: '.number_format((float) $a->balance, 2),
            ])
            ->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('recordCurrencyPurchase')
                ->label('شراء عملة (من الصرافة → خزينة)')
                ->icon('heroicon-o-banknotes')
                ->color('success')
                ->modalHeading('تسجيل شراء عملة أجنبية')
                ->modalDescription('مثال: دفع 17,500 ج.م من خزينة الجنيه واستلام 100 د.ك في «نقدي دينار». عند اختلاف العملة بين الحسابين يُعبأ حقل «المبلغ المستلم» بعملة الحساب الثاني.')
                ->modalWidth('2xl')
                ->form([
                    Select::make('from_account_id')
                        ->label('من حساب (يُخصم المبلغ التالي بعملة هذا الحساب)')
                        ->options(fn () => $this->treasuryAccountSelectOptions())
                        ->searchable()
                        ->required()
                        ->native(false),
                    Select::make('to_account_id')
                        ->label('إلى حساب (يُضاف المبلغ المستلم بعملة هذا الحساب)')
                        ->options(fn () => $this->treasuryAccountSelectOptions())
                        ->searchable()
                        ->required()
                        ->native(false),
                    TextInput::make('amount')
                        ->label('المبلغ المدفوع / المُخصوم')
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->step(0.01)
                        ->helperText('بعملة «من حساب» (مثال: 17500 عند الشراء بالجنيه).'),
                    TextInput::make('converted_amount')
                        ->label('المبلغ المستلم (اختياري إن كانت العملة واحدة)')
                        ->numeric()
                        ->minValue(0.01)
                        ->step(0.01)
                        ->helperText('بعملة «إلى حساب» — مطلوب إذا اختلفت العملة عن حساب المصدر (مثال: 100 د.ك).'),
                    TextInput::make('exchange_rate')
                        ->label('سعر الصرف (اختياري)')
                        ->numeric()
                        ->minValue(0.000001)
                        ->step(0.000001)
                        ->helperText('وحدات عملة المصدر لكل 1 وحدة من عملة الاستلام؛ إن تُرك فارغاً يُحسب تلقائياً من المبلغين.'),
                    Textarea::make('notes')
                        ->label('ملاحظات')
                        ->rows(2)
                        ->maxLength(1000),
                ])
                ->action(function (array $data): void {
                    try {
                        $payload = [
                            'from_account_id' => (int) $data['from_account_id'],
                            'to_account_id' => (int) $data['to_account_id'],
                            'amount' => (float) $data['amount'],
                            'module' => TransactionModule::General->value,
                            'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                            'created_by' => auth()->id(),
                        ];
                        if (isset($data['converted_amount']) && $data['converted_amount'] !== '' && $data['converted_amount'] !== null) {
                            $payload['converted_amount'] = (float) $data['converted_amount'];
                        }
                        if (isset($data['exchange_rate']) && $data['exchange_rate'] !== '' && $data['exchange_rate'] !== null) {
                            $payload['exchange_rate'] = (float) $data['exchange_rate'];
                        }

                        app(TransactionService::class)->recordTransfer($payload);
                        $this->refreshSummary();
                        Notification::make()->title('تم تسجيل شراء العملة والقيود المحاسبية.')->success()->send();
                    } catch (\Throwable $e) {
                        Notification::make()->title('تعذر التنفيذ')->body($e->getMessage())->danger()->send();
                    }
                }),
        ];
    }
}
