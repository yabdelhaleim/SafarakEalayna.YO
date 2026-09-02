<?php

namespace App\Services\Online;

use App\Enums\AccountType;
use App\Enums\CustomerType;
use App\Enums\OnlineTransactionStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Online\OnlineServiceType;
use App\Models\Online\OnlineTransaction;
use App\Models\Transaction;
use App\Services\Finance\DeferredTransactionDeletionGuard;
use App\Services\Finance\LedgerClearingAccounts;
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
        ?DeferredTransactionDeletionGuard $deletionGuard = null,
    ) {
        $this->deletionGuard = $deletionGuard
            ?? app(DeferredTransactionDeletionGuard::class);
    }

    public function getAll(array $filters): LengthAwarePaginator
    {
        // 🛡️ Phase 10: support `with_trashed` so the audit / trash views can
        // see soft-deleted (cancelled) rows. Default behaviour is unchanged:
        // hide cancelled rows from the default listing.
        $query = OnlineTransaction::query();
        if (! empty($filters['with_trashed'])) {
            $query->withTrashed();
        }

        $query->with([
            'serviceTypeRow',
            'providerRow',
            'customer',
            'employee.user',
            'account',
            'paymentMethodRow',
            'createdBy',
        ]);

        if (! empty($filters['service_type_code'])) {
            $query->where('service_type_code', $filters['service_type_code']);
        }
        if (! empty($filters['provider_code'])) {
            $query->where('provider_code', $filters['provider_code']);
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
            'serviceTypeRow',
            'providerRow',
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
        // ─────────────────────────────────────────────────────────────────
        // SEC-4 REMEDIATION (2026-08-21) — replay protection for Online
        // transactions. Mirrors the established Hajj/Umra, Flight, Visa,
        // Wallet, and Bus idempotency pattern.
        //
        //   Identity:    (created_by, idempotency_key)
        //   Stored on:   online_transactions.idempotency_key  (nullable, 100 chars)
        //   Enforced:    UNIQUE index `ot_idem_uniq` (migration 2026_08_21_010000)
        //
        //   Layered protection:
        //     1. Pre-check inside DB::transaction: SELECT existing OnlineTransaction
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
        //        pre-check filters them out so a fresh INSERT is allowed
        //        (after the soft-deleted row's key is cleared).
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
                // immediately. No new OnlineTransaction, no new ledger
                // entries, no new audit log. The transient `idempotent_replay`
                // flag is read by the controller to return HTTP 200.
                if ($idempotencyKey !== null) {
                    $existing = OnlineTransaction::query()
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
                    OnlineTransaction::withTrashed()
                        ->where('created_by', $createdByForIdem)
                        ->where('idempotency_key', $idempotencyKey)
                        ->whereNotNull('deleted_at')
                        ->update(['idempotency_key' => null]);
                }

                $serviceTypeCode = trim((string) ($data['service_type_code'] ?? ''));
                if ($serviceTypeCode === '') {
                    throw new \RuntimeException('نوع الخدمة مطلوب.');
                }

                // Service Type and Provider are now free-text codes.
                // The online_service_types / online_service_providers
                // master tables are kept only as *optional* lookups —
                // the existence check is intentionally skipped.

                $providerCode = ! empty($data['provider_code'])
                    ? trim((string) $data['provider_code'])
                    : null;

                // If no customer_id was passed but a name was typed, try to
                // attach to an existing customer or auto-create a new one
                // scoped to the Online module. This guarantees every Online
                // transaction is linked to a Customer record so debt can be
                // tracked and the customer surfaces in the module's list.
                $data = $this->ensureCustomerIsLinked($data);

                [$customerName, $customerPhone] = $this->resolveCustomerNameAndPhone($data);

                // 🛡️ Phase 10: cross-currency guard. The Online module is
                // intentionally EGP-only (per the project owner) — we must
                // reject any booking whose vault currency differs from the
                // AR currency, otherwise `recordJournalTransfer` would
                // silently FX-convert and corrupt the AR balance. We don't
                // touch TransactionService for this — the rejection happens
                // before we ever call it.
                $this->assertCurrencyCompatible($data);

                $purchase = (float) $data['purchase_price'];
                $selling = (float) $data['selling_price'];
                $amountPaid = isset($data['amount_paid']) ? (float) $data['amount_paid'] : $selling;
                $profit = $selling - $purchase;

                $status = OnlineTransactionStatus::tryFrom($data['status'] ?? OnlineTransactionStatus::Completed->value)
                    ?? OnlineTransactionStatus::Completed;

                // Layer 2 — INSERT. The DB UNIQUE constraint is the final
                // backstop. If two concurrent calls bypassed the pre-check
                // (e.g. lock acquisition failed), the second INSERT will
                // fail with SQLSTATE 23000 / MySQL code 1062. The catch
                // block below converts that to an idempotent return.
                try {
                    // Wrap the create in runProfitMutation() so the saving observer
                    // guard lets the explicit `profit` write through. Mirrors the
                    // BusBookingService / FawryTransactionService pattern.
                    $tx = OnlineTransaction::runProfitMutation(function () use ($data, $serviceTypeCode, $providerCode, $customerName, $customerPhone, $purchase, $selling, $amountPaid, $profit, $status, $idempotencyKey, $createdByForIdem) {
                        return OnlineTransaction::create([
                            'service_type_code' => $serviceTypeCode,
                            'provider_code' => $providerCode,
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
                            'idempotency_key' => $idempotencyKey,
                            'status' => $status->value,
                            'failure_reason' => $data['failure_reason'] ?? null,
                            'notes' => $data['notes'] ?? null,
                            'created_by' => $createdByForIdem,
                        ]);
                    });
                } catch (\Illuminate\Database\QueryException $qe) {
                    // Layer 2 catch — DB UNIQUE backstop.
                    if ($this->isDuplicateKeyError($qe) && $idempotencyKey !== null) {
                        // The pre-check passed but the INSERT still tripped
                        // the UNIQUE. Another call must have created the row
                        // between SELECT and INSERT. Re-query and return the
                        // now-existing row as idempotent_replay.
                        $existing = OnlineTransaction::query()
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

                if ($status === OnlineTransactionStatus::Completed) {
                    $serviceTypeLookup = OnlineServiceType::where('code', $serviceTypeCode)->first();
                    $providerLookup = $providerCode
                        ? OnlineServiceProvider::where('code', $providerCode)->first()
                        : null;
                    $this->postFinancialEntries($tx, $serviceTypeLookup, $providerLookup, $purchase, $selling, $customerName);
                }

                Log::info('Online transaction created', [
                    'online_transaction_id' => $tx->id,
                    'service_type_code' => $serviceTypeCode,
                    'provider_code' => $providerCode,
                    'purchase' => $purchase,
                    'selling' => $selling,
                    'profit' => $profit,
                    'created_by' => $createdByForIdem,
                    'idempotency_key' => $idempotencyKey,
                    'idempotent_replay' => false,
                ]);

                return $tx->fresh([
                    'serviceTypeRow',
                    'providerRow',
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

    /**
     * Identify a "duplicate entry on unique index" QueryException.
     * MySQL: SQLSTATE 23000, error code 1062.
     * SQLite: SQLSTATE 23000 (canonical SQL state).
     * PostgreSQL: SQLSTATE 23000.
     *
     * Mirrors the same helper in WalletTransactionService,
     * HajjUmraBookingService, VisaBookingService, and FlightBookingService
     * to keep the project-wide convention.
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

    public function update(OnlineTransaction $tx, array $data): OnlineTransaction
    {
        try {
            return DB::transaction(function () use ($tx, $data) {
                // Capture pre-update values for the account/customer so we can
                // pass them to repostCashPaymentTransaction(). After $tx->fill()
                // runs, getOriginal() will return the pre-update values anyway,
                // but capturing them here is cleaner and avoids subtle bugs.
                $originalAccountId = (int) $tx->account_id;
                $originalCustomerId = (int) ($tx->customer_id ?? 0);
                $originalStatus = $tx->status;

                if (array_key_exists('customer_id', $data) || array_key_exists('customer_name', $data) || array_key_exists('customer_phone', $data)) {
                    [$customerName, $customerPhone] = $this->resolveCustomerNameAndPhone(array_merge(
                        $tx->only(['customer_id', 'customer_name', 'customer_phone']),
                        $data,
                    ));
                    $data['customer_name'] = $customerName;
                    $data['customer_phone'] = $customerPhone;
                }

                // Detect ACTUAL changes (vs same value) — used to gate the
                // ledger repost so we don't waste DB writes on no-op edits.
                $sellingChanged = array_key_exists('selling_price', $data)
                    && (float) $data['selling_price'] !== (float) $tx->selling_price;
                $purchaseChanged = array_key_exists('purchase_price', $data)
                    && (float) $data['purchase_price'] !== (float) $tx->purchase_price;
                $amountPaidChanged = array_key_exists('amount_paid', $data)
                    && (float) $data['amount_paid'] !== (float) $tx->amount_paid;
                $accountChanged = array_key_exists('account_id', $data)
                    && (int) $data['account_id'] !== $originalAccountId;
                $customerChanged = array_key_exists('customer_id', $data)
                    && (int) $data['customer_id'] !== $originalCustomerId;

                // 🛡️ Phase 10: cross-currency guard (mirror of `create()`).
                // The Online module is EGP-only, so an account/customer swap
                // must not silently route the booking through a foreign
                // currency. Validate before any ledger repost fires.
                if ($accountChanged || $customerChanged) {
                    $this->assertCurrencyCompatible(array_merge(
                        $tx->only(['account_id', 'customer_id']),
                        $data,
                    ));
                }

                // 🛡️ Phase 10: status transition detection. A status change
                // (Completed ↔ Cancelled ↔ Pending ↔ Failed) is the heaviest
                // possible edit — it changes which side of the GL the row
                // lives on. We handle it explicitly so PATCH /transactions/{id}
                // can cancel OR re-open a transaction without going through
                // DELETE.
                $statusChanged = array_key_exists('status', $data)
                    && $data['status'] !== $originalStatus->value;
                $newStatus = $statusChanged
                    ? OnlineTransactionStatus::tryFrom($data['status']) ?? $tx->status
                    : $tx->status;

                if ($sellingChanged || $purchaseChanged) {
                    $purchase = (float) ($data['purchase_price'] ?? $tx->purchase_price);
                    $selling = (float) ($data['selling_price'] ?? $tx->selling_price);
                    $data['profit'] = $selling - $purchase;
                }

                // Wrap the fill+save in runProfitMutation() so the saving
                // observer guard lets the auto-computed `profit` write through.
                // Mirrors FawryTransactionService::updateTransaction pattern.
                OnlineTransaction::runProfitMutation(function () use ($tx, $data) {
                    $tx->fill($data)->save();
                });

                // 🛡️ ACCOUNTING INTEGRITY (Phase 9 + Phase 10):
                //
                // Two cases to consider:
                //   A. The status is now Completed. We may need to:
                //      - post fresh entries (Pending/Failed → Completed), OR
                //      - repost on price/vault changes (Completed → Completed).
                //   B. The status moved AWAY from Completed. We must reverse
                //      every GL entry that was posted (Completed → anything).
                //
                // The previous gate was `if ($tx->status === Completed)` which
                // only handled case A and missed case B entirely (PATCH'ing
                // status=Completed→Cancelled would silently leave the GL in
                // place). Restructured below so both cases are covered
                // independently.

                // ── A. Status transition Completed → something else:
                //      reverse everything that was ever posted against this
                //      row. This is the inverse of step 1 in `delete()` —
                //      but without the soft-delete, so the user can later
                //      PATCH status back to Completed to re-post.
                if ($statusChanged && $originalStatus === OnlineTransactionStatus::Completed) {
                    $linkedForReverse = Transaction::where('related_type', OnlineTransaction::class)
                        ->where('related_id', $tx->id)
                        ->orderByDesc('id')
                        ->get();
                    foreach ($linkedForReverse as $rt) {
                        $this->transactionService->reverseTransaction($rt);
                    }
                }

                // ── B. Status transition something → Completed:
                //      if no live (non-reversal) GL entries are linked,
                //      post fresh. (Pending/Failed posts nothing at create,
                //      so flipping to Completed must do the post now.)
                if ($statusChanged && $newStatus === OnlineTransactionStatus::Completed
                    && $originalStatus !== OnlineTransactionStatus::Completed) {
                    $hasLiveLinked = Transaction::where('related_type', OnlineTransaction::class)
                        ->where('related_id', $tx->id)
                        ->whereDoesntHave('entries', function ($q) {
                            $q->where('notes', 'like', 'عكس%');
                        })
                        ->exists();
                    if (! $hasLiveLinked) {
                        $provider = $tx->provider_code
                            ? OnlineServiceProvider::where('code', $tx->provider_code)->first()
                            : null;
                        $this->postFinancialEntries(
                            $tx,
                            $tx->serviceTypeRow,
                            $provider,
                            (float) $tx->purchase_price,
                            (float) $tx->selling_price,
                            (string) ($tx->customer_name ?? ''),
                        );
                    }
                }

                // ── C. Field-change repost (only meaningful when the new
                //      status is Completed — the old status might have
                //      been Completed too, in which case step A above
                //      already reversed; or it was something else, in
                //      which case step B already posted fresh; in both
                //      cases we now need to repost on field changes
                //      because the OLD entries that the user just made
                //      invalid through PATCH are still live).
                if ($tx->status === OnlineTransactionStatus::Completed) {
                    if ($sellingChanged || $accountChanged || $customerChanged) {
                        $newSelling = (float) ($data['selling_price'] ?? $tx->selling_price);
                        $newIncome = $this->repostIncomeTransaction($tx, $newSelling);
                        if ($newIncome) {
                            OnlineTransaction::runProfitMutation(function () use ($tx, $newIncome) {
                                $tx->income_transaction_id = $newIncome->id;
                                $tx->save();
                            });
                        }
                    }

                    if ($purchaseChanged) {
                        $newPurchase = (float) ($data['purchase_price'] ?? $tx->purchase_price);
                        $newExpense = $this->repostExpenseTransaction($tx, $newPurchase);
                        if ($newExpense) {
                            OnlineTransaction::runProfitMutation(function () use ($tx, $newExpense) {
                                $tx->expense_transaction_id = $newExpense->id;
                                $tx->save();
                            });
                        }
                    }

                    if ($amountPaidChanged || $accountChanged || $customerChanged) {
                        $newAmountPaid = (float) ($data['amount_paid'] ?? $tx->amount_paid);
                        $this->repostCashPaymentTransaction(
                            $tx,
                            $newAmountPaid,
                            $originalAccountId,
                            $originalCustomerId ?: null,
                        );
                    }
                }

                Log::info('Online transaction updated', [
                    'online_transaction_id' => $tx->id,
                    'selling_changed' => $sellingChanged,
                    'purchase_changed' => $purchaseChanged,
                    'amount_paid_changed' => $amountPaidChanged,
                    'account_changed' => $accountChanged,
                    'customer_changed' => $customerChanged,
                    'status_changed' => $statusChanged,
                    'original_status' => $originalStatus->value,
                    'new_status' => $newStatus->value,
                    'updated_by' => Auth::id(),
                ]);

                return $tx->fresh([
                    'serviceTypeRow',
                    'providerRow',
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

    /**
     * Repost the main income transaction when selling_price changes.
     *
     * Mirrors `HajjUmraBookingService::repostIncomeTransaction` /
     * `VisaBookingService::repostIncomeTransaction` (Phase 8): reverse the
     * old transaction (additive — never destructive), then post a fresh
     * income with the new amount. Returns the new transaction, or the
     * unchanged old one if amount matches.
     *
     * Resolves the destination account the same way `postFinancialEntries`
     * does: customer account if customer_id is set, otherwise $tx->account_id
     * (direct cashbox deposit for anonymous customers).
     */
    protected function repostIncomeTransaction(OnlineTransaction $tx, float $newSelling): ?Transaction
    {
        if (! $tx->income_transaction_id) {
            return null;
        }

        $oldTx = Transaction::find($tx->income_transaction_id);
        if (! $oldTx) {
            return null;
        }

        $oldAmount = (float) $oldTx->amount;
        if (abs($oldAmount - $newSelling) < 0.000001) {
            return $oldTx; // no-op
        }

        $this->transactionService->reverseTransaction($oldTx);

        // Mirror the AR-account resolution from `postFinancialEntries()`:
        //   registered customer → their own AR (auto-created)
        //   walk-in             → module-wide walk-in AR mirror
        $arAccountId = $tx->customer_id
            ? $this->ensureCustomerAccount((int) $tx->customer_id)->id
            : app(LedgerClearingAccounts::class)->onlineWalkInArAccountId();

        return $this->transactionService->recordIncome([
            'amount' => $newSelling,
            'to_account_id' => $arAccountId,
            'module' => TransactionModule::Online->value,
            'related_type' => OnlineTransaction::class,
            'related_id' => $tx->id,
            'notes' => 'إعادة تسجيل مديونية (تعديل سعر) — '.$tx->customer_name,
            'created_by' => Auth::id() ?? 1,
        ]);
    }

    /**
     * Repost the expense transaction when purchase_price changes.
     *
     * Mirrors `HajjUmraBookingService::repostExpenseTransaction` /
     * `VisaBookingService::repostExpenseTransaction` (Phase 8): reverse the
     * old transaction (additive — never destructive), then post a fresh
     * expense with the new amount.
     *
     * Resolves the source account the same way `postFinancialEntries` does
     * (Fawry pattern):
     *   - provider.default_purchase_account_id → use it
     *   - else if amountPaid > 0 → vault (cash covers the cost)
     *   - else → income clearing account (vault has nothing to debit)
     */
    protected function repostExpenseTransaction(OnlineTransaction $tx, float $newPurchase): ?Transaction
    {
        if (! $tx->expense_transaction_id) {
            return null;
        }

        $oldTx = Transaction::find($tx->expense_transaction_id);
        if (! $oldTx) {
            return null;
        }

        $oldAmount = (float) $oldTx->amount;
        if (abs($oldAmount - $newPurchase) < 0.000001) {
            return $oldTx; // no-op
        }

        $provider = $tx->provider_code
            ? OnlineServiceProvider::where('code', $tx->provider_code)->first()
            : null;
        $amountPaid = (float) ($tx->amount_paid ?? $tx->selling_price);

        if ($provider?->default_purchase_account_id) {
            $sourceAccountId = $provider->default_purchase_account_id;
        } elseif ($amountPaid > 0) {
            $sourceAccountId = $tx->account_id;
        } else {
            $sourceAccountId = app(LedgerClearingAccounts::class)
                ->incomeContraIdForModule('online') ?? $tx->account_id;
        }

        $this->transactionService->reverseTransaction($oldTx);

        return $this->transactionService->recordExpense([
            'amount' => $newPurchase,
            'from_account_id' => $sourceAccountId,
            'module' => TransactionModule::Online->value,
            'related_type' => OnlineTransaction::class,
            'related_id' => $tx->id,
            'notes' => 'إعادة تسجيل تكلفة خدمة أونلاين (تعديل سعر) — '.$tx->customer_name,
            'created_by' => Auth::id() ?? 1,
        ]);
    }

    /**
     * Repost the cash payment transaction when amount_paid or account_id changes.
     *
     * The cash payment is the OPTIONAL second income transaction created
     * in `postFinancialEntries` when `customer_id` is set AND `amount_paid > 0`.
     * Its transaction_id is NOT stored on $tx, so we locate it via the
     * account pair that uniquely identifies it:
     *   - from_account_id = customer account (cash LEAVES customer debt)
     *   - to_account_id = $tx->account_id (cash ARRIVES in vault)
     *
     * (Note: TransactionService internally stores ALL double-entry
     * transactions as `type=transfer` — the income/expense semantic lives
     * in the from/to direction, NOT in the `type` column. So we must
     * filter by the account pair, not by `type`.)
     *
     * Account-swap edge case: if the user edits the transaction and chooses
     * a new vault (account_id changed), the old transfer was posted with
     * the OLD pair (customer → oldAccount). A naive equality filter on
     * the NEW to_account_id would miss it, leaving the old transfer
     * un-reversed and corrupting the GL. We therefore search by EITHER
     * the previous or the new (old/new is captured via $original attributes).
     *
     * Handles all 4 transitions (X→Y where X and Y can be 0):
     *   X>0, Y>0: reverse old + create new
     *   X>0, Y=0: reverse old only
     *   X=0, Y>0: create new only
     *   X=0, Y=0: no-op
     */
    protected function repostCashPaymentTransaction(
        OnlineTransaction $tx,
        float $newAmountPaid,
        ?int $oldAccountId = null,
        ?int $oldCustomerId = null,
    ): void {
        // Cash payment transfer exists for ANY transaction (registered customer
        // OR walk-in) — both routes post a journal transfer from the AR mirror
        // to the vault. The previous version short-circuited on
        // `! $tx->customer_id`, which left walk-in cash payments un-reversed
        // when walk-in / registered status flipped during an edit. Removed
        // the early-return guard so all transactions get the same treatment.

        $arAccountId = $tx->customer_id
            ? $this->ensureCustomerAccount((int) $tx->customer_id)->id
            : app(LedgerClearingAccounts::class)->onlineWalkInArAccountId();

        // Build the candidate set of (from_account, to_account) pairs the
        // cash-payment transfer could have been posted with. We must check
        // both the OLD pair (in case the user swapped vault or customer)
        // AND the NEW pair (in case customer/vault switched).
        $oldVaultId = $oldAccountId ?? $tx->account_id;
        $oldArAccountId = $oldCustomerId
            ? ($this->ensureCustomerAccount($oldCustomerId)->id ?? $arAccountId)
            : $arAccountId;

        $candidatePairs = [
            ['from' => $oldArAccountId, 'to' => $oldVaultId],
            ['from' => $arAccountId, 'to' => $tx->account_id],
        ];

        // Fetch the most recent ACTIVE cash-payment transfer posted on this
        // transaction that matches one of the candidate pairs. We use
        // OR-where on the pair to handle the swap case, exclude any
        // transfer that was already reversed (its entries carry the
        // 'عكس القيد #…' marker written by TransactionService::reverseTransaction),
        // and order by id DESC so multiple edits always re-target the latest
        // live transfer — without this guard the second edit was a no-op
        // (reversing an already-reversed row) and the customer AR drifted.
        $cashPaymentTx = Transaction::where('related_type', OnlineTransaction::class)
            ->where('related_id', $tx->id)
            ->where(function ($q) use ($candidatePairs) {
                foreach ($candidatePairs as $pair) {
                    $q->orWhere(function ($inner) use ($pair) {
                        $inner->where('from_account_id', $pair['from'])
                            ->where('to_account_id', $pair['to']);
                    });
                }
            })
            ->whereDoesntHave('entries', fn ($q) => $q->where('notes', 'like', 'عكس القيد#%'))
            ->orderBy('id', 'desc')
            ->first();

        if ($cashPaymentTx) {
            $this->transactionService->reverseTransaction($cashPaymentTx);
        }

        if ($newAmountPaid > 0.001) {
            $this->transactionService->recordJournalTransfer([
                'amount' => $newAmountPaid,
                'from_account_id' => $arAccountId,
                'to_account_id' => $tx->account_id,
                'allow_from_negative' => true,
                'module' => TransactionModule::Online->value,
                'related_type' => OnlineTransaction::class,
                'related_id' => $tx->id,
                'notes' => 'إعادة تسجيل سداد جزئي (تعديل) — '.$tx->customer_name,
                'created_by' => Auth::id() ?? 1,
            ]);
        }
    }

    public function delete(OnlineTransaction $tx): bool
    {
        try {
            // 🛡️ Idempotency guard (Phase 10 — mirrors FawryTransactionService):
            // if the row is already soft-deleted, do nothing. Without this, a
            // second call on the same row would re-reverse the (already
            // reversed) GL entries and double-credit the vault.
            $alreadyDeleted = DB::table('online_transactions')
                ->where('id', $tx->id)
                ->whereNotNull('deleted_at')
                ->exists();
            if ($alreadyDeleted) {
                Log::info('Online transaction delete skipped — already soft-deleted', [
                    'online_transaction_id' => $tx->id,
                    'user_id' => Auth::id(),
                ]);

                return true;
            }

            // Re-read the row so the values reflect the latest pay-debt / update
            // edits done by other flows (e.g. CustomerController::payDebt
            // doesn't touch this row, but service.update or other writers do).
            $tx = $tx->fresh();

            return DB::transaction(function () use ($tx) {
                $isWalkIn = empty($tx->customer_id);
                $customerName = (string) $tx->customer_name;
                $vaultId = (int) $tx->account_id;

                // 🛡️ Production-safety guard (business rule):
                // Refuse to delete if any debt payment was recorded after
                // the operation was created. Runs BEFORE reversal / FIFO /
                // soft-delete so a blocked attempt leaves books & balances
                // untouched. Same shared service used by Fawry / Wallet.
                $customerAccountId = null;
                if (! $isWalkIn) {
                    $customerAccountId = (int) (Customer::query()
                        ->where('id', (int) $tx->customer_id)
                        ->value('account_id') ?? 0) ?: null;
                }
                $originalSettlement = $this->deletionGuard->computeOriginalSettlement(
                    OnlineTransaction::class,
                    (int) $tx->id,
                    $vaultId,
                );
                $this->deletionGuard->ensureNoLaterPayment(
                    $tx->created_at,
                    (float) ($tx->amount_paid ?? 0.0),
                    $originalSettlement,
                    $customerAccountId,
                    OnlineTransaction::class,
                    (int) $tx->id,
                );

                // ── 1. Reverse ALL transactions linked to this online tx
                //       (covers: main income, cash settlement, and expense).
                //       `reverseTransaction` is idempotent — already-reversed
                //       entries are no-ops, so step 1 is safe even if the
                //       service is called twice on the same in-flight model.
                $linkedTransactions = Transaction::where('related_type', OnlineTransaction::class)
                    ->where('related_id', $tx->id)
                    ->orderByDesc('id')
                    ->get();

                foreach ($linkedTransactions as $rt) {
                    $this->transactionService->reverseTransaction($rt);
                }

                // ── 2. Walk-in AR reclamation (Fawry Phase 6 pattern):
                //       For a walk-in client the per-name debt lives in the
                //       shared "ذمم عملاء الخدمات الإلكترونية غير مسجلين" AR
                //       mirror. After step 1 that mirror is back to its
                //       pre-create balance. But if the customer paid some of
                //       the debt through the generic CustomerController
                //       (route: POST /api/v1/customers/{id}/pay-debt) those
                //       pay-debt entries are NOT linked to this online_tx
                //       (they have no related_id), so the AR mirror still
                //       carries a residual credit. Re-allocate that residual
                //       FIFO to OTHER walk-in transactions for the same name;
                //       whatever's left goes back to the vault (credit memo
                //       for the customer).
                $residualForReallocation = 0.0;
                if ($isWalkIn) {
                    $residualForReallocation = $this->reclaimWalkInArExcess(
                        $tx,
                        $customerName,
                        $vaultId,
                    );
                }

                // ── 3. Flip status → Cancelled + stamp audit fields
                $cancellationNote = '[تم الإلغاء بواسطة '.Auth::user()?->name.' في '.now()->format('Y-m-d H:i').']'
                    .($residualForReallocation > 0.005
                        ? ' — إعادة توجيه رصيد walk-in: '.number_format($residualForReallocation, 2).' ج.م'
                        : '');

                $tx->status = OnlineTransactionStatus::Cancelled;
                $tx->failure_reason = ($tx->failure_reason ? $tx->failure_reason."\n" : '').$cancellationNote;
                $tx->cancelled_by = Auth::id();
                $tx->cancelled_at = now();
                $tx->save();

                // ── 4. Soft-delete the row (the model uses
                //       ModelDeletionGuard so $tx->delete() is allowed inside
                //       this static `run` callback).
                OnlineTransaction::run(function () use ($tx) {
                    $tx->delete();
                });

                Log::info('Online transaction cancelled and ledger reversed', [
                    'online_transaction_id' => $tx->id,
                    'cancelled_by' => Auth::id(),
                    'walk_in_reallocated' => round($residualForReallocation, 2),
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

    /**
     * Walk-in AR reclamation (Fawry Phase 6 / Bug B pattern).
     *
     * The walk-in AR mirror account (`ذمم عملاء الخدمات الإلكترونية غير مسجلين`)
     * aggregates debt across all walk-in transactions for the same
     * customer_name. When we cancel one of those transactions and the
     * customer had already paid some of that debt through the generic
     * `CustomerController::payDebt` (module=online), the pay-debt entries
     * are NOT linked to this online_transaction via related_id, so the
     * step-1 reversal of THIS transaction's linked entries does not see
     * them. The AR mirror therefore keeps a residual credit (= the amount
     * already paid by the customer, now stranded because the sale it
     * paid against is gone).
     *
     * Strategy:
     *   1) Read the residual credit on the walk-in AR mirror.
     *   2) Re-allocate it FIFO against OTHER walk-in online transactions
     *      for the same customer_name by increasing their `amount_paid`.
     *      This keeps the per-transaction debt report honest (column-source
     *      debt still matches the GL).
     *   3) Whatever is left after FIFO gets returned to the vault via a
     *      journal transfer (debit vault, credit walk-in AR) — a credit
     *      memo for the client. This balances the AR and acknowledges
     *      the residual.
     *   4) Zero out the deleted transaction's `amount_paid` so the
     *      per-transaction debt from column-source equals 0.
     *
     * Returns the total amount reallocated or returned to the vault
     * (caller uses it to record the cancellation reason).
     */
    protected function reclaimWalkInArExcess(
        OnlineTransaction $tx,
        string $customerName,
        int $vaultId,
    ): float {
        if ($customerName === '' || $vaultId <= 0) {
            return 0.0;
        }

        $clearing = app(LedgerClearingAccounts::class);
        $walkInArId = $clearing->onlineWalkInArAccountId();

        // Phase 10 reclamation — scope to THIS customer_name. The walk-in
        // AR mirror is shared across all walk-in clients, so we compute
        // THIS client's total overpayment (column-source) and only return
        // that, capped at the walk-in AR's negative balance.
        //
        // Components of this client's overpayment:
        //   A) The cancelled tx's own column-source overpayment
        //      = (cancelled.amount_paid - cancelled.selling) when > 0.
        //      (amount_paid has not been zeroed yet at this point — step
        //      3c below does that AFTER we read the value.)
        //   B) Other non-cancelled walk-in txs for the same customer_name
        //      with (amount_paid - selling) > 0.
        $cancelledOverpayment = max(0.0, round(
            (float) $tx->amount_paid - (float) $tx->selling_price,
            2,
        ));

        $otherTxsOverpayment = (float) DB::table('online_transactions')
            ->whereNull('customer_id')
            ->where('customer_name', $customerName)
            ->where('id', '!=', $tx->id)
            ->whereNull('deleted_at')
            ->where('status', OnlineTransactionStatus::Completed->value)
            ->whereRaw('amount_paid > selling_price')
            ->selectRaw('COALESCE(SUM(amount_paid - selling_price), 0) as overpaid')
            ->value('overpaid');

        $thisClientOverpayment = round($cancelledOverpayment + $otherTxsOverpayment, 2);
        if ($thisClientOverpayment <= 0.005) {
            return 0.0;
        }

        // Cap at the walk-in AR's actual negative balance. If multiple
        // walk-in clients share the mirror and OTHER clients also have
        // overpayments, we won't claim their money.
        $walkInArAccount = Account::find($walkInArId);
        $walkInArNegative = $walkInArAccount ? abs(min(0.0, (float) $walkInArAccount->balance)) : 0.0;
        $overpayment = round(min($thisClientOverpayment, $walkInArNegative), 2);
        if ($overpayment <= 0.005) {
            return 0.0;
        }

        $createdBy = Auth::id() ?? 1;
        $remaining = $overpayment;

        // 3a) FIFO re-allocate to other walk-in transactions for the same
        //      customer_name that still have unpaid debt. We do this in
        //      column-source space (online_transactions.amount_paid) so
        //      per-transaction debt stays consistent. After this loop
        //      `remaining` is what couldn't be absorbed by other txs.
        $fifoTxs = DB::table('online_transactions')
            ->whereNull('customer_id')
            ->where('customer_name', $customerName)
            ->where('id', '!=', $tx->id)
            ->whereNull('deleted_at')
            ->where('status', OnlineTransactionStatus::Completed->value)
            ->whereRaw('selling_price > amount_paid')
            ->orderBy('created_at', 'asc')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        foreach ($fifoTxs as $other) {
            if ($remaining <= 0.005) {
                break;
            }
            $otherDebt = (float) $other->selling_price - (float) $other->amount_paid;
            if ($otherDebt <= 0) {
                continue;
            }
            $allocate = min($remaining, $otherDebt);
            DB::table('online_transactions')
                ->where('id', $other->id)
                ->update([
                    'amount_paid' => DB::raw('amount_paid + '.(float) $allocate),
                    'updated_at' => now(),
                ]);
            $remaining = round($remaining - $allocate, 2);
        }

        // `remaining` is what couldn't be absorbed by other txs — that's
        // the residual credit we return to the vault.
        $overpayment = $remaining;

        // 3b) Whatever wasn't absorbed by other walk-in transactions gets
        //      returned to the customer as a credit memo. Per project
        //      convention (balance = SUM(credit) - SUM(debit)) this means:
        //        - DEBIT the vault (= vault.balance decreases — we hand cash
        //          back to the customer)
        //        - CREDIT the walk-in AR mirror (= walkInAr.balance
        //          increases, moving back toward 0 from the negative side)
        //      So in `recordJournalTransfer` the from-account is the
        //      vault, the to-account is the walk-in AR mirror. Without
        //      `allow_from_negative=true` the vault must have enough cash.
        if ($remaining > 0.005 && $vaultId > 0) {
            $this->transactionService->recordJournalTransfer([
                'amount' => $remaining,
                'from_account_id' => $vaultId,
                'to_account_id' => $walkInArId,
                'module' => TransactionModule::Online->value,
                'related_type' => OnlineTransaction::class,
                'related_id' => $tx->id,
                'notes' => "إعادة مديونية walk-in محذوفة ({$customerName}) — عملية #{$tx->id}",
                'created_by' => $createdBy,
                'allow_from_negative' => true,
            ]);
        }

        // 3c) Zero out the deleted transaction's amount_paid so the
        //      per-transaction debt column matches reality.
        DB::table('online_transactions')
            ->where('id', $tx->id)
            ->update([
                'amount_paid' => 0,
                'updated_at' => now(),
            ]);

        return round($overpayment, 2);
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
        ?OnlineServiceType $serviceType,
        ?OnlineServiceProvider $provider,
        float $purchase,
        float $selling,
        string $customerName,
    ): void {
        $module = TransactionModule::Online->value;
        $providerLabel = $provider?->name_ar ? " - {$provider->name_ar}" : '';
        $createdBy = Auth::id() ?? 1;

        // service_type_code is now the source of truth (free-text). The
        // $serviceType passed in is an optional lookup for the Arabic label.
        $serviceTypeLabel = $serviceType?->name_ar ?? $tx->service_type_code;

        // Resolve the AR account that holds the receivable (debt).
        // - Registered customer → their own AR (auto-created on first use).
        // - Walk-in (no customer_id) → module-wide walk-in AR mirror (mirrors
        //   Fawry's `fawryWalkInArAccountId()` pattern). This keeps the debt
        //   visible in the GL / trial balance / office receivables report.
        $arAccountId = $tx->customer_id
            ? $this->ensureCustomerAccount((int) $tx->customer_id)->id
            : app(LedgerClearingAccounts::class)->onlineWalkInArAccountId();

        // 1) SALE income — credit the AR mirror for the full selling price.
        //    This is the canonical "money owed" entry. The cash-side
        //    settlement (entry #2) only moves cash from the AR mirror to the
        //    vault when the customer pays now.
        $income = null;
        if ($selling > 0) {
            $income = $this->transactionService->recordIncome([
                'amount' => $selling,
                'to_account_id' => $arAccountId,
                'module' => $module,
                'related_type' => OnlineTransaction::class,
                'related_id' => $tx->id,
                'notes' => ($tx->customer_id
                    ? "تحصيل خدمة أونلاين (مديونية) - {$serviceTypeLabel}{$providerLabel}: {$customerName}"
                    : "مديونية خدمة أونلاين (عميل غير مسجل - {$customerName}) - {$serviceTypeLabel}{$providerLabel}"),
                'created_by' => $createdBy,
            ]);
            $tx->income_transaction_id = $income->id;
        }

        // 2) CASH settlement — only when amount_paid > 0. Move cash from the
        //    AR mirror to the vault. If the customer paid full selling_price,
        //    the AR balance net settles to 0; if partial, the remainder is
        //    still owed (visible in the AR mirror).
        $amountPaid = (float) ($tx->amount_paid ?? $selling);
        if ($amountPaid > 0 && $tx->account_id) {
            $this->transactionService->recordJournalTransfer([
                'amount' => $amountPaid,
                'from_account_id' => $arAccountId,
                'to_account_id' => $tx->account_id,
                'allow_from_negative' => true,
                'module' => $module,
                'related_type' => OnlineTransaction::class,
                'related_id' => $tx->id,
                'notes' => ($tx->customer_id
                    ? "سداد جزئي من عميل - {$serviceTypeLabel}{$providerLabel}: {$customerName}"
                    : "سداد جزئي (عميل غير مسجل - {$customerName}) - {$serviceTypeLabel}{$providerLabel}"),
                'created_by' => $createdBy,
            ]);
        }

        // 3) EXPENSE — route the cost of the service following the Fawry
        //    convention (proven pattern):
        //      a) Provider has a default_purchase_account_id → use it
        //         (the supplier's prepaid / credit account).
        //      b) Provider has no purchase account BUT customer paid cash
        //         → use the vault (the vault just received cash, so the
        //         cost correctly comes out of that cash).
        //      c) Provider has no purchase account AND unpaid (credit) → use
        //         the income clearing account so the vault doesn't go
        //         negative when nothing was collected.
        if ($purchase > 0) {
            $clearing = app(LedgerClearingAccounts::class);

            if ($provider?->default_purchase_account_id) {
                $sourceAccountId = $provider->default_purchase_account_id;
            } elseif ($amountPaid > 0) {
                $sourceAccountId = $tx->account_id;
            } else {
                $sourceAccountId = $clearing->incomeContraIdForModule('online') ?? $tx->account_id;
            }

            $expense = $this->transactionService->recordExpense([
                'amount' => $purchase,
                'from_account_id' => $sourceAccountId,
                'module' => $module,
                'related_type' => OnlineTransaction::class,
                'related_id' => $tx->id,
                'notes' => "تكلفة خدمة أونلاين - {$serviceTypeLabel}{$providerLabel}: {$customerName}",
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
                // Phase 1.Bend3 fix: CustomerLedgerObserver creates a generic
                // 'office'-tagged account the moment a Customer row is
                // inserted. When that customer is later used in an Online
                // transaction flow we re-tag the account to 'online' so it
                // surfaces in the Online dashboards / strict module_type
                // queries (e.g. OnlineStats widget, TreasuryService). Wrapped
                // in LedgerBalanceMutationGuard because touching `balance`
                // — even to confirm 0.00 — would otherwise trip the
                // Account::updating boot guard.
                if ($account->module_type !== 'online') {
                    LedgerBalanceMutationGuard::run(function () use ($account) {
                        $account->module_type = 'online';
                        $account->save();
                    });
                }

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

    /**
     * 🛡️ Phase 10: cross-currency guard. The Online module is intentionally
     * EGP-only (per the project owner — confirmed 2026-07-27). We reject
     * any booking whose vault currency differs from the AR currency BEFORE
     * we call TransactionService, so the global double-entry machinery is
     * never asked to silently FX-convert a sale.
     *
     * Rules:
     *  - The vault ($data['account_id']) MUST be in EGP.
     *  - The customer AR mirror (when customer_id is set) MUST be in EGP.
     *  - The walk-in AR mirror (`ذمم عملاء الخدمات الإلكترونية غير مسجلين`)
     *    is always EGP (created in EGP by `onlineWalkInArAccountId()`).
     *
     * Throws InvalidArgumentException with an Arabic message that the Vue
     * UI surfaces via the toast.
     */
    protected function assertCurrencyCompatible(array $data): void
    {
        $expected = 'EGP';

        if (! empty($data['account_id'])) {
            $vault = Account::find((int) $data['account_id']);
            if ($vault && strtoupper((string) $vault->currency) !== $expected) {
                throw new \InvalidArgumentException(
                    'موديول الخدمات الإلكترونية يقبل فقط الحسابات بعملة الجنيه المصري (EGP). '
                    ."الحساب المختار «{$vault->name}» بعملة ".strtoupper((string) $vault->currency).'.'
                );
            }
        }

        if (! empty($data['customer_id'])) {
            $customer = Customer::find((int) $data['customer_id']);
            if ($customer && $customer->account_id) {
                $ar = Account::find($customer->account_id);
                if ($ar && strtoupper((string) $ar->currency) !== $expected) {
                    throw new \InvalidArgumentException(
                        'حساب العميل بعملة '.strtoupper((string) $ar->currency)
                        .' — موديول الخدمات الإلكترونية يقبل فقط حسابات العملاء بالجنيه المصري (EGP).'
                    );
                }
            }
        }
    }

    /**
     * Ensure every Online transaction is linked to a Customer record.
     *
     * Flow:
     *  1. If `customer_id` is provided → use it as-is.
     *  2. Else if a free-text `customer_name` is provided:
     *     a. Try to match an existing Customer by phone (if supplied) OR
     *        by exact full_name match — to avoid duplicating an existing
     *        customer when the user types a name that already exists.
     *     b. If no match is found, create a new Customer scoped to the
     *        Online module (`module_type='online'`).
     *     c. Mutate `$data` so the downstream code sees the resolved
     *        `customer_id` and the persisted customer record is created
     *        inside the same DB transaction.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function ensureCustomerIsLinked(array $data): array
    {
        if (! empty($data['customer_id'])) {
            return $data;
        }

        $name = isset($data['customer_name']) ? trim((string) $data['customer_name']) : '';
        $phone = isset($data['customer_phone']) ? trim((string) $data['customer_phone']) : '';

        if ($name === '') {
            return $data;
        }

        // Match by phone first (more reliable), then by exact name.
        $existing = null;
        if ($phone !== '') {
            $existing = Customer::query()
                ->where('phone', $phone)
                ->first();
        }
        if (! $existing) {
            $existing = Customer::query()
                ->where('full_name', $name)
                ->first();
        }

        if ($existing) {
            $data['customer_id'] = $existing->id;
            // Backfill module_type if it's missing — keeps the customer
            // visible in the Online module list going forward.
            if (empty($existing->module_type)) {
                $existing->module_type = 'online';
                $existing->save();
            }

            return $data;
        }

        // No match → create a fresh Customer scoped to this module.
        $customer = Customer::create([
            'full_name' => $name,
            'phone' => $phone ?: null,
            'type' => CustomerType::Individual->value,
            'module_type' => 'online',
            'status' => 'active',
            'created_by' => Auth::id(),
        ]);

        $data['customer_id'] = $customer->id;

        return $data;
    }
}
