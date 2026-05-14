<?php

namespace App\Services\HajjUmra;

use App\Enums\HajjUmraStatus;
use App\Enums\TransactionModule;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Program;
use App\Services\Finance\TransactionService;
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
        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['program_id'])) {
            $query->where('program_id', $filters['program_id']);
        }
        if (!empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }
        if (!empty($filters['from_date'])) {
            $query->whereDate('created_at', '>=', $filters['from_date']);
        }
        if (!empty($filters['to_date'])) {
            $query->whereDate('created_at', '<=', $filters['to_date']);
        }
        if (!empty($filters['search'])) {
            $term = $filters['search'];
            $query->whereHas('customer', function ($q) use ($term) {
                $q->where('full_name', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('passport_number', 'like', "%{$term}%");
            });
        }
        if (!empty($filters['program_type'])) {
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

            $purchase = (float) $data['purchase_price'];
            $selling = (float) $data['selling_price'];
            $profit = round($selling - $purchase, 2);

            $accountId = (int) ($data['account_id'] ?? 0);
            if ($accountId === 0) {
                $vault = \App\Models\Account::getModuleVault('hajj_umra');
                if (!$vault) {
                    throw new \RuntimeException('لم يتم العثور على الخزينة الرسمية لموديول الحج والعمرة. يرجى اختيار حساب أو ضبط الخزينة الرسمية.');
                }
                $accountId = $vault->id;
            }
            
            $createdBy = Auth::id() ?? ($data['employee_id'] ?? null);

            $booking = HajjUmraBooking::create([
                'customer_id' => $customer->id,
                'companion_customer_id' => $data['companion_customer_id'] ?? null,
                'program_id' => $program->id,
                'module' => TransactionModule::HajjUmra->value,
                'purchase_price' => $purchase,
                'selling_price' => $selling,
                'profit' => $profit,
                'currency' => $data['currency'] ?? 'EGP',
                'per_person' => (bool) ($data['per_person'] ?? true),
                'status' => $data['status'] ?? HajjUmraStatus::Confirmed->value,
                'agent_name' => $data['agent_name'] ?? ($customer->full_name ?? ''),
                'notes' => $data['notes'] ?? null,
                'account_id' => $accountId,
                'employee_id' => $data['employee_id'] ?? $createdBy,
                'created_by' => $createdBy,
            ]);

            $expense = $this->transactions->recordExpense([
                'amount' => $purchase,
                'from_account_id' => $accountId,
                'module' => TransactionModule::HajjUmra->value,
                'related_type' => HajjUmraBooking::class,
                'related_id' => $booking->id,
                'notes' => "تكلفة برنامج {$program->program_name} - {$customer->full_name}",
                'created_by' => $createdBy,
            ]);

            $income = $this->transactions->recordIncome([
                'amount' => $selling,
                'to_account_id' => $accountId,
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
            if (!empty($data['initial_payment']) && (float) ($data['initial_payment']['amount'] ?? 0) > 0) {
                $this->addPayment($booking, $data['initial_payment']);
            }

            Log::info('HajjUmra booking created', [
                'booking_id' => $booking->id,
                'customer_id' => $customer->id,
                'program_id' => $program->id,
                'purchase' => $purchase,
                'selling' => $selling,
                'profit' => $profit,
            ]);

            return $this->find($booking->id);
        });
    }

    public function update(HajjUmraBooking $booking, array $data): HajjUmraBooking
    {
        return DB::transaction(function () use ($booking, $data) {
            $fields = collect($data)->only([
                'companion_customer_id',
                'status',
                'agent_name',
                'notes',
                'employee_id',
                'per_person',
            ])->all();

            // إعادة حساب الربح إذا تغيرت الأسعار
            if (array_key_exists('purchase_price', $data) || array_key_exists('selling_price', $data)) {
                $purchase = (float) ($data['purchase_price'] ?? $booking->purchase_price);
                $selling = (float) ($data['selling_price'] ?? $booking->selling_price);
                $fields['purchase_price'] = $purchase;
                $fields['selling_price'] = $selling;
                $fields['profit'] = round($selling - $purchase, 2);
            }

            $booking->update($fields);

            return $this->find($booking->id);
        });
    }

    public function cancel(HajjUmraBooking $booking, ?string $reason = null): HajjUmraBooking
    {
        return DB::transaction(function () use ($booking, $reason) {
            $note = trim((string) $booking->notes);
            if ($reason) {
                $note = ($note === '' ? '' : $note."\n").'سبب الإلغاء: '.$reason;
            }

            $booking->update([
                'status' => HajjUmraStatus::Cancelled->value,
                'notes' => $note,
            ]);

            return $this->find($booking->id);
        });
    }

    /**
     * تسجيل دفعة جديدة لحجز قائم. تنشئ HajjUmraPayment + قيد إيراد محاسبي
     * ($incomeTx) إلى نفس account_id الذي اختاره المستخدم.
     */
    public function addPayment(HajjUmraBooking $booking, array $data): HajjUmraPayment
    {
        return DB::transaction(function () use ($booking, $data) {
            $amount = (float) $data['amount'];
            $accountId = (int) ($data['account_id'] ?? $booking->account_id);
            $createdBy = Auth::id() ?? ($data['created_by'] ?? null);

            $income = $this->transactions->recordIncome([
                'amount' => $amount,
                'to_account_id' => $accountId,
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

        if (!$data || empty($data['phone'])) {
            throw new \InvalidArgumentException('بيانات العميل (الاسم والهاتف) مطلوبة.');
        }

        return Customer::updateOrCreate(
            ['phone' => $data['phone']],
            collect($data)->only([
                'full_name', 'national_id', 'passport_number', 'passport_expiry',
                'date_of_birth', 'city', 'affiliation', 'notes',
            ])->all()
        );
    }
}
