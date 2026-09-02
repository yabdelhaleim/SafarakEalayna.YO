<?php

namespace App\Services\Wallet;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Enums\WalletTransactionType;
use App\Models\Account;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Wallet\WalletTransaction;
use App\Models\Wallet\WalletType;
use App\Services\Finance\DeferredTransactionDeletionGuard;
use App\Services\Finance\TransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WalletTransactionService
{
    public function __construct(
        protected TransactionService $transactionService,
        ?DeferredTransactionDeletionGuard $deletionGuard = null,
    ) {
        $this->deletionGuard = $deletionGuard
            ?? app(DeferredTransactionDeletionGuard::class);
    }

    public function getAllTransactions(array $filters): LengthAwarePaginator
    {
        $query = WalletTransaction::with([
            'walletType',
            'customer',
            'walletAccount',
            'cashAccount',
            'employee',
            'createdBy',
        ]);

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['wallet_type_id'])) {
            $query->where('wallet_type_id', $filters['wallet_type_id']);
        }

        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (! empty($filters['employee_id'])) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (! empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('customer_name', 'like', '%'.$filters['search'].'%')
                    ->orWhere('wallet_number', 'like', '%'.$filters['search'].'%');
            });
        }

        if (! empty($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date'].' 00:00:00');
        }

        if (! empty($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date'].' 23:59:59');
        }

        $perPage = min($filters['per_page'] ?? 20, 100);

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Normalize a monetary value to the canonical 2-decimal half-up precision.
     *
     * FINDING D-V2-009 (P2, MEDIUM) REMEDIATION (2026-08-26):
     * Pre-fix, raw 3-decimal inputs (e.g. 100.005) reached three different
     * layers with three different representations:
     *   - wallet_transactions.amount stored as 100.01 (decimal:2 cast rounds)
     *   - account_entries.debit stored as 100.005 (raw value posted)
     *   - accounts.balance derived from balance_after = 9900.00
     * This created a split-brain where reports aggregating WT.amount disagreed
     * with reports aggregating account_entries.
     *
     * Post-fix: every monetary value MUST be normalized to 2-decimal
     * half-up precision at the service layer BEFORE it touches:
     *   - WalletTransaction::create() / ->amount / ->service_fee / ->total_amount
     *   - TransactionService::recordIncome() / recordExpense() / recordJournalTransfer()
     *   - AccountEntry::create() debit/credit fields
     *   - Account::balance mutation
     *
     * After normalization, 100.005 → 100.01 EVERYWHERE. One canonical value.
     *
     * Implementation uses bcmath (PHP_INT_MAX-safe) with a half-away-from-zero
     * offset of 0.005, identical to tests/Feature/Wallet/Support/Decimal::round().
     */
    public static function normalizeAmount(int|float|string $value): float
    {
        $value = (string) $value;
        if ($value === '' || $value === null) {
            return 0.00;
        }
        $negative = str_starts_with($value, '-');
        $abs = $negative ? substr($value, 1) : $value;
        // bcadd 0.005 to round-half-away-from-zero at 2 decimals.
        $rounded = bcadd($abs, '0.005', 2);

        return (float) ($negative && $rounded !== '0' && ! str_contains($rounded, '-')
            ? '-' . $rounded
            : $rounded);
    }

    public function createTransaction(array $data): WalletTransaction
    {
        // ─────────────────────────────────────────────────────────────────
        // IDM-1 REMEDIATION (2026-08-20) — replay protection for wallet
        // transactions. Mirrors the established Hajj/Umra, Flight, Visa,
        // and Bus idempotency pattern.
        //
        //   Identity:    (created_by, idempotency_key)
        //   Stored on:   wallet_transactions.idempotency_key  (nullable, 100 chars)
        //   Enforced:    UNIQUE index `wt_idem_uniq` (migration 2026_08_20_120000)
        //
        //   Layered protection:
        //     1. Pre-check inside DB::transaction: SELECT existing WalletTransaction
        //        with same (created_by, idempotency_key). If found and not
        //        soft-deleted → return it as idempotent_replay=true. The caller
        //        (controller) maps this to HTTP 200 + body flag.
        //     2. DB-level UNIQUE constraint (the migration). Even if two
        //        callers bypass the pre-check (race, buggy client, raw SQL),
        //        the INSERT will fail with SQLSTATE 23000 / MySQL code 1062.
        //        The catch below re-queries and returns the existing row
        //        idempotently.
        //     3. Soft-deleted rows are NOT treated as replay blockers.
        //        A soft-deleted row has a non-null `deleted_at`; the
        //        pre-check filters them out so a fresh INSERT is allowed.
        //
        //   Backward compat: when `idempotency_key` is null/empty, no
        //   protection is applied. Legacy callers keep their existing
        //   behavior — no checkpoints, no errors.
        // ─────────────────────────────────────────────────────────────────
        $idempotencyKey = isset($data['idempotency_key']) && $data['idempotency_key'] !== ''
            ? (string) $data['idempotency_key']
            : null;
        // Resolve the principal (created_by) so the (created_by, key)
        // scope is consistent with the UNIQUE index. Auth::id() is the
        // authenticated user; fall back to the request-supplied value
        // when called from a non-HTTP context (e.g. jobs, tests).
        $createdByForIdem = (int) (Auth::id() ?? ($data['created_by'] ?? 1));

        try {
            return DB::transaction(function () use ($data, $idempotencyKey, $createdByForIdem) {
                // Layer 1 — pre-check. If a non-soft-deleted row with the
                // same (created_by, idempotency_key) exists, return it
                // immediately. No new WalletTransaction, no new ledger
                // entries, no new audit log. The transient `idempotent_replay`
                // flag is read by the controller to return HTTP 200.
                if ($idempotencyKey !== null) {
                    $existing = WalletTransaction::query()
                        ->where('created_by', $createdByForIdem)
                        ->where('idempotency_key', $idempotencyKey)
                        // Soft-delete-aware: only ACTIVE rows block a replay.
                        ->whereNull('deleted_at')
                        ->first();
                    if ($existing) {
                        $existing->idempotent_replay = true;

                        return $existing;
                    }

                    // Soft-deleted row with the same key: release the key
                    // so the new INSERT can succeed. The UNIQUE constraint
                    // (created_by, idempotency_key) does NOT distinguish
                    // soft-deleted rows from active ones, so a fresh
                    // INSERT would collide without this NULL-out. The
                    // soft-deleted row keeps its `deleted_at` for audit;
                    // only the idempotency_key is cleared.
                    //
                    // IMPORTANT: use `withTrashed()` because the default
                    // SoftDeletes global scope HIDES soft-deleted rows
                    // from `query()`. Without it, the UPDATE silently
                    // matches 0 rows and the INSERT collides downstream.
                    //
                    // This is safe inside the DB transaction: if the new
                    // INSERT/INSERT fails for any reason, the
                    // soft-delete's key-revoke rolls back along with
                    // everything else.
                    WalletTransaction::withTrashed()
                        ->where('created_by', $createdByForIdem)
                        ->where('idempotency_key', $idempotencyKey)
                        ->whereNotNull('deleted_at')
                        ->update(['idempotency_key' => null]);
                }

                $rawType = $data['type'];
                $type = $rawType instanceof WalletTransactionType
                    ? $rawType
                    : WalletTransactionType::from((string) $rawType);
                // D-V2-009: normalize to 2-decimal precision BEFORE any further
                // arithmetic or storage. Same canonical value used everywhere downstream.
                $amount = self::normalizeAmount($data['amount']);
                $fee = self::normalizeAmount($data['service_fee'] ?? 0);

                // FINDING CONC-1 (HIGH) REMEDIATED (2026-08-21):
                // Pre-fix: `WalletTransaction::create()` ran BEFORE any row
                // lock was acquired. A burst of concurrent sends could each
                // create their WT row, then queue for the lock inside
                // `recordIncome/recordExpense`. The lock prevented double-spend
                // on the balance check, but WT row count could rise above the
                // count of successful transactions (phantom WT rows on
                // failed-overdraft attempts).
                //
                // Post-fix: acquire `lockForUpdate()` on the wallet_account_id
                // row BEFORE the WT insert. This is the canonical
                // serialization point: any concurrent send targeting the same
                // wallet_account_id will queue here, see the latest balance,
                // and either succeed or fail cleanly. The lock is held until
                // the DB::transaction commits, after the journal legs have
                // also locked the same row (lockForUpdate is re-entrant for
                // the same connection). On rollback, the lock is released.
                //
                // Note: `recordJournalTransfer` ALSO locks the from/to accounts
                // (lines 691-696) — that's defense in depth, not redundant.
                // It guards the journal-specific path; this guard protects
                // the WT insert path.
                $walletAccountId = (int) $data['wallet_account_id'];
                $cashAccountId = (int) $data['cash_account_id'];
                if ($walletAccountId > 0) {
                    Account::query()
                        ->where('id', $walletAccountId)
                        ->lockForUpdate()
                        ->first();
                }
                if ($cashAccountId > 0 && $cashAccountId !== $walletAccountId) {
                    Account::query()
                        ->where('id', $cashAccountId)
                        ->lockForUpdate()
                        ->first();
                }

                // total_amount: للИслаرسال العميل يدفع amount+fee، للاستقبال يأخذ amount-fee
                $totalAmount = match ($type) {
                    WalletTransactionType::Send => $amount + $fee,
                    WalletTransactionType::Receive => $amount - $fee,
                };
                // customer_name من العميل المرتبط أو من النص الحر
                $customerName = $data['customer_name'] ?? '';
                if (! empty($data['customer_id'])) {
                    $customer = Customer::find($data['customer_id']);
                    if ($customer) {
                        $customerName = $customer->full_name ?? $customer->name ?? $customerName;
                    }
                }
                $walletTypeName = WalletType::find($data['wallet_type_id'])?->name ?? '';
                $createdBy = Auth::id() ?? ($data['created_by'] ?? 1);
                // D-V2-009: normalize amount_paid for consistency.
                $amountPaid = isset($data['amount_paid'])
                    ? self::normalizeAmount($data['amount_paid'])
                    : $totalAmount;

                // Layer 2 — INSERT. The DB UNIQUE constraint is the final
                // backstop. If two concurrent calls bypassed the pre-check
                // (e.g. lock acquisition failed), the second INSERT will
                // fail with SQLSTATE 23000 / MySQL code 1062. The catch
                // block below converts that to an idempotent return.
                try {
                    $record = WalletTransaction::create([
                        'wallet_type_id' => $data['wallet_type_id'],
                        'customer_id' => $data['customer_id'] ?? null,
                        'customer_name' => $customerName,
                        'wallet_number' => $data['wallet_number'],
                        'type' => $type->value,
                        'amount' => $amount,
                        'service_fee' => $fee,
                        'total_amount' => $totalAmount,
                        'amount_paid' => $amountPaid,
                        'wallet_account_id' => $data['wallet_account_id'],
                        'cash_account_id' => $data['cash_account_id'],
                        // WLT-1 (2026-09-02): optional receive-only
                        // destination override. Persisted verbatim;
                        // semantics are enforced in postMainReceivePair()
                        // and postSettlementReceive().
                        'receive_destination_account_id' => $type === WalletTransactionType::Receive
                            ? ($data['receive_destination_account_id'] ?? null)
                            : null,
                        'employee_id' => $data['employee_id'] ?? null,
                        'created_by' => $createdBy,
                        'notes' => $data['notes'] ?? null,
                        'idempotency_key' => $idempotencyKey,
                    ]);
                } catch (QueryException $qe) {
                    // Layer 2 catch — DB UNIQUE backstop.
                    if ($this->isDuplicateKeyError($qe) && $idempotencyKey !== null) {
                        // The pre-check passed but the INSERT still tripped
                        // the UNIQUE. Another call must have created the row
                        // between SELECT and INSERT. Re-query and return the
                        // now-existing row as idempotent_replay.
                        $existing = WalletTransaction::query()
                            ->where('created_by', $createdByForIdem)
                            ->where('idempotency_key', $idempotencyKey)
                            ->whereNull('deleted_at')
                            ->first();
                        if ($existing) {
                            $existing->idempotent_replay = true;

                            return $existing;
                        }
                    }
                    // Not a duplicate-key error, or the row isn't visible
                    // for some reason — rethrow so the outer catch logs it.
                    throw $qe;
                }

                // Wrap in try/catch(Throwable) to surface inner exceptions clearly.
                // Outer try only catches \Exception, but accountForSend/accountForReceive
                // may throw \TypeError or \Error which silently bypass the catch.
                try {
                    // WLT-1 (2026-09-02): re-read the record so the freshly-
                    // persisted `receive_destination_account_id` is visible
                    // to the helper methods (the in-memory $record instance
                    // is the original INSERT result and does NOT auto-reflect
                    // the columns we wrote into the same call).
                    $fresh = $record->fresh();
                    [$incomeTransaction, $expenseTransaction] = match ($type) {
                        WalletTransactionType::Send => $this->accountForSend(
                            $fresh, $amount, $fee, $walletTypeName, $customerName, $createdBy
                        ),
                        WalletTransactionType::Receive => $this->accountForReceive(
                            $fresh, $amount, $fee, $walletTypeName, $customerName, $createdBy
                        ),
                    };
                } catch (\Throwable $inner) {
                    throw $inner;
                }
                $record->update([
                    'income_transaction_id' => $incomeTransaction->id,
                    'expense_transaction_id' => $expenseTransaction->id,
                ]);
                Log::info('WalletTransaction created', [
                    'id' => $record->id,
                    'type' => $type->value,
                    'amount' => $amount,
                    'service_fee' => $fee,
                    'customer_name' => $customerName,
                    'created_by' => $createdBy,
                    'idempotency_key' => $idempotencyKey,
                ]);

                // ── Audit log ─────────────────────────────────────────────
                // نسجل نوع العملية + النطاق المستخدم ( رسمي موديول / قسم مكتب )
                // في جدول audit_logs بدون أي تأثير على المنطق المحاسبي.
                // الفشل هنا لا يكسر العملية (صفر تأثير على الـ flow).
                $this->writeAuditLog(
                    action: 'wallet_transaction.created',
                    record: $record,
                    type: $type,
                    oldValues: null,
                );

                return $record->fresh([
                    'walletType', 'customer', 'walletAccount', 'cashAccount',
                    'receiveDestinationAccount',
                    'employee', 'createdBy', 'incomeTransaction', 'expenseTransaction',
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('WalletTransactionService::createTransaction failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'input' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Identify a "duplicate entry on unique index" QueryException.
     * MySQL: SQLSTATE 23000, error code 1062.
     * SQLite: SQLSTATE 23000 (canonical SQL state).
     * PostgreSQL: SQLSTATE 23000.
     *
     * Mirrors the same helper in HajjUmraBookingService, VisaBookingService,
     * and FlightBookingService to keep the project-wide convention.
     */
    private function isDuplicateKeyError(QueryException $qe): bool
    {
        $sqlState = (string) ($qe->errorInfo[0] ?? '');
        if ($sqlState === '23000') {
            return true;
        }
        $code = (int) ($qe->errorInfo[1] ?? 0);

        return $code === 1062;
    }

    public function updateTransaction(WalletTransaction $transaction, array $data): WalletTransaction
    {
        try {
            return DB::transaction(function () use ($transaction, $data) {
                // Snapshot OLD values BEFORE the update so audit log
                // can record what changed.
                $oldSnapshot = $this->snapshotForAudit($transaction);

                // Detect ACTUAL changes (vs same value) — used to gate the
                // ledger repost so we don't waste DB writes on no-op edits.
                // Mirrors OnlineTransactionService Phase 9 / HajjUmra Phase 8.
                $amountChanged = array_key_exists('amount', $data)
                    && (float) $data['amount'] !== (float) $transaction->amount;
                $serviceFeeChanged = array_key_exists('service_fee', $data)
                    && (float) $data['service_fee'] !== (float) $transaction->service_fee;
                $amountPaidChanged = array_key_exists('amount_paid', $data)
                    && (float) $data['amount_paid'] !== (float) $transaction->amount_paid;
                $walletAccountChanged = array_key_exists('wallet_account_id', $data)
                    && (int) $data['wallet_account_id'] !== (int) $transaction->wallet_account_id;
                $cashAccountChanged = array_key_exists('cash_account_id', $data)
                    && (int) $data['cash_account_id'] !== (int) $transaction->cash_account_id;
                // WLT-1 (2026-09-02): the receive-destination override moves
                // the Expense leg between accounts — must trigger a ledger
                // repost so the old leg is reversed and the new leg is posted
                // against the new destination account.
                $receiveDestinationChanged = array_key_exists('receive_destination_account_id', $data)
                    && (int) ($data['receive_destination_account_id'] ?? 0) !== (int) ($transaction->receive_destination_account_id ?? 0);

                $amountOrFeeChanged = $amountChanged || $serviceFeeChanged;
                $anyLedgerAffectingChange = $amountOrFeeChanged || $amountPaidChanged
                    || $walletAccountChanged || $cashAccountChanged
                    || $receiveDestinationChanged;

                // Compute the new totals BEFORE the model update so we can
                // re-derive total_amount (Send: amount+fee, Receive: amount-fee).
                if ($amountOrFeeChanged) {
                    // D-V2-009: normalize amount + fee to 2-decimal precision
                    // BEFORE re-deriving total_amount. The total must use the
                    // canonical 2-decimal values.
                    $newAmount = self::normalizeAmount($data['amount'] ?? $transaction->amount);
                    $newFee = self::normalizeAmount($data['service_fee'] ?? $transaction->service_fee);
                    $type = $transaction->type instanceof WalletTransactionType
                        ? $transaction->type
                        : WalletTransactionType::from((string) $transaction->type);
                    $data['amount'] = $newAmount;
                    $data['service_fee'] = $newFee;
                    $data['total_amount'] = match ($type) {
                        WalletTransactionType::Send => self::normalizeAmount($newAmount + $newFee),
                        WalletTransactionType::Receive => self::normalizeAmount($newAmount - $newFee),
                    };
                }
                // D-V2-009: normalize amount_paid if supplied.
                if (array_key_exists('amount_paid', $data) && $data['amount_paid'] !== null) {
                    $data['amount_paid'] = self::normalizeAmount($data['amount_paid']);
                }
                $transaction->update($data);
                // ACCOUNTING INTEGRITY (Phase 9 fix — same pattern as
                // OnlineTransactionService / HajjUmraBookingService /
                // VisaBookingService): when amount/service_fee/accounts/
                // amount_paid change, the OLD ledger entries must be
                // reversed (additive — never destructive) and NEW entries
                // posted with the corrected values.
                if ($anyLedgerAffectingChange) {
                    $newMain = $this->repostMainTransactions($transaction);
                    if ($newMain !== null) {
                        [$newIncome, $newExpense] = $newMain;
                        $transaction->update([
                            'income_transaction_id' => $newIncome->id,
                            'expense_transaction_id' => $newExpense->id,
                        ]);
                    }
                    $this->repostSettlementTransaction($transaction);
                }

                // ── Audit log — نسجل التعديل (القيم القديمة vs الجديدة) ──
                $type = $transaction->type instanceof WalletTransactionType
                    ? $transaction->type
                    : WalletTransactionType::from((string) $transaction->type);
                $this->writeAuditLog(
                    action: 'wallet_transaction.updated',
                    record: $transaction->fresh(),
                    type: $type,
                    oldValues: $oldSnapshot,
                );

                return $transaction->fresh([
                    'walletType', 'customer', 'walletAccount', 'cashAccount',
                    'receiveDestinationAccount',
                    'employee', 'createdBy', 'incomeTransaction', 'expenseTransaction',
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('WalletTransactionService::updateTransaction failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'id' => $transaction->id,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * سَنابشوت قبل التعديل — يُمرَّر للـ AuditLog::old_values
     * عشان لما المستخدم يعدّل العملية نقدر نعرف القيم القديمة والجديدة.
     *
     * @return array<string, mixed>
     */
    protected function snapshotForAudit(WalletTransaction $transaction): array
    {
        // نفس منطق النطاق المستخدم في writeAuditLog — عشان old_values
        // يبقى فيه نفس شكل new_values (مهم لعمليات update/delete).
        $walletAccount = $transaction->wallet_account_id
            ? Account::find($transaction->wallet_account_id)
            : null;
        $scope = ($walletAccount
            && (
                $walletAccount->module === 'wallet_transfer'
                || $walletAccount->module_type === 'wallet_transfer'
            )
        ) ? 'official_module' : 'office_department';

        return [
            'type' => $transaction->type instanceof WalletTransactionType
                ? $transaction->type->value
                : (string) $transaction->type,
            'wallet_account_id' => $transaction->wallet_account_id,
            'wallet_account_scope' => $scope,
            'wallet_account_name' => $walletAccount?->name,
            'cash_account_id' => $transaction->cash_account_id,
            'amount' => (float) $transaction->amount,
            'service_fee' => (float) $transaction->service_fee,
            'total_amount' => (float) $transaction->total_amount,
            'amount_paid' => (float) $transaction->amount_paid,
            'customer_id' => $transaction->customer_id,
            'customer_name' => $transaction->customer_name,
            'wallet_number' => $transaction->wallet_number,
            'wallet_type_id' => $transaction->wallet_type_id,
        ];
    }

    /**
     * Repost the main income + expense transactions when amount, fee, or
     * the wallet/cash account IDs change.
     *
     * Mirrors `OnlineTransactionService::repostIncomeTransaction` /
     * `repostExpenseTransaction` (Phase 9): reverse the old transactions
     * (additive — never destructive), then post a fresh pair using the
     * new amounts and the new account IDs. The 3rd optional settlement
     * transaction (amount_paid) is handled separately by
     * `repostSettlementTransaction()`.
     *
     * IMPORTANT: only the MAIN pair is reposted here — calling
     * `accountForSend` / `accountForReceive` (which both post the
     * settlement too) would cause `repostSettlementTransaction` to
     * double-post a settlement. Instead, we use the lean helpers
     * `postMainSendPair` / `postMainReceivePair` and let the settlement
     * helper own the settlement lifecycle.
     *
     * Returns the new [income, expense] transaction pair, or null if the
     * source transaction is missing both ledger links.
     */
    protected function repostMainTransactions(WalletTransaction $transaction): ?array
    {
        if (! $transaction->income_transaction_id || ! $transaction->expense_transaction_id) {
            return null;
        }

        $oldIncome = Transaction::find($transaction->income_transaction_id);
        $oldExpense = Transaction::find($transaction->expense_transaction_id);
        if (! $oldIncome || ! $oldExpense) {
            return null;
        }

        $type = $transaction->type instanceof WalletTransactionType
            ? $transaction->type
            : WalletTransactionType::from((string) $transaction->type);

        $amount = self::normalizeAmount($transaction->amount);
        $fee = self::normalizeAmount($transaction->service_fee);

        $walletTypeName = $transaction->walletType?->name ?? '';
        $customerName = $transaction->customer_name ?: '—';
        $createdBy = $transaction->created_by ?? Auth::id() ?? 1;

        // Reverse old pair BEFORE posting new — guarantees the ledger has
        // a stable audit trail even if something fails mid-flight (the
        // outer DB::transaction wraps the whole thing so a failure rolls
        // back cleanly).
        $this->transactionService->reverseTransaction($oldIncome);
        $this->transactionService->reverseTransaction($oldExpense);

        // Recreate ONLY the main pair — settlement is the settlement
        // helper's responsibility.
        return match ($type) {
            WalletTransactionType::Send => $this->postMainSendPair(
                $transaction, $amount, $fee, $walletTypeName, $customerName, $createdBy
            ),
            WalletTransactionType::Receive => $this->postMainReceivePair(
                $transaction, $amount, $fee, $walletTypeName, $customerName, $createdBy
            ),
        };
    }

    /**
     * Repost the optional settlement transaction (3rd ledger row created
     * when customer_id is set AND amount_paid > 0).
     *
     * The settlement transaction is NOT stored on the model — it is
     * identified by the account pair:
     *   - Send:    cash_account_id (TO) ← customer_account (CONTRA)
     *   - Receive: cash_account_id (FROM) ← customer_account (CONTRA)
     *
     * Handles all 4 transitions (X→Y where X and Y can be 0):
     *   X>0, Y>0: reverse old + create new
     *   X>0, Y=0: reverse old only
     *   X=0, Y>0: create new only
     *   X=0, Y=0: no-op
     *
     * (Note: TransactionService internally stores ALL double-entry
     * transactions as `type=transfer` — the income/expense semantic
     * lives in the from/to direction, NOT in the `type` column. So we
     * must filter by the account pair, not by `type`.)
     */
    protected function repostSettlementTransaction(WalletTransaction $transaction): void
    {
        if (! $transaction->customer_id) {
            return; // settlement only exists for customer-based transactions
        }

        $customerAccount = $this->ensureCustomerAccount((int) $transaction->customer_id);
        $type = $transaction->type instanceof WalletTransactionType
            ? $transaction->type
            : WalletTransactionType::from((string) $transaction->type);

        // For both Send and Receive, the settlement involves the cash
        // account and the customer account (or the destination override
        // account, when WLT-1 was used). The pair uniquely identifies
        // the settlement row (the main income/expense use wallet_account
        // or customer_account alone, never cash+customer together).
        //
        // BUG-FIX (audit 2026-08-14): use the LATEST settlement (order by
        // id desc) instead of the FIRST. Without this fix, every update
        // reverses the same oldest settlement row (F.1) while later
        // settlements accumulate unstacked — the customer balance then
        // drifts MORE negative than expected on every subsequent update
        // (e.g. paying 3k → 5k → 10k on a 10k debt left the customer at
        // −12.7k instead of 0). The mirror entries from previous
        // reversals are excluded by `notes NOT LIKE 'عكس%'` so the
        // chain of "reverse of reverse of reverse" does not pollute
        // the match.
        //
        // WLT-1 (2026-09-02): the settlement may have been posted with
        // either the customer_account or the receive_destination_account_id
        // as the contra side. We match BOTH pairs so the right settlement
        // is reversed regardless of which destination was used originally.
        $destinationOverride = (int) ($transaction->receive_destination_account_id ?? 0) ?: null;
        $settlementContraId = $destinationOverride ?: $customerAccount->id;

        $settlement = Transaction::where('related_type', WalletTransaction::class)
            ->where('related_id', $transaction->id)
            ->where(function ($q) use ($transaction, $customerAccount, $settlementContraId) {
                $q->where(function ($sub) use ($transaction, $customerAccount) {
                    // Legacy pair: cash_account ↔ customer_account
                    $sub->where('from_account_id', $transaction->cash_account_id)
                        ->where('to_account_id', $customerAccount->id);
                })->orWhere(function ($sub) use ($transaction, $customerAccount) {
                    $sub->where('from_account_id', $customerAccount->id)
                        ->where('to_account_id', $transaction->cash_account_id);
                })->orWhere(function ($sub) use ($transaction, $settlementContraId) {
                    // WLT-1 pair: cash_account ↔ destination override
                    $sub->where('from_account_id', $transaction->cash_account_id)
                        ->where('to_account_id', $settlementContraId);
                })->orWhere(function ($sub) use ($transaction, $settlementContraId) {
                    $sub->where('from_account_id', $settlementContraId)
                        ->where('to_account_id', $transaction->cash_account_id);
                });
            })
            ->whereNotIn('id', function ($sub) use ($transaction) {
                // Exclude mirror / reversal transactions stamped with
                // 'عكس' notes so we don't pick a reversed-then-reversed
                // row as if it were a fresh settlement.
                $sub->select('id')->from('transactions')
                    ->where('related_type', WalletTransaction::class)
                    ->where('related_id', $transaction->id)
                    ->where('notes', 'like', 'عكس%');
            })
            ->orderBy('id', 'desc')
            ->first();

        if ($settlement) {
            $this->transactionService->reverseTransaction($settlement);
        }

        $amountPaid = self::normalizeAmount($transaction->amount_paid);
        if ($amountPaid < 0.001) {
            return;
        }

        $walletTypeName = $transaction->walletType?->name ?? '';
        $customerName = $transaction->customer_name ?: '—';
        $createdBy = $transaction->created_by ?? Auth::id() ?? 1;

        // Re-emit the same settlement entry that the original
        // accountForSend / accountForReceive would have posted.
        if ($type === WalletTransactionType::Send) {
            $this->transactionService->recordJournalTransfer([
                'amount' => $amountPaid,
                'from_account_id' => $customerAccount->id,
                'to_account_id' => $transaction->cash_account_id,
                'module' => TransactionModule::Wallet->value,
                'related_type' => WalletTransaction::class,
                'related_id' => $transaction->id,
                'type' => TransactionType::Transfer->value,
                'notes' => "إعادة تسجيل دفعة نقدية مسددة من العميل بقيمة {$amountPaid} — {$walletTypeName} - {$customerName}",
                'created_by' => $createdBy,
                'currency' => $transaction->walletAccount?->currency,
            ]);
        } else {
            // Receive — WLT-1: contra side may be the destination override
            // or the legacy customer account.
            $contraNotes = $destinationOverride
                ? "إعادة تسجيل دفعة نقدية مسددة إلى حساب الاستقبال المختار بقيمة {$amountPaid} — {$walletTypeName} - {$customerName}"
                : "إعادة تسجيل دفعة نقدية مسددة للعميل بقيمة {$amountPaid} — {$walletTypeName} - {$customerName}";
            $this->transactionService->recordExpense([
                'amount' => $amountPaid,
                'from_account_id' => $transaction->cash_account_id,
                'contra_account_id' => $settlementContraId,
                'module' => TransactionModule::Wallet->value,
                'related_type' => WalletTransaction::class,
                'related_id' => $transaction->id,
                'notes' => $contraNotes,
                'created_by' => $createdBy,
            ]);
        }
    }

    /**
     * إرسال رصيد للعميل:
     *   مع تفعيل نظام الأجل:
     *   أ) في حال اختيار عميل مسجل:
     *      1. نسجل مديونية بقيمة total_amount كاملة على حساب العميل (Income للعميل).
     *      2. نسجل خصم الرصيد من المحفظة بقيمة amount (Expense للمحفظة).
     *      3. لو سدد العميل دفعة (amount_paid > 0)، نسجل تحصيل الدفعة للخزينة مع contra_account_id هو حساب العميل (سداد).
     *   ب) عميل غير مسجل:
     *      مباشرة استلام نقدي بالخزينة بقيمة total_amount كاملة، وخصم الرصيد من المحفظة بقيمة amount.
     */
    private function accountForSend(
        WalletTransaction $record,
        float $amount,
        float $fee,
        string $walletTypeName,
        string $customerName,
        int $createdBy
    ): array {
        [$income, $expense] = $this->postMainSendPair(
            $record, $amount, $fee, $walletTypeName, $customerName, $createdBy
        );

        $this->postSettlementSend(
            $record, (float) $record->amount_paid, $walletTypeName, $customerName, $createdBy
        );

        return [$income, $expense];
    }

    /**
     * Post only the main income + expense pair for a Send transaction.
     * Settlement is intentionally NOT posted here — that is handled by
     * postSettlementSend() so that repost flows can update the main pair
     * independently of the settlement (and vice-versa).
     */
    protected function postMainSendPair(
        WalletTransaction $record,
        float $amount,
        float $fee,
        string $walletTypeName,
        string $customerName,
        int $createdBy
    ): array {
        // FIX (2026-08-30) + WLT-FEE-LEG (2026-09-03):
        //
        // SEND must debit ONLY the wallet provider account by `amount`
        // (NOT amount+fee) — the wallet provider debits the sender's wallet
        // by the principal amount only. The fee is the agency's commission
        // and surfaces in cash / P&L according to whether the customer
        // is registered:
        //
        //   - registered customer: wallet → customer_account بـ amount فقط
        //     (مديونية على العميل). الـ fees بتدخل الخزنة في
        //     postSettlementSend() لما العميل يدفع الـ amount_paid (totalAmount)
        //     نقدياً — الخزنة بتزيد بـ 60 كاملة.
        //
        //   - anonymous walk-in:   wallet → cash_account بـ `amount` فقط
        //     (50) + income leg منفصل للرسوم (`fee`) على نفس الخزنة. ده
        //     بيعمل correct double-entry: cash +50 من transfer + cash +10
        //     من income = cash +60 إجمالي. الـ fees بتدخل الخزنة فوراً
        //     كعمولة للوكالة (revenue).
        //
        // الـ WT row بيحتفظ بـ service_fee / total_amount / amount_paid
        // كـ audit metadata، لكن الـ ledger legs دايماً بتتطابق مع الـ cash
        // flow الفعلي (قبل WLT-FEE-LEG: الـ fees كانت «phantom» — موجودة
        // في الـ WT بس مش بتتحرك في الحسابات، يعني كان في فرق بين
        // الـ treasury summary والـ WT summary).
        if ($record->customer_id) {
            $customerAccount = $this->ensureCustomerAccount((int) $record->customer_id);

            // Customer مسجّل: wallet → customerAccount بـ amount (المبلغ فقط).
            // الـ settlement بعدها يخصم amount_paid من العميل ويضيفه للخزنة.
            $transfer = $this->transactionService->recordJournalTransfer([
                'amount' => $amount,                                // المبلغ فقط — الرسوم على الـ settlement
                'from_account_id' => $record->wallet_account_id,    // المحفظة (يخصم منها)
                'to_account_id' => $customerAccount->id,            // حساب العميل (مديونية تزيد)
                'allow_from_negative' => true,                      // allow negative on prepaid wallets
                'module' => TransactionModule::Wallet->value,
                'related_type' => WalletTransaction::class,
                'related_id' => $record->id,
                'type' => TransactionType::Transfer->value,
                'notes' => "إرسال {$walletTypeName} - {$customerName}: خصم {$amount} من المحفظة، رسوم {$fee} على التسوية",
                'created_by' => $createdBy,
                'currency' => $record->walletAccount?->currency,
            ]);

            // Return the same transfer as both legs for backward compat with
            // the caller that stores $record->income_transaction_id /
            // $record->expense_transaction_id (both point at this transfer).
            return [$transfer, $transfer];
        }

        // Anonymous customer (نقدي فوري): المحفظة → الخزنة بـ المبلغ الأصلي فقط،
// والرسوم تتسجل كـ income leg منفصل على نفس الخزنة.
        //
        // WLT-FEE-LEG (2026-09-03) — إصلاح فقدان الرسوم في الـ ledger.
        // قبل الإصلاح: الـ cash كانت بتزيد بـ `amount` فقط (50). الـ 10 رسومات
        // كانت بتتسجل في الـ WT row كـ service_fee بس مفيش ledger leg ليها —
        // يعني بتضيع من دفاتر الوكالة (phantom — موجود في الـ WT بس مش في الحسابات).
        //
        // بعد الإصلاح: الحساب الفعلي يكون correct double-entry:
        //   1. main_transfer: wallet → cash بـ `amount` (50)
        //      - الـ wallet provider (vodafone) رصيدها ينقص 50 (اللي اتخصم من العميل)
        //      - الخزنة تزيد 50
        //   2. fee_income:   revenue → cash بـ `fee` (10)
        //      - الخزنة تزيد 10 (دخل الرسوم اللي العميل دفعها للوكالة)
        //
        // النتيجة الإجمالية: wallet -50، cash +60، revenue +10 (دخل الرسوم).
        // ده متسق مع registered customer path:
        //   - wallet -50 (main transfer لمديونية العميل)
        //   - cash +60 (settlement من العميل: الـ 50 للمدفوع + الـ 10 للرسوم)
        //
        // أمثلة على الأثر في الميزانية:
        //   - 50 + 10 رسوم: wallet ينقص 50، cash يزيد 60 (10 عمولة الوكالة).
        //   - 940 + 10 رسوم: wallet ينقص 940، cash يزيد 950 (10 عمولة الوكالة).
        $mainTransfer = $this->transactionService->recordJournalTransfer([
            'amount' => $amount,                              // المبلغ فقط — الـ wallet ما بتتخصمش بالرسوم
            'from_account_id' => $record->wallet_account_id,  // المحفظة (vodafone provider balance)
            'to_account_id' => $record->cash_account_id,      // الخزنة
            'allow_from_negative' => true,
            'module' => TransactionModule::Wallet->value,
            'related_type' => WalletTransaction::class,
            'related_id' => $record->id,
            'type' => TransactionType::Transfer->value,
            'notes' => "إرسال {$walletTypeName} - {$customerName}: خصم {$amount} من المحفظة للخزنة (الرسوم على الـ leg الثاني)",
            'created_by' => $createdBy,
            'currency' => $record->walletAccount?->currency,
        ]);

        // الـ fee income: سجّل رسوم الخدمة كدخل للوكالة على نفس الخزنة.
        // ده بيخلي cash +fee (10) و revenue -fee — الـ revenue هو agency earnings
        // من رسوم خدمات المحفظة. Double-entry صحيح.
        $feeIncome = null;
        if ($fee >= 0.005) {
            $feeIncome = $this->transactionService->recordIncome([
                'amount' => $fee,
                'to_account_id' => $record->cash_account_id,
                'module' => TransactionModule::Wallet->value,
                'related_type' => WalletTransaction::class,
                'related_id' => $record->id,
                'notes' => "إرسال {$walletTypeName} - {$customerName}: رسوم خدمة {$fee} (عمولة الوكالة)",
                'created_by' => $createdBy,
            ]);
        }

        // الـ returned tuple بيمشي مع signed contract بتاع الـ caller:
        //   - first  = income_transaction_id (الـ feeIncome لو فيه fees، وإلا الـ mainTransfer)
        //   - second = expense_transaction_id (الـ mainTransfer — wallet debit)
        return [$feeIncome ?? $mainTransfer, $mainTransfer];
    }

    /**
     * Post only the optional settlement transaction for a Send with a
     * registered customer when amount_paid > 0. Idempotent — if amount_paid
     * is 0 or the customer has no registered account, this is a no-op.
     *
     * FINDING FIN-2 (HIGH) REMEDIATED (2026-08-21):
     * Pre-fix: this method called `recordIncome(...)` with the SAME
     * `(related_type, related_id)` as the main Send pair. The duplicate
     * guard in `TransactionService::recordJournalTransfer` (lines 650-674)
     * rejected the second call with "Duplicate income transaction blocked".
     *
     * The guard itself documents the intended pattern:
     *   "Subsequent COLLECTIONS on a booking must use Transfer (type=transfer)."
     *
     * Post-fix: this method now calls `recordTransfer(...)` instead. The
     * settlement becomes a cashbox→wallet-account replenishment, the
     * proper double-entry for "cashier collected cash from the customer
     * and put it into the wallet vault". This is the transfer, NOT an
     * income — and it does not collide with the main Send income slot.
     */
    protected function postSettlementSend(
        WalletTransaction $record,
        float $amountPaid,
        string $walletTypeName,
        string $customerName,
        int $createdBy
    ): void {
        if (! $record->customer_id) {
            return;
        }

        if ($amountPaid < 0.001) {
            return;
        }

        $customerAccount = $this->ensureCustomerAccount((int) $record->customer_id);

        // Settlement is a TRANSFER from the customer account to the cash account,
        // reducing customer debt and increasing cashbox balance.
        // D-V2-009: normalize the settlement amount so WT.amount_paid and the
        // settlement ledger leg carry the SAME canonical 2-decimal value.
        $amountPaid = self::normalizeAmount($amountPaid);
        $this->transactionService->recordJournalTransfer([
            'amount' => $amountPaid,
            'from_account_id' => $customerAccount->id,
            'to_account_id' => $record->cash_account_id,
            'module' => TransactionModule::Wallet->value,
            'related_type' => WalletTransaction::class,
            'related_id' => $record->id,
            'type' => TransactionType::Transfer->value,
            'notes' => "إرسال {$walletTypeName} - {$customerName}: دفعة نقدية مسددة من العميل بقيمة {$amountPaid}",
            'created_by' => $createdBy,
            'currency' => $record->walletAccount?->currency,
        ]);
    }

    /**
     * استقبال رصيد من العميل:
     *   أ) في حال اختيار عميل مسجل:
     *      1. نسجل زيادة الرصيد بمحفظتنا بقيمة amount (Income للمحفظة).
     *      2. نسجل استحقاق العميل بقيمة total_amount كاملة (صافي الاستقبال) على حساب العميل (Expense للعميل / دائن).
     *      3. لو دفعنا للعميل جزء نقدياً (amount_paid > 0)، نسجل خروج المبلغ من الخزينة مع contra_account_id هو حساب العميل (خصم).
     *   ب) عميل غير مسجل:
     *      مباشرة زيادة الرصيد بالمحفظة بقيمة amount، وصرف المبلغ نقدي للعميل بقيمة total_amount من الخزينة.
     */
    private function accountForReceive(
        WalletTransaction $record,
        float $amount,
        float $fee,
        string $walletTypeName,
        string $customerName,
        int $createdBy
    ): array {
        [$income, $expense] = $this->postMainReceivePair(
            $record, $amount, $fee, $walletTypeName, $customerName, $createdBy
        );

        $this->postSettlementReceive(
            $record, (float) $record->amount_paid, $walletTypeName, $customerName, $createdBy
        );

        return [$income, $expense];
    }

    /**
     * Post only the main income + expense pair for a Receive transaction.
     * Settlement is intentionally NOT posted here — handled by
     * postSettlementReceive() so the repost flow can update main pair and
     * settlement independently.
     */
    protected function postMainReceivePair(
        WalletTransaction $record,
        float $amount,
        float $fee,
        string $walletTypeName,
        string $customerName,
        int $createdBy
    ): array {
        $totalAmount = $amount - $fee;

        // WLT-1 (2026-09-02) — Receive destination override.
        //
        // Pre-fix, the destination of the Expense leg was hard-coded:
        //   - registered customer → customerAccount (the customer debt/AP)
        //   - anonymous customer  → cash_account_id (the cashbox)
        //
        // The user requested the ability to receive INTO any account type
        // they choose (e.g. a bank account, another wallet provider, a
        // card-clearing account). The optional `receive_destination_account_id`
        // column on the WT row records the override. When present, the
        // Expense leg is routed there instead of the legacy default.
        // When NULL, the legacy default applies unchanged — fully backward
        // compatible with existing rows and API clients.
        //
        // The chosen destination is also stored in the WT row so the
        // settlement path (postSettlementReceive) can route cash
        // collections to the same destination when amount_paid > 0.
        $destinationOverride = (int) ($record->receive_destination_account_id ?? 0) ?: null;

        // Income leg is ALWAYS into the wallet provider account — the
        // wallet provider receives `amount` regardless of where the cash
        // physically lands.
        $income = $this->transactionService->recordIncome([
            'amount' => $amount,
            'to_account_id' => $record->wallet_account_id,
            'module' => TransactionModule::Wallet->value,
            'related_type' => WalletTransaction::class,
            'related_id' => $record->id,
            'notes' => "استقبال {$walletTypeName} - {$customerName}: استلام رصيد بقيمة {$amount} في المحفظة",
            'created_by' => $createdBy,
        ]);

        // Resolve the destination for the Expense leg. Priority:
        //   1. explicit override (any active account the user picked)
        //   2. registered customer's account (legacy default)
        //   3. cash_account_id for anonymous (legacy default)
        if ($destinationOverride) {
            $expenseFromAccountId = $destinationOverride;
            $destinationLabel = 'حساب الاستقبال المختار';
            $expenseNotes = "استقبال {$walletTypeName} - {$customerName}: تحويل {$totalAmount} إلى الحساب المختار (صافي بعد رسوم {$fee})";
        } elseif ($record->customer_id) {
            $customerAccount = $this->ensureCustomerAccount((int) $record->customer_id);
            $expenseFromAccountId = $customerAccount->id;
            $destinationLabel = 'حساب العميل';
            $expenseNotes = "استقبال {$walletTypeName} - {$customerName}: مستحق للعميل بقيمة {$totalAmount} (صافي بعد رسوم {$fee})";
        } else {
            // Anonymous walk-in — default to the cashbox.
            $expenseFromAccountId = (int) $record->cash_account_id;
            $destinationLabel = 'الخزينة';
            $expenseNotes = "استقبال {$walletTypeName} - {$customerName}: دفع نقدي {$totalAmount}";
        }

        $expense = $this->transactionService->recordExpense([
            'amount' => $totalAmount,
            'from_account_id' => $expenseFromAccountId,
            'module' => TransactionModule::Wallet->value,
            'related_type' => WalletTransaction::class,
            'related_id' => $record->id,
            'notes' => $expenseNotes,
            'created_by' => $createdBy,
        ]);

        return [$income, $expense];
    }

    /**
     * Post only the optional settlement transaction for a Receive with a
     * registered customer when amount_paid > 0. Idempotent — if amount_paid
     * is 0 or the customer has no registered account, this is a no-op.
     */
    protected function postSettlementReceive(
        WalletTransaction $record,
        float $amountPaid,
        string $walletTypeName,
        string $customerName,
        int $createdBy
    ): void {
        if (! $record->customer_id) {
            return;
        }

        if ($amountPaid < 0.001) {
            return;
        }

        $customerAccount = $this->ensureCustomerAccount((int) $record->customer_id);

        // WLT-1 (2026-09-02): the settlement leg mirrors the destination
        // choice of the main receive pair. If the user picked an override
        // account, the cash settlement flows from the cashbox INTO that
        // override account (the cashier is paying out to wherever the
        // customer/agency chose). If the user did NOT pick an override,
        // the cash settlement flows from cashbox to the customer account
        // (legacy behavior — the cashier is paying the customer back).
        $destinationOverride = (int) ($record->receive_destination_account_id ?? 0) ?: null;
        $contraAccountId = $destinationOverride ?: $customerAccount->id;
        $settlementNotes = $destinationOverride
            ? "استقبال {$walletTypeName} - {$customerName}: دفعة نقدية مسددة إلى حساب الاستقبال المختار بقيمة {$amountPaid}"
            : "استقبال {$walletTypeName} - {$customerName}: دفعة نقدية مسددة للعميل بقيمة {$amountPaid}";

        $this->transactionService->recordExpense([
            'amount' => $amountPaid,
            'from_account_id' => $record->cash_account_id,
            'contra_account_id' => $contraAccountId,
            'module' => TransactionModule::Wallet->value,
            'related_type' => WalletTransaction::class,
            'related_id' => $record->id,
            'notes' => $settlementNotes,
            'created_by' => $createdBy,
        ]);
    }

    public function deleteTransaction(WalletTransaction $transaction): bool
    {
        try {
            return DB::transaction(function () use ($transaction) {
                // 🛡️ Production-safety guard (business rule):
                // Refuse to delete if any debt payment was recorded after
                // the wallet operation was created. Runs BEFORE reversal /
                // soft-delete so a blocked attempt leaves books & balances
                // untouched. Same shared service used by Fawry / Online.
                $customerAccountId = null;
                if (! empty($transaction->customer_id)) {
                    $customerAccountId = (int) (Customer::query()
                        ->where('id', (int) $transaction->customer_id)
                        ->value('account_id') ?? 0) ?: null;
                }
                $settlementAccountId = (int) ($transaction->cash_account_id ?: $transaction->wallet_account_id);
                $originalSettlement = $this->deletionGuard->computeOriginalSettlement(
                    WalletTransaction::class,
                    (int) $transaction->id,
                    $settlementAccountId,
                );
                // BUG-FIX (audit 2026-08-14): for walk-in operations
                // (customer_id IS NULL), pass null for $currentPaidAmount
                // so the guard's Check 1 is skipped. For walk-in there is
                // no customer account to "later pay", and amount_paid is
                // set at creation time reflecting the cash flow — it is
                // NOT a signal of a subsequent debt payment. Failing
                // Check 1 here was a false positive that blocked every
                // legitimate walk-in deletion.
                $currentPaidAmount = $customerAccountId !== null
                    ? (float) ($transaction->amount_paid ?? 0.0)
                    : null;

                $this->deletionGuard->ensureNoLaterPayment(
                    $transaction->created_at,
                    $currentPaidAmount,
                    $originalSettlement,
                    $customerAccountId,
                    WalletTransaction::class,
                    (int) $transaction->id,
                );

                // عكس كل القيود المحاسبية التابعة لهذه العملية بما فيها السداد/الصرف التابع
                $relatedTransactions = Transaction::where('related_type', WalletTransaction::class)
                    ->where('related_id', $transaction->id)
                    ->get();

                foreach ($relatedTransactions as $rt) {
                    $this->transactionService->reverseTransaction($rt);
                }

                $transaction->delete();

                Log::info('WalletTransaction deleted and ledger reversed', [
                    'id' => $transaction->id,
                    'deleted_by' => Auth::id(),
                ]);

                // ── Audit log — نسجل الحذف في audit_logs (قبل الـ soft-delete
                //    عشان نقدر نقرأ الحقول لـ old_values) ──
                $type = $transaction->type instanceof WalletTransactionType
                    ? $transaction->type
                    : WalletTransactionType::from((string) $transaction->type);
                $this->writeAuditLog(
                    action: 'wallet_transaction.deleted',
                    record: $transaction,
                    type: $type,
                    oldValues: $this->snapshotForAudit($transaction),
                );

                return true;
            });
        } catch (\Exception $e) {
            Log::error('WalletTransactionService::deleteTransaction failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'id' => $transaction->id,
            ]);
            throw $e;
        }
    }

    protected function ensureCustomerAccount(int $customerId): Account
    {
        // FINDING CONC-2 (MED) REMEDIATION (2026-08-21):
        // Two concurrent first-time sends for the same customer could each
        // see `customer->account_id === NULL`, both call `Account::create()`,
        // and both write to `$customer->account_id` — leaving the Customer
        // pointing to one Account and the orphan Account dangling.
        //
        // The fix: acquire a row-level lock on the Customer row at the
        // START of ensureCustomerAccount, re-read `account_id` under the
        // lock, and only create a new Account if the second reader still
        // sees NULL. The Customer row is the canonical serialization
        // point because it is the cross-process mutual exclusion primitive.
        //
        // The check + create is wrapped in DB::transaction so that the
        // lock is held for the duration of the create+update pair.
        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($customerId) {
            /** @var Customer $customer */
            $customer = Customer::query()
                ->where('id', $customerId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($customer->account_id) {
                $account = Account::find($customer->account_id);
                if ($account) {
                    // Phase 8 fix: CustomerLedgerObserver creates a generic
                    // 'office'-tagged account the moment a Customer row is
                    // inserted. When that customer is later used by a wallet
                    // transaction, we re-tag the account to 'wallet_transfer'
                    // so it surfaces in the TransferDashboardController stats
                    // and TransferAccounts/* resources (which filter strictly
                    // by module_type='wallet_transfer'). The re-tag is wrapped
                    // in LedgerBalanceMutationGuard because touching `balance`
                    // — even to confirm 0.00 — would otherwise trip the
                    // `Account::updating` boot guard.
                    if ($account->module_type !== 'wallet_transfer') {
                        LedgerBalanceMutationGuard::run(function () use ($account) {
                            $account->module_type = 'wallet_transfer';
                            $account->save();
                        });
                    }

                    return $account;
                }
            }

            $account = Account::create([
                'name' => 'حساب العميل: '.$customer->full_name,
                'type' => AccountType::Customer,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'wallet_transfer',
                'is_module_vault' => false,
                'notes' => 'حساب تلقائي للعميل #'.$customer->id,
                'created_by' => Auth::id() ?? 1,
            ]);

            $customer->update(['account_id' => $account->id]);

            return $account;
        }));
    }

    public function getTransactionById(int $id): WalletTransaction
    {
        return WalletTransaction::with([
            'walletType', 'customer', 'walletAccount', 'cashAccount',
            'employee', 'createdBy', 'incomeTransaction', 'expenseTransaction',
        ])->findOrFail($id);
    }

    /**
     * Test-only accessor for the protected ensureCustomerAccount method.
     * The CONC-2 regression test in Phase12ConcurrencyTest calls this twice
     * to verify the lockForUpdate serialization holds.
     */
    public function ensureCustomerAccountForTest(int $customerId): Account
    {
        return $this->ensureCustomerAccount($customerId);
    }

    public function getDailySummary(string $date): array
    {
        // FINDING FIN-4 (MED) REMEDIATION (2026-08-21):
        // The previous implementation summed `amount` (the principal only)
        // and reported it as `total_sent`/`total_received`, which silently
        // undercounted cash moved through the wallet because the
        // `service_fee` was excluded. For reconciliation purposes, the
        // cash that physically LEAVES the cashbox for a Send is
        // `total_amount` = `amount` + `service_fee`. The accounting
        // ledger was already correct (each TX has 3 paired entries);
        // only this summary aggregator was wrong.
        //
        // FIX: add `total_sent_with_fees` and `total_received_with_fees`
        // using `total_amount`. Keep `total_sent`/`total_received`
        // (using `amount`) for backward compatibility — older clients
        // (Filament dashboard widgets, exports) still read those.
        $result = WalletTransaction::whereDate('created_at', $date)
            ->selectRaw('
                COUNT(*)                                       as total_transactions,
                SUM(CASE WHEN type = "send"    THEN 1 ELSE 0 END) as send_count,
                SUM(CASE WHEN type = "receive" THEN 1 ELSE 0 END) as receive_count,
                SUM(CASE WHEN type = "send"    THEN amount ELSE 0 END)        as total_sent,
                SUM(CASE WHEN type = "receive" THEN amount ELSE 0 END)        as total_received,
                SUM(CASE WHEN type = "send"    THEN total_amount ELSE 0 END) as total_sent_with_fees,
                SUM(CASE WHEN type = "receive" THEN total_amount ELSE 0 END) as total_received_with_fees,
                SUM(service_fee) as total_fees
            ')
            ->first();

        return [
            'total_transactions' => (int) ($result->total_transactions ?? 0),
            'send_count' => (int) ($result->send_count ?? 0),
            'receive_count' => (int) ($result->receive_count ?? 0),
            'total_sent' => (float) ($result->total_sent ?? 0),
            'total_received' => (float) ($result->total_received ?? 0),
            'total_sent_with_fees' => (float) ($result->total_sent_with_fees ?? 0),
            'total_received_with_fees' => (float) ($result->total_received_with_fees ?? 0),
            'total_fees' => (float) ($result->total_fees ?? 0),
        ];
    }

    /**
     * كتابة سجل تدقيق (AuditLog) للعملية.
     *
     * يُسجَّل:
     *  - نوع العملية (created / updated / deleted)
     *  - نوع المحفظة المستخدم (إرسال/استقبال)
     *  - النطاق (scope) = 'official_module' (محفظة wallet_transfer رسمية) أو
     *                       'office_department' (محفظة قسم مكتب عامة)
     *  - القيم الجديدة بعد العملية
     *  - القيم القديمة (لو تعديل أو حذف) قبل العملية
     *
     * ضمانات الأمان:
     *  - الـ AuditLog::create() محاط بـ try/catch — لو فشل التسجيل ما نكسرش العملية.
     *  - صفر تأثير على المنطق المحاسبي أو على أي field آخر في الـ wallet_transactions.
     *  - صفر تأثير على الـ return value للـ create/update/delete.
     *
     * @param  string  $action  مثل: 'wallet_transaction.created'
     * @param  WalletTransaction  $record  الـ record بعد/قبل العملية
     * @param  array<string, mixed>|null  $oldValues
     */
    protected function writeAuditLog(
        string $action,
        WalletTransaction $record,
        WalletTransactionType $type,
        ?array $oldValues = null,
    ): void {
        try {
            $walletAccount = $record->wallet_account_id
                ? Account::find($record->wallet_account_id)
                : null;

            // تحديد النطاق: رسمي موديول wallet_transfer vs قسم مكتب عام office
            $scope = ($walletAccount
                && (
                    $walletAccount->module === 'wallet_transfer'
                    || $walletAccount->module_type === 'wallet_transfer'
                )
            ) ? 'official_module' : 'office_department';

            $scopeLabel = $scope === 'official_module'
                ? 'رسمية للموديول'
                : 'قسم المكتب';

            $newValues = [
                'type' => $type->value,
                'wallet_account_id' => $record->wallet_account_id,
                'wallet_account_scope' => $scope,           // ⭐ المفتاح الأساسي
                'wallet_account_name' => $walletAccount?->name,
                'wallet_account_module_type' => $walletAccount?->module_type,
                'cash_account_id' => $record->cash_account_id,
                'amount' => (float) $record->amount,
                'service_fee' => (float) $record->service_fee,
                'total_amount' => (float) $record->total_amount,
                'amount_paid' => (float) $record->amount_paid,
                'customer_id' => $record->customer_id,
                'customer_name' => $record->customer_name,
                'wallet_number' => $record->wallet_number,
                'wallet_type_id' => $record->wallet_type_id,
            ];

            AuditLog::create([
                'user_id' => Auth::id() ?? $record->created_by ?? 1,
                'action' => $action,
                // Legacy polymorphic convention (kept for backward compatibility —
                // every existing audit consumer reads `model_type`/`model_id`).
                'model_type' => WalletTransaction::class,
                'model_id' => $record->id,
                // FINDING FIN-5 (MED) REMEDIATION (2026-08-21):
                // Also write the modern `related_type`/`related_id` pair so
                // cross-table audit queries ("all audit rows for booking X",
                // "all audit rows for transaction Y") can use a single
                // convention. Columns added by migration
                // `2026_08_19_120000_add_related_columns_to_audit_logs_table`.
                'related_type' => WalletTransaction::class,
                'related_id' => $record->id,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'notes' => sprintf(
                    'عملية محفظة (%s) باستخدام محفظة %s: %s — مبلغ %s + رسوم %s — عميل: %s',
                    $type->value,
                    $scopeLabel,
                    $walletAccount?->name ?? '—',
                    number_format((float) $record->amount, 2),
                    number_format((float) $record->service_fee, 2),
                    $record->customer_name ?: 'بدون اسم',
                ),
            ]);
        } catch (\Throwable $e) {
            // ⚠️ فشل الـ audit log لا يكسر العملية أبداً
            Log::warning('WalletTransaction audit log failed', [
                'action' => $action,
                'record_id' => $record->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
