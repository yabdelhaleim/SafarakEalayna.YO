<?php

namespace App\Services\HajjUmra;

use App\Enums\AccountType;
use App\Enums\HajjUmraStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Program;
use App\Models\Transaction;
use App\Services\Finance\TransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class HajjUmraBookingService
{
    public function __construct(protected TransactionService $transactions) {}

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = HajjUmraBooking::with([
            'customer',
            'companion',
            'program.executingCompany',
            'program.tripSupervisor',
            'program.accommodationTypeRow',
            'employee',
            'account',
            'payments.account',
        ]);

        $this->applyFilters($query, $filters);

        $perPage = (int) min($filters['per_page'] ?? 15, 100);

        return $query->latest()->paginate($perPage);
    }

    protected function applyFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['program_id'])) {
            $query->where('program_id', $filters['program_id']);
        }
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if (! empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }
        if (! empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->whereHas('customer', function ($q) use ($term) {
                $q->where('full_name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('passport_number', 'like', "%{$term}%");
            });
        }
        if (! empty($filters['program_type'])) {
            $pt = strtolower((string) $filters['program_type']);
            $query->whereHas('program', fn ($q) => $q->whereRaw('LOWER(program_type) = ?', [$pt]));
        }
    }

    public function find(int $id): HajjUmraBooking
    {
        return HajjUmraBooking::with([
            'customer',
            'companion',
            'program.executingCompany',
            'program.tripSupervisor',
            'program.accommodationTypeRow',
            'employee',
            'account',
            'expenseTransaction',
            'incomeTransaction',
            'payments.account',
            'payments.transaction',
        ])->findOrFail($id);
    }

    /**
     * Create a Hajj/Umra booking with double-entry accounting:
     *  - recordExpense: تكلفة الشراء كمصروف من حساب الخزينة (تدفع للشركة المنفذة)
     *  - recordIncome: سعر البيع كإيراد إلى نفس الحساب (يُحصَّل من العميل)
     *
     * إذا كان initial_payment.amount > 0 يُسجَّل كدفعة مرتبطة بقيد دخل من الحساب نفسه.
     */
    public function create(array $data): HajjUmraBooking
    {
        return DB::transaction(function () use ($data) {
            $customer = $this->resolveCustomer($data['customer'] ?? null, $data['customer_id'] ?? null);

            $program = Program::findOrFail($data['program_id']);

            $purchase = (float) ($data['purchase_price'] ?? 0);
            $companionPurchase = (float) ($data['companion_purchase_price'] ?? 0);
            $selling = (float) ($data['selling_price'] ?? 0);
            $companionSelling = (float) ($data['companion_selling_price'] ?? 0);
            $accommodationExtra = (float) ($data['accommodation_extra_charge'] ?? 0);

            $totalPurchase = $purchase + $companionPurchase;
            $totalSelling = $selling + $companionSelling + $accommodationExtra;
            $profit = round($totalSelling - $totalPurchase, 2);

            $accountId = (int) ($data['account_id'] ?? 0);
            if ($accountId === 0) {
                $vault = Account::getModuleVault('hajj_umra');
                if (! $vault) {
                    throw new \RuntimeException('لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.');
                }
                $accountId = $vault->id;
            }

            $createdBy = Auth::id() ?? ($data['employee_id'] ?? null);

            $booking = HajjUmraBooking::create([
                'customer_id' => $customer->id,
                'companion_customer_id' => $data['companion_customer_id'] ?? null,
                'program_id' => $program->id,
                'supplier_id' => $data['supplier_id'] ?? null,
                'module' => TransactionModule::HajjUmra->value,
                'purchase_price' => $purchase,
                'companion_purchase_price' => $companionPurchase,
                'selling_price' => $selling,
                'companion_selling_price' => $companionSelling,
                'profit' => $profit,
                'currency' => $data['currency'] ?? 'EGP',
                'per_person' => (bool) ($data['per_person'] ?? true),
                'accommodation_choice' => $data['accommodation_choice'] ?? 'standard',
                'accommodation_extra_charge' => $accommodationExtra,
                'status' => $data['status'] ?? HajjUmraStatus::Confirmed->value,
                'agent_name' => $data['agent_name'] ?? ($customer->full_name ?? ''),
                'notes' => $data['notes'] ?? null,
                'account_id' => $accountId,
                'employee_id' => $data['employee_id'] ?? $createdBy,
                'created_by' => $createdBy,
            ]);

            $customerAccount = $this->ensureCustomerAccount($customer->id);

            // Save passenger breakdowns if any
            if (! empty($data['passengers']) && is_array($data['passengers'])) {
                foreach ($data['passengers'] as $p) {
                    $booking->passengers()->create([
                        'category' => $p['category'],
                        'count' => (int) $p['count'],
                        'unit_price' => (float) $p['unit_price'],
                        'subtotal' => (float) $p['subtotal'],
                    ]);
                }
            }

            $expenseAccountId = $accountId;
            $supplierId = $data['supplier_id'] ?? null;
            if ($supplierId) {
                $supplier = UmrahSupplier::find($supplierId);
                if ($supplier && $supplier->account_id) {
                    $expenseAccountId = $supplier->account_id;
                }
            } elseif ($program->executing_company_id) {
                $company = HajjUmraExecutingCompany::find($program->executing_company_id);
                if ($company) {
                    if (! $company->account_id) {
                        $account = Account::create([
                            'name' => 'حساب الشركة المنفذة للحج/العمرة: '.($company->name ?: 'غير مسمى'),
                            'type' => AccountType::Supplier->value,
                            'currency' => 'EGP',
                            'balance' => 0.00,
                            'is_active' => true,
                            'owner_type' => Account::OWNER_TYPE_OWNER,
                            'module_type' => 'hajj_umra',
                            'notes' => 'حساب شركة منفذة تلقائي مضاف من النظام.',
                            'created_by' => $createdBy,
                        ]);
                        $company->account_id = $account->id;
                        $company->save();
                    }
                    $expenseAccountId = $company->account_id;
                }
            }

            $expense = $this->transactions->recordExpense([
                'amount' => $totalPurchase,
                'from_account_id' => $expenseAccountId,
                'module' => TransactionModule::HajjUmra->value,
                'related_type' => HajjUmraBooking::class,
                'related_id' => $booking->id,
                'notes' => "تكلفة برنامج {$program->program_name} - {$customer->full_name}",
                'created_by' => $createdBy,
            ]);

            $income = $this->transactions->recordIncome([
                'amount' => $totalSelling,
                'to_account_id' => $customerAccount->id,
                'module' => TransactionModule::HajjUmra->value,
                'related_type' => HajjUmraBooking::class,
                'related_id' => $booking->id,
                'notes' => "بيع برنامج {$program->program_name} - {$customer->full_name}",
                'created_by' => $createdBy,
            ]);

            $booking->update([
                'expense_transaction_id' => $expense->id,
                'income_transaction_id' => $income->id,
            ]);

            // تسجيل دفعة أولية إن وُجدت
            if (! empty($data['initial_payment']) && (float) ($data['initial_payment']['amount'] ?? 0) > 0) {
                $this->addPayment($booking, $data['initial_payment']);
            }

            Log::info('HajjUmra booking created', [
                'booking_id' => $booking->id,
                'customer_id' => $customer->id,
                'program_id' => $program->id,
                'purchase' => $totalPurchase,
                'selling' => $totalSelling,
                'profit' => $profit,
            ]);

            return $this->find($booking->id);
        });
    }

    protected function repostExpenseTransaction(HajjUmraBooking $booking, Transaction $transaction, float $newAmount): Transaction
    {
        $expenseAccountId = (int) $booking->account_id;
        if ($booking->supplier_id) {
            $supplier = UmrahSupplier::find($booking->supplier_id);
            if ($supplier?->account_id) {
                $expenseAccountId = (int) $supplier->account_id;
            }
        } else {
            $program = Program::find($booking->program_id);
            if ($program && $program->executing_company_id) {
                $company = HajjUmraExecutingCompany::find($program->executing_company_id);
                if ($company) {
                    if (! $company->account_id) {
                        $account = Account::create([
                            'name' => 'حساب الشركة المنفذة للحج/العمرة: '.($company->name ?: 'غير مسمى'),
                            'type' => AccountType::Supplier->value,
                            'currency' => 'EGP',
                            'balance' => 0.00,
                            'is_active' => true,
                            'owner_type' => Account::OWNER_TYPE_OWNER,
                            'module_type' => 'hajj_umra',
                            'notes' => 'حساب شركة منفذة تلقائي مضاف من النظام.',
                            'created_by' => $booking->created_by ?? 1,
                        ]);
                        $company->account_id = $account->id;
                        $company->save();
                    }
                    $expenseAccountId = (int) $company->account_id;
                }
            }
        }

        $oldAmount = (float) $transaction->amount;
        $accountChanged = ($expenseAccountId !== (int) $transaction->from_account_id);
        if ($oldAmount === $newAmount && ! $accountChanged) {
            return $transaction;
        }

        $this->transactions->voidTransactionJournal($transaction);
        $transaction->delete();

        return $this->transactions->recordExpense([
            'amount' => $newAmount,
            'from_account_id' => $expenseAccountId,
            'module' => TransactionModule::HajjUmra->value,
            'related_type' => HajjUmraBooking::class,
            'related_id' => $booking->id,
            'notes' => $transaction->notes,
            'created_by' => $transaction->created_by ?? 1,
        ]);
    }

    protected function repostIncomeTransaction(HajjUmraBooking $booking, Transaction $transaction, float $newAmount): Transaction
    {
        $oldAmount = (float) $transaction->amount;
        if ($oldAmount === $newAmount) {
            return $transaction;
        }

        $customerAccount = $this->ensureCustomerAccount($booking->customer_id);

        $this->transactions->voidTransactionJournal($transaction);
        $transaction->delete();

        return $this->transactions->recordIncome([
            'amount' => $newAmount,
            'to_account_id' => $customerAccount->id,
            'module' => TransactionModule::HajjUmra->value,
            'related_type' => HajjUmraBooking::class,
            'related_id' => $booking->id,
            'notes' => $transaction->notes,
            'created_by' => $transaction->created_by,
        ]);
    }

    public function update(HajjUmraBooking $booking, array $data): HajjUmraBooking
    {
        return DB::transaction(function () use ($booking, $data) {
            $fields = collect($data)->only([
                'companion_customer_id',
                'supplier_id',
                'status',
                'agent_name',
                'notes',
                'employee_id',
                'per_person',
                'accommodation_choice',
            ])->all();

            $purchase = (float) (array_key_exists('purchase_price', $data) ? $data['purchase_price'] : $booking->purchase_price);
            $companionPurchase = (float) (array_key_exists('companion_purchase_price', $data) ? $data['companion_purchase_price'] : $booking->companion_purchase_price);
            $selling = (float) (array_key_exists('selling_price', $data) ? $data['selling_price'] : $booking->selling_price);
            $companionSelling = (float) (array_key_exists('companion_selling_price', $data) ? $data['companion_selling_price'] : $booking->companion_selling_price);
            $accommodationExtra = (float) (array_key_exists('accommodation_extra_charge', $data) ? $data['accommodation_extra_charge'] : $booking->accommodation_extra_charge);

            $totalPurchase = $purchase + $companionPurchase;
            $totalSelling = $selling + $companionSelling + $accommodationExtra;
            $profit = round($totalSelling - $totalPurchase, 2);

            $fields['purchase_price'] = $purchase;
            $fields['companion_purchase_price'] = $companionPurchase;
            $fields['selling_price'] = $selling;
            $fields['companion_selling_price'] = $companionSelling;
            $fields['accommodation_extra_charge'] = $accommodationExtra;
            $fields['profit'] = $profit;

            $booking->update($fields);

            // Update passengers if provided
            if (array_key_exists('passengers', $data) && is_array($data['passengers'])) {
                $booking->passengers()->delete();
                foreach ($data['passengers'] as $p) {
                    $booking->passengers()->create([
                        'category' => $p['category'],
                        'count' => (int) $p['count'],
                        'unit_price' => (float) $p['unit_price'],
                        'subtotal' => (float) $p['subtotal'],
                    ]);
                }
            }

            // Sync accounting amounts via void + repost (LedgerBalanceMutationGuard-safe)
            $booking->load(['expenseTransaction', 'incomeTransaction']);
            if ($booking->expenseTransaction) {
                $expense = $this->repostExpenseTransaction($booking, $booking->expenseTransaction, $totalPurchase);
                if ($expense->id !== $booking->expense_transaction_id) {
                    $booking->update(['expense_transaction_id' => $expense->id]);
                }
            }
            if ($booking->incomeTransaction) {
                $income = $this->repostIncomeTransaction($booking, $booking->incomeTransaction, $totalSelling);
                if ($income->id !== $booking->income_transaction_id) {
                    $booking->update(['income_transaction_id' => $income->id]);
                }
            }

            return $this->find($booking->id);
        });
    }

    public function cancel(HajjUmraBooking $booking, ?string $reason = null): HajjUmraBooking
    {
        return DB::transaction(function () use ($booking, $reason) {
            $status = $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status;
            if ($status === HajjUmraStatus::Cancelled->value) {
                throw new \RuntimeException('الحجز ملغى مسبقاً.');
            }

            $booking->load(['payments.transaction', 'expenseTransaction', 'incomeTransaction']);

            foreach ($booking->payments as $payment) {
                if ($payment->transaction) {
                    $this->transactions->voidTransactionJournal($payment->transaction);
                    $payment->transaction->delete();
                }
            }

            if ($booking->incomeTransaction) {
                $this->transactions->voidTransactionJournal($booking->incomeTransaction);
                $booking->incomeTransaction->delete();
            }

            if ($booking->expenseTransaction) {
                $this->transactions->voidTransactionJournal($booking->expenseTransaction);
                $booking->expenseTransaction->delete();
            }

            $note = trim((string) $booking->notes);
            if ($reason) {
                $note = ($note === '' ? '' : $note."\n").'سبب الإلغاء: '.$reason;
            }

            $booking->update([
                'status' => HajjUmraStatus::Cancelled->value,
                'notes' => $note,
                'expense_transaction_id' => null,
                'income_transaction_id' => null,
            ]);

            Log::info('HajjUmra booking cancelled with journal reversal', [
                'booking_id' => $booking->id,
                'reason' => $reason,
            ]);

            return $this->find($booking->id);
        });
    }

    public function addPayment(HajjUmraBooking $booking, array $data): HajjUmraPayment
    {
        return DB::transaction(function () use ($booking, $data) {
            $amount = (float) $data['amount'];
            $accountId = (int) ($data['account_id'] ?? $booking->account_id);
            $createdBy = Auth::id() ?? ($data['created_by'] ?? null);

            $customerAccount = $this->ensureCustomerAccount($booking->customer_id);

            $income = $this->transactions->recordIncome([
                'amount' => $amount,
                'to_account_id' => $accountId,
                'contra_account_id' => $customerAccount->id,
                'module' => TransactionModule::HajjUmra->value,
                'related_type' => HajjUmraBooking::class,
                'related_id' => $booking->id,
                'notes' => "دفعة على حجز #{$booking->id}",
                'created_by' => $createdBy,
            ]);

            return $booking->payments()->create([
                'payment_method' => $data['payment_method'] ?? 'cash',
                'amount' => $amount,
                'currency' => $data['currency'] ?? $booking->currency ?? 'EGP',
                'treasury_account' => $data['treasury_account'] ?? 'office_drawer',
                'account_id' => $accountId,
                'transaction_id' => $income->id,
                'transaction_reference' => $data['reference'] ?? $data['transaction_reference'] ?? null,
                'payment_date' => $data['payment_date'] ?? now(),
                'paid_by' => $data['paid_by'] ?? $booking->customer?->full_name ?? '',
                'created_by' => $createdBy,
            ]);
        });
    }

    protected function resolveCustomer(?array $data, ?int $existingId): Customer
    {
        if ($existingId) {
            return Customer::findOrFail($existingId);
        }

        if (! $data || empty($data['phone'])) {
            throw new \InvalidArgumentException('بيانات العميل (الاسم والهاتف) مطلوبة.');
        }

        return Customer::updateOrCreate(
            ['phone' => $data['phone']],
            collect($data)->only([
                'full_name', 'national_id', 'travel_country', 'passport_number', 'passport_expiry',
                'date_of_birth', 'city', 'affiliation', 'notes',
            ])->all()
        );
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

        // Create new account for customer
        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($customer) {
            $account = Account::create([
                'name' => 'حساب العميل: '.$customer->full_name,
                'type' => AccountType::Customer,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'hajj_umra',
                'is_module_vault' => false,
                'notes' => 'حساب تلقائي للعميل #'.$customer->id,
                'created_by' => Auth::id() ?? 1,
            ]);

            $customer->update(['account_id' => $account->id]);

            Log::info('Customer ledger account created automatically', [
                'customer_id' => $customer->id,
                'account_id' => $account->id,
            ]);

            return $account;
        }));
    }
}
