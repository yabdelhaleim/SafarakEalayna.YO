<?php

namespace App\Services\Online;

use App\Enums\AccountType;
use App\Enums\OnlineTransactionStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Online\OnlineServiceType;
use App\Models\Online\OnlineTransaction;
use App\Models\Transaction;
use App\Services\Finance\TransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OnlineTransactionService
{
    public function __construct(
        protected TransactionService $transactionService,
    ) {}

    public function getAll(array $filters): LengthAwarePaginator
    {
        $query = OnlineTransaction::query()->with([
            'serviceType',
            'provider',
            'customer',
            'employee.user',
            'account',
            'paymentMethodRow',
            'createdBy',
        ]);

        if (! empty($filters['service_type_id'])) {
            $query->where('service_type_id', $filters['service_type_id']);
        }
        if (! empty($filters['provider_id'])) {
            $query->where('provider_id', $filters['provider_id']);
        }
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }
        if (! empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }
        if (! empty($filters['account_id'])) {
            $query->where('account_id', $filters['account_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date'].' 00:00:00');
        }
        if (! empty($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date'].' 23:59:59');
        }
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhere('reference_number', 'like', "%{$search}%");
            });
        }

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    public function getById(int $id): OnlineTransaction
    {
        return OnlineTransaction::with([
            'serviceType',
            'provider',
            'customer',
            'employee.user',
            'account',
            'paymentMethodRow',
            'expenseTransaction',
            'incomeTransaction',
            'createdBy',
        ])->findOrFail($id);
    }

    public function create(array $data): OnlineTransaction
    {
        try {
            return DB::transaction(function () use ($data) {
                $serviceType = OnlineServiceType::findOrFail($data['service_type_id']);
                if (! $serviceType->is_active) {
                    throw new \RuntimeException('نوع الخدمة غير نشط حالياً.');
                }

                $provider = ! empty($data['provider_id'])
                    ? OnlineServiceProvider::find($data['provider_id'])
                    : null;

                [$customerName, $customerPhone] = $this->resolveCustomerNameAndPhone($data);

                $purchase = (float) $data['purchase_price'];
                $selling = (float) $data['selling_price'];
                $amountPaid = isset($data['amount_paid']) ? (float) $data['amount_paid'] : $selling;
                $profit = $selling - $purchase;

                $status = OnlineTransactionStatus::tryFrom($data['status'] ?? OnlineTransactionStatus::Completed->value)
                    ?? OnlineTransactionStatus::Completed;

                $tx = OnlineTransaction::create([
                    'service_type_id' => $serviceType->id,
                    'provider_id' => $provider?->id,
                    'customer_id' => $data['customer_id'] ?? null,
                    'customer_name' => $customerName,
                    'customer_phone' => $customerPhone,
                    'customer_country' => $data['customer_country'] ?? null,
                    'employee_id' => $data['employee_id'] ?? null,
                    'purchase_price' => $purchase,
                    'selling_price' => $selling,
                    'amount_paid' => $amountPaid,
                    'profit' => $profit,
                    'payment_method' => $data['payment_method'],
                    'account_id' => $data['account_id'],
                    'reference_number' => $data['reference_number'] ?? null,
                    'status' => $status->value,
                    'failure_reason' => $data['failure_reason'] ?? null,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => Auth::id(),
                ]);

                if ($status === OnlineTransactionStatus::Completed) {
                    $this->postFinancialEntries($tx, $serviceType, $provider, $purchase, $selling, $customerName);
                }

                Log::info('Online transaction created', [
                    'online_transaction_id' => $tx->id,
                    'service_type' => $serviceType->code,
                    'provider' => $provider?->code,
                    'purchase' => $purchase,
                    'selling' => $selling,
                    'profit' => $profit,
                    'created_by' => Auth::id(),
                ]);

                return $tx->fresh([
                    'serviceType',
                    'provider',
                    'customer',
                    'employee.user',
                    'account',
                    'paymentMethodRow',
                    'expenseTransaction',
                    'incomeTransaction',
                    'createdBy',
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('OnlineTransactionService::create failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'input' => $data,
            ]);
            throw $e;
        }
    }

    public function update(OnlineTransaction $tx, array $data): OnlineTransaction
    {
        try {
            return DB::transaction(function () use ($tx, $data) {
                if (array_key_exists('customer_id', $data) || array_key_exists('customer_name', $data) || array_key_exists('customer_phone', $data)) {
                    [$customerName, $customerPhone] = $this->resolveCustomerNameAndPhone(array_merge(
                        $tx->only(['customer_id', 'customer_name', 'customer_phone']),
                        $data,
                    ));
                    $data['customer_name'] = $customerName;
                    $data['customer_phone'] = $customerPhone;
                }

                if (isset($data['purchase_price']) || isset($data['selling_price'])) {
                    $purchase = (float) ($data['purchase_price'] ?? $tx->purchase_price);
                    $selling = (float) ($data['selling_price'] ?? $tx->selling_price);
                    $data['profit'] = $selling - $purchase;
                }

                $tx->fill($data)->save();

                Log::info('Online transaction updated', [
                    'online_transaction_id' => $tx->id,
                    'updated_by' => Auth::id(),
                ]);

                return $tx->fresh([
                    'serviceType',
                    'provider',
                    'customer',
                    'employee.user',
                    'account',
                    'paymentMethodRow',
                    'expenseTransaction',
                    'incomeTransaction',
                    'createdBy',
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('OnlineTransactionService::update failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'online_transaction_id' => $tx->id,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    public function delete(OnlineTransaction $tx): bool
    {
        try {
            return DB::transaction(function () use ($tx) {
                // لا يمكن حذف المعاملة فعلياً (يمنعها النموذج للحفاظ على السجلات).
                // بدلاً من ذلك نُغير الحالة إلى "ملغاة" ونعكس القيود المالية.

                if ($tx->status === OnlineTransactionStatus::Cancelled) {
                    throw new \RuntimeException('المعاملة ملغاة بالفعل.');
                }

                // عكس القيود المالية فقط إذا كانت المعاملة مكتملة (لها قيود)
                if ($tx->status === OnlineTransactionStatus::Completed) {
                    $relatedTransactions = Transaction::where('related_type', OnlineTransaction::class)
                        ->where('related_id', $tx->id)
                        ->get();

                    foreach ($relatedTransactions as $rt) {
                        $this->transactionService->reverseTransaction($rt);
                    }
                }

                // تغيير الحالة إلى "ملغاة" مع تسجيل السبب
                $tx->status = OnlineTransactionStatus::Cancelled;
                $tx->failure_reason = ($tx->failure_reason ? $tx->failure_reason."\n" : '')
                    .'[تم الإلغاء بواسطة '.Auth::user()?->name.' في '.now()->format('Y-m-d H:i').']';
                $tx->save();

                Log::info('Online transaction cancelled and ledger reversed', [
                    'online_transaction_id' => $tx->id,
                    'cancelled_by' => Auth::id(),
                ]);

                return true;
            });
        } catch (\Throwable $e) {
            Log::error('OnlineTransactionService::delete failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'online_transaction_id' => $tx->id,
            ]);
            throw $e;
        }
    }

    public function getDailySummary(string $date): array
    {
        $start = $date.' 00:00:00';
        $end = $date.' 23:59:59';

        $row = OnlineTransaction::where('status', OnlineTransactionStatus::Completed->value)
            ->whereBetween('created_at', [$start, $end])
            ->selectRaw('
                COUNT(*) as total_transactions,
                COALESCE(SUM(purchase_price), 0) as total_purchase,
                COALESCE(SUM(selling_price), 0) as total_selling,
                COALESCE(SUM(profit), 0) as total_profit
            ')
            ->first();

        return [
            'date' => $date,
            'total_transactions' => (int) ($row->total_transactions ?? 0),
            'total_purchase' => (float) ($row->total_purchase ?? 0),
            'total_selling' => (float) ($row->total_selling ?? 0),
            'total_profit' => (float) ($row->total_profit ?? 0),
        ];
    }

    private function postFinancialEntries(
        OnlineTransaction $tx,
        OnlineServiceType $serviceType,
        ?OnlineServiceProvider $provider,
        float $purchase,
        float $selling,
        string $customerName,
    ): void {
        $module = TransactionModule::Online->value;
        $providerLabel = $provider?->name_ar ? " - {$provider->name_ar}" : '';
        $createdBy = Auth::id() ?? 1;

        if ($selling > 0) {
            if ($tx->customer_id) {
                $customerAccount = $this->ensureCustomerAccount((int) $tx->customer_id);

                // 1. مديونية العميل بالقيمة الإجمالية
                $income = $this->transactionService->recordIncome([
                    'amount' => $selling,
                    'to_account_id' => $customerAccount->id,
                    'module' => $module,
                    'related_type' => OnlineTransaction::class,
                    'related_id' => $tx->id,
                    'notes' => "تحصيل خدمة أونلاين (مديونية) - {$serviceType->name_ar}{$providerLabel}: {$customerName}",
                    'created_by' => $createdBy,
                ]);

                // 2. سداد جزء من المبلغ نقدياً (المدفوع الآن)
                $amountPaid = (float) ($tx->amount_paid ?? $selling);
                if ($amountPaid > 0) {
                    $this->transactionService->recordIncome([
                        'amount' => $amountPaid,
                        'to_account_id' => $tx->account_id,
                        'contra_account_id' => $customerAccount->id,
                        'module' => $module,
                        'related_type' => OnlineTransaction::class,
                        'related_id' => $tx->id,
                        'notes' => "تحصيل خدمة أونلاين (سداد جزئي) - {$serviceType->name_ar}{$providerLabel}: {$customerName}",
                        'created_by' => $createdBy,
                    ]);
                }
            } else {
                // عميل نقدي فوري مباشر
                $income = $this->transactionService->recordIncome([
                    'amount' => $selling,
                    'to_account_id' => $tx->account_id,
                    'module' => $module,
                    'related_type' => OnlineTransaction::class,
                    'related_id' => $tx->id,
                    'notes' => "تحصيل خدمة أونلاين - {$serviceType->name_ar}{$providerLabel}: {$customerName}",
                    'created_by' => $createdBy,
                ]);
            }
            $tx->income_transaction_id = $income->id;
        }

        if ($purchase > 0) {
            $sourceAccountId = $provider?->default_purchase_account_id ?: $tx->account_id;
            $expense = $this->transactionService->recordExpense([
                'amount' => $purchase,
                'from_account_id' => $sourceAccountId,
                'module' => $module,
                'related_type' => OnlineTransaction::class,
                'related_id' => $tx->id,
                'notes' => "تكلفة خدمة أونلاين - {$serviceType->name_ar}{$providerLabel}: {$customerName}",
                'created_by' => $createdBy,
            ]);
            $tx->expense_transaction_id = $expense->id;
        }

        $tx->save();
    }

    protected function ensureCustomerAccount(int $customerId): Account
    {
        $customer = Customer::findOrFail($customerId);

        if ($customer->account_id) {
            $account = Account::find($customer->account_id);
            if ($account) {
                return $account;
            }
        }

        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($customer) {
            $account = Account::create([
                'name' => 'حساب العميل: '.$customer->full_name,
                'type' => AccountType::Customer,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'online',
                'is_module_vault' => false,
                'notes' => 'حساب تلقائي للعميل #'.$customer->id,
                'created_by' => Auth::id() ?? 1,
            ]);

            $customer->update(['account_id' => $account->id]);

            return $account;
        }));
    }

    /**
     * @return array{0:string,1:?string}
     */
    private function resolveCustomerNameAndPhone(array $data): array
    {
        $name = $data['customer_name'] ?? null;
        $phone = $data['customer_phone'] ?? null;

        if (! empty($data['customer_id'])) {
            $customer = Customer::find($data['customer_id']);
            if ($customer) {
                $name = $name ?: $customer->full_name;
                $phone = $phone ?: $customer->phone;
            }
        }

        return [$name ?? '', $phone];
    }
}
