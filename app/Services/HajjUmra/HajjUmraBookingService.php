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
use App\Models\User;
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

            // Wrapped in HajjUmraBooking::runProfitMutation() so the ModelProfitMutationGuard lets
            // the canonical `profit` write through — see HajjUmraBooking::booted()
            // saving observer.
            $booking = HajjUmraBooking::runProfitMutation(function () use ($customer, $program, $data, $purchase, $companionPurchase, $selling, $companionSelling, $profit, $accountId, $createdBy, $accommodationExtra) {
                return HajjUmraBooking::create([
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
            });

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

            // ─────────────────────────────────────────────────────────────────
            // FIX (GAP #HJ-6, fixed 2026-07-16):
            //   If the expense falls back to the cashbox (no supplier AND no
            //   executing company), the cashbox must have enough balance to
            //   cover the purchase cost. Without this guard, the system could
            //   silently book expenses it cannot pay, leading to negative
            //   balances and broken reconciliation downstream.
            // ─────────────────────────────────────────────────────────────────
            if ($expenseAccountId === $accountId) {
                $cashbox = Account::findOrFail($accountId);
                if ((float) $cashbox->balance < (float) $totalPurchase) {
                    throw new \RuntimeException(
                        'رصيد الخزينة غير كافٍ لتغطية تكلفة الحجز: '
                        .'الرصيد الحالي ' . number_format((float) $cashbox->balance, 2)
                        .' والمطلوب ' . number_format((float) $totalPurchase, 2)
                        .' (' . $cashbox->name . '). '
                        .'يُرجى اختيار مورّد أو شركة منفذة لتحمّل تكلفة الحجز، '
                        .'أو إيداع رصيد كافٍ في الخزينة.'
                    );
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

        // ✦ Phase 2026-07-11 FIX: previously this called
        //   `voidTransactionJournal($transaction); $transaction->delete();`
        //   which destroyed the original `transactions` row AND its
        //   `account_entries` — violating the project rule "the original
        //   transaction + entries are never deleted or modified".
        //   Now we use `reverseTransaction()` which ADDS inverse
        //   account_entries on the SAME transaction_id and updates the
        //   transaction notes prefix (`عكس: `). The original row stays.
        $this->transactions->reverseTransaction($transaction);

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

        // ✦ Phase 2026-07-11 FIX: same reverseTransaction-based pattern as
        //   repostExpenseTransaction — do NOT destroy the original
        //   transaction row. See the inline note there for rationale.
        $this->transactions->reverseTransaction($transaction);

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

    /**
     * @deprecated INCIDENT-2026-08-17: Tourism No-Edit Contract. Always throws.
     *   Cancellation is the supported correction path.
     */
    public function update(HajjUmraBooking $booking, array $data): HajjUmraBooking
    {
        throw new \LogicException(
            'HajjUmraBookingService::update is disabled by Tourism no-edit contract (2026-08-17). '
            .'Cancellation is the supported correction path.'
        );
    }

    public function cancel(HajjUmraBooking $booking, ?string $reason = null): HajjUmraBooking
    {
        return DB::transaction(function () use ($booking, $reason) {
            $status = $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status;
            if ($status === HajjUmraStatus::Cancelled->value) {
                throw new \RuntimeException('الحجز ملغى مسبقاً.');
            }
            // Phase 10.5 FIX — close the asymmetric terminal-state gap. The
            // previous implementation only guarded against double-cancel; a
            // refunded booking could be cancelled, but a cancelled booking
            // could not be refunded. The state machine is now symmetric:
            //   Cancelled ↔ Refunded are both terminal.
            // The refund() path in HajjUmraRefundService already rejects
            // status=Cancelled; this adds the mirror rejection.
            if ($status === HajjUmraStatus::Refunded->value) {
                throw new \RuntimeException(
                    'لا يمكن إلغاء حجز تم استرداده بالكامل (status=refunded). '
                    .'الحالة نهائية.'
                );
            }

            $booking->load(['payments.transaction', 'expenseTransaction', 'incomeTransaction']);

            // ✦ Phase 2026-07-11 FIX (Q1+Q2): was:
            //   foreach ($booking->payments as $payment) {
            //       if ($payment->transaction) {
            //           $this->transactions->voidTransactionJournal($payment->transaction);
            //           $payment->transaction->delete();   // ← destructive
            //       }
            //   }
            //   …same pattern for income + expense transactions
            //
            //   This destroyed the original `transactions` rows and their
            //   `account_entries`, violating the project-wide rule that
            //   "the original transaction + entries are never deleted or
            //   modified — reversals are always ADDITIVE".
            //
            //   The replacement uses `TransactionService::reverseTransaction()`,
            //   which adds inverse `account_entries` rows on the SAME
            //   `transaction_id` and updates the transaction notes with a
            //   `عكس: ` prefix. The original rows are preserved.
            //
            //   The booking row stays visible (status=Cancelled) per Q3 —
            //   no soft-delete here. For admin-driven soft-delete with full
            //   reversal, see `deleteBookingWithReversal()` below.
            foreach ($booking->payments as $payment) {
                if ($payment->transaction) {
                    $this->transactions->reverseTransaction($payment->transaction);
                }
            }

            if ($booking->incomeTransaction) {
                $this->transactions->reverseTransaction($booking->incomeTransaction);
            }

            if ($booking->expenseTransaction) {
                $this->transactions->reverseTransaction($booking->expenseTransaction);
            }

            $note = trim((string) $booking->notes);
            if ($reason) {
                $note = ($note === '' ? '' : $note."\n").'سبب الإلغاء: '.$reason;
            }

            $booking->update([
                'status' => HajjUmraStatus::Cancelled->value,
                'notes' => $note,
                // Keep the *_transaction_id pointers — `reverseTransaction()` ADDS
                // entries on the same transaction_id; the FK references stay valid.
                // Previously these were nulled here, which left dangling
                // references after the old destructive delete.
            ]);

            Log::info('HajjUmra booking cancelled (additive reversal applied)', [
                'booking_id' => $booking->id,
                'reason' => $reason,
                'payments_reversed' => $booking->payments->filter(fn ($p) => $p->transaction)->count(),
                'income_reversed' => (bool) $booking->incomeTransaction,
                'expense_reversed' => (bool) $booking->expenseTransaction,
            ]);

            return $this->find($booking->id);
        });
    }

    /**
     * Administrative soft-delete with full financial reversal.
     *
     * Mirrors the canonical `FlightBookingService::deleteBookingWithReversal()`
     * pattern (same name, same shape, same invariants) for the HajjUmra
     * module. Use this when an admin needs to fully remove a booking from
     * active lists while:
     *   ① posting additive `account_entries` reversals (never destroying
     *      the original `transactions` / `account_entries` rows), AND
     *   ② soft-deleting the booking row (hiding it from views/reports).
     *
     * For customer-initiated cancellation that should keep the booking row
     * visible (status=Cancelled), use `cancel($booking, $reason)` instead.
     *
     * Idempotency: throws RuntimeException if the booking is already
     * soft-deleted, matching the Flight pattern.
     *
     * @throws \RuntimeException on duplicates or when the guard is misconfigured
     */
    public function deleteBookingWithReversal(int $bookingId, ?User $actor = null): bool
    {
        // ── INVARIANT: actor identity from server-side auth ──
        // Phase 8.6 B1 — mirror HajjUmraRefundService::refund() (L65-70):
        // NEVER trust Auth::id() ?: 1. If no actor is supplied AND no
        // authenticated user exists, reject — deletion operations cannot
        // be attributed to a system user.
        $actor = $actor ?? auth()->user();
        if (! $actor instanceof User) {
            throw new \RuntimeException(
                'HajjUmraBookingService::deleteBookingWithReversal requires an authenticated actor. '
                .'Deletion operations cannot be attributed to a system user.'
            );
        }
        $userId = $actor->id;

        // Wrap in the canonical deletion gate so the model's `deleting` event
        // allows the soft-delete. Same depth-counter shape as
        // LedgerBalanceMutationGuard; per-model isolation comes free from
        // ModelDeletionGuard trait's per-class statics (FlightBooking's gate
        // cannot open HajjUmraBooking's gate and vice versa).
        return HajjUmraBooking::run(function () use ($bookingId, $userId) {
            return DB::transaction(function () use ($bookingId, $userId) {
                // 1) Lock + reload with relations.
                //    withTrashed() so an already-soft-deleted booking can be
                //    located — we want a clean idempotency error, not "No query results".
                $booking = HajjUmraBooking::query()
                    ->withTrashed()
                    ->with(['payments.transaction', 'expenseTransaction', 'incomeTransaction'])
                    ->lockForUpdate()
                    ->findOrFail($bookingId);

                // 2) Idempotency guard — second call returns a clean Arabic error.
                if ($booking->trashed()) {
                    throw new \RuntimeException(
                        'هذا الحجز محذوف بالفعل (soft delete) — لا يمكن عكسه مرة ثانية.'
                    );
                }

                $userIdEffective = $userId;

                Log::info('HajjUmraBookingService::deleteBookingWithReversal — starting', [
                    'booking_id' => $booking->id,
                    'status' => $booking->status?->value ?? (string) $booking->status,
                    'payments_count' => $booking->payments->count(),
                    'has_income' => (bool) $booking->incomeTransaction,
                    'has_expense' => (bool) $booking->expenseTransaction,
                    'user_id' => $userIdEffective,
                ]);

                // 3) Reverse each payment transaction (additive — never destructive).
                foreach ($booking->payments as $payment) {
                    if ($payment->transaction) {
                        $this->transactions->reverseTransaction($payment->transaction);
                    }
                }

                // 4) Reverse the booking's income + expense transactions (additive).
                if ($booking->incomeTransaction) {
                    $this->transactions->reverseTransaction($booking->incomeTransaction);
                }
                if ($booking->expenseTransaction) {
                    $this->transactions->reverseTransaction($booking->expenseTransaction);
                }

                // 5) Soft-delete the payments (uses new SoftDeletes trait).
                //    The transactions themselves stay — only `account_entries`
                //    inverses were added by reverseTransaction().
                $booking->payments()->delete();

                // 6) Soft-delete the booking row itself. Allowed because we are
                //    inside HajjUmraBooking::run(...) which flipped the model's
                //    deletion gate open for the canonical reversal flow.
                $booking->delete();

                Log::info('HajjUmraBookingService::deleteBookingWithReversal — complete', [
                    'booking_id' => $booking->id,
                    'user_id' => $userIdEffective,
                ]);

                return true;
            });
        });
    }

    public function addPayment(HajjUmraBooking $booking, array $data): HajjUmraPayment
    {
        // ─────────────────────────────────────────────────────────────────
        // FIX (GAP #HJ-4 + #HJ-5, fixed 2026-07-16):
        //   The system used to accept payments on cancelled OR soft-deleted
        //   bookings, which would corrupt the ledger and let admins add
        //   money to closed bookings. We now guard at the service level —
        //   this is the canonical entry point for all payment additions
        //   (Tinker, API, Filament form, direct call).
        // ─────────────────────────────────────────────────────────────────
        $status = $booking->status instanceof \BackedEnum ? $booking->status->value : (string) $booking->status;
        if ($status === \App\Enums\HajjUmraStatus::Cancelled->value) {
            throw new \RuntimeException(
                'لا يمكن إضافة دفعة على حجز مُلغى (status=cancelled). '
                .'يجب استخدام HajjUmraRefundService::refund() لاسترداد المبالغ.'
            );
        }
        if ($status === \App\Enums\HajjUmraStatus::Refunded->value) {
            throw new \RuntimeException(
                'لا يمكن إضافة دفعة على حجز تم استرداده بالكامل (status=refunded).'
            );
        }
        if ($booking->trashed()) {
            throw new \RuntimeException(
                'لا يمكن إضافة دفعة على حجز محذوف (soft-deleted). '
                .'يجب استخدام deleteBookingWithReversal() للعكس الإداري.'
            );
        }

        // ─────────────────────────────────────────────────────────────────
        // PRE-PHASE-B IDEMPOTENCY FIX (2026-08-15):
        //   Replay protection for the payment endpoint.
        //
        //   Identity:  (hajj_umra_booking_id, idempotency_key)
        //   Stored on: hajj_umra_payments.idempotency_key  (nullable, 100 chars)
        //   Enforced:  UNIQUE index hup_idem_uniq  (MySQL allows multiple NULLs
        //              so legacy callers that don't supply a key are unaffected).
        //
        //   Layered protection:
        //     1. Pre-check (this method, inside the lock): SELECT existing
        //        payment with same (booking_id, idempotency_key). If found
        //        and not soft-deleted → return it (idempotent return).
        //     2. DB-level UNIQUE constraint (the migration) — backstop in
        //        case two callers bypass the lock. The INSERT will fail
        //        with MySQL error 1062 / SQLSTATE 23000, which we catch
        //        and convert to an idempotent return.
        //     3. `lockForUpdate()` on the booking row serializes concurrent
        //        calls on the same booking. The lock is held for the
        //        duration of the transaction (released on commit/rollback).
        //
        //   Backward compat:
        //     - When `idempotency_key` is null/empty, no protection is
        //       applied and the call may still be replayed. Legacy
        //       callers keep their existing behavior.
        //     - When supplied, replays return the original payment
        //       (200 OK with the original row) — no second financial
        //       mutation, no extra AccountEntry rows, no extra Transaction.
        // ─────────────────────────────────────────────────────────────────
        $idempotencyKey = isset($data['idempotency_key']) && $data['idempotency_key'] !== ''
            ? (string) $data['idempotency_key']
            : null;

        try {
            return DB::transaction(function () use ($booking, $data, $idempotencyKey) {
                // Serialize concurrent calls on the same booking.
                $locked = HajjUmraBooking::query()->lockForUpdate()->find($booking->id);
                if (! $locked) {
                    throw new \RuntimeException("Booking {$booking->id} not found.");
                }

                // Layer 1 — pre-check: if a payment already exists for this
                // (booking, idempotency_key), return it instead of creating
                // a duplicate. We also honor soft-deletes: a soft-deleted
                // payment with the same key is treated as "deleted" and a
                // new payment may be inserted (the unique index will be
                // violated only by ACTIVE rows, so the DB would block a
                // second ACTIVE one with the same key — but soft-deleted
                // rows coexist).
                if ($idempotencyKey !== null) {
                    $existing = HajjUmraPayment::query()
                        ->where('hajj_umra_booking_id', $locked->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->first();
                    if ($existing) {
                        // Tag the returned model with a transient flag so
                        // the HTTP layer can surface 200 OK + replay marker
                        // instead of 201 Created.
                        $existing->idempotent_replay = true;
                        return $existing;
                    }
                }

                $amount = (float) $data['amount'];
                $accountId = (int) ($data['account_id'] ?? $booking->account_id);
                $createdBy = Auth::id() ?? ($data['created_by'] ?? null);

                // Phase 10.2 FIX — reject cross-currency payment.
                // Same shape as the Phase 9.12 Visa fix: recordJournalTransfer
                // falls back to using the source amount as the destination
                // amount when currencies don't match and no conversion rate is
                // supplied (TransactionService lines 728-741), silently
                // corrupting the destination ledger. Hajj/Umra has the same
                // defect; the fix is the same — reject at the service boundary
                // with a clear Arabic error.
                $account = Account::query()->findOrFail($accountId);
                if (strtoupper((string) $account->currency) !== strtoupper((string) ($locked->currency ?? 'EGP'))) {
                    throw new \RuntimeException(
                        'عملة الحجز ('.($locked->currency ?? 'EGP').') لا تطابق عملة حساب الدفع ('
                        .$account->currency.'). يجب إجراء تحويل عملات عبر نظام التحويل المعتمد.'
                    );
                }

                $customerAccount = $this->ensureCustomerAccount($booking->customer_id);

                // FIX (latent-bug-after-FC-AUDIT-20260814): a payment on an existing
                // booking is a TRANSFER (customer AR → treasury), NOT a new Income.
                // The booking's sale Income was already recorded at create(); a
                // payment represents cash movement against existing debt. Using
                // recordIncome() here would, after the FC-AUDIT D1 fix, set
                // type=Income and trigger the duplicate-income guard at
                // TransactionService::recordJournalTransfer (lines 612–625) on
                // the second payment. We now use recordJournalTransfer() with
                // explicit type=Transfer, which (a) matches the pre-FC-AUDIT
                // behaviour exactly (silent default) and (b) is the semantically
                // correct category for cash collection against a known sale.
                $income = $this->transactions->recordJournalTransfer([
                    'amount' => $amount,
                    'from_account_id' => $customerAccount->id,
                    'to_account_id' => $accountId,
                    'module' => TransactionModule::HajjUmra->value,
                    'type' => \App\Enums\TransactionType::Transfer->value,
                    'related_type' => HajjUmraBooking::class,
                    'related_id' => $booking->id,
                    'notes' => "دفعة على حجز #{$booking->id}",
                    'created_by' => $createdBy,
                ]);

                try {
                    return $booking->payments()->create([
                        'payment_method' => $data['payment_method'] ?? 'cash',
                        'amount' => $amount,
                        'currency' => $data['currency'] ?? $booking->currency ?? 'EGP',
                        'treasury_account' => $data['treasury_account'] ?? 'office_drawer',
                        'account_id' => $accountId,
                        'transaction_id' => $income->id,
                        'transaction_reference' => $data['reference'] ?? $data['transaction_reference'] ?? null,
                        'idempotency_key' => $idempotencyKey,
                        'payment_date' => $data['payment_date'] ?? now(),
                        'paid_by' => $data['paid_by'] ?? $booking->customer?->full_name ?? '',
                        'created_by' => $createdBy,
                    ]);
                } catch (\Illuminate\Database\QueryException $qe) {
                    // Layer 2 — defense in depth. The pre-check above plus
                    // the `lockForUpdate` should make this catch unreachable
                    // in normal operation, but if two transactions somehow
                    // race past the pre-check (e.g. on a connection pool
                    // without row locks), the UNIQUE index is the last line.
                    //
                    // MySQL: SQLSTATE 23000, error code 1062 ("Duplicate entry").
                    // SQLite: SQLSTATE 23000 with a similar message.
                    if ($this->isDuplicateKeyError($qe) && $idempotencyKey !== null) {
                        // Re-query: the row that another tx created must now
                        // be visible. Return it as the idempotent result.
                        $existing = HajjUmraPayment::query()
                            ->where('hajj_umra_booking_id', $locked->id)
                            ->where('idempotency_key', $idempotencyKey)
                            ->first();
                        if ($existing) {
                            $existing->idempotent_replay = true;
                            return $existing;
                        }
                    }
                    throw $qe;
                }
            });
        } catch (\Illuminate\Database\QueryException $qe) {
            // Outer catch: if the inner transaction's DB::transaction
            // re-raised a duplicate-key error from `recordJournalTransfer`
            // or the create() call after we've already inserted the
            // payment row, we surface a clean idempotent return when the
            // (booking, key) is still resolvable.
            if ($this->isDuplicateKeyError($qe) && $idempotencyKey !== null) {
                $existing = HajjUmraPayment::query()
                    ->where('hajj_umra_booking_id', $booking->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();
                if ($existing) {
                    $existing->idempotent_replay = true;
                    return $existing;
                }
            }
            throw $qe;
        }
    }

    /**
     * Identify a "duplicate entry on unique index" QueryException across
     * MySQL and SQLite. SQLSTATE 23000 is the standard; MySQL error code
     * 1062 is the canonical "Duplicate entry" code.
     */
    private function isDuplicateKeyError(\Illuminate\Database\QueryException $qe): bool
    {
        $sqlState = (string) ($qe->errorInfo[0] ?? '');
        if ($sqlState === '23000') {
            return true;
        }
        $code = (int) ($qe->errorInfo[1] ?? 0);
        return $code === 1062;
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
                // Phase 1.Bend3 fix: CustomerLedgerObserver creates a generic
                // 'office'-tagged account the moment a Customer row is
                // inserted. When that customer is later used in a HajjUmra
                // booking flow we re-tag the account to 'hajj_umra' so it
                // surfaces in the strict module_type='hajj_umra' queries
                // (TreasuryService line 521). Wrapped in
                // LedgerBalanceMutationGuard because touching `balance`
                // — even to confirm 0.00 — would otherwise trip the
                // Account::updating boot guard.
                if ($account->module_type !== 'hajj_umra') {
                    LedgerBalanceMutationGuard::run(function () use ($account) {
                        $account->module_type = 'hajj_umra';
                        $account->save();
                    });
                }

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
