<?php

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Helpers\ApiResponse;
use App\Helpers\CacheHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreWalletTransactionRequest;
use App\Http\Requests\Wallet\UpdateWalletTransactionRequest;
use App\Http\Resources\Wallet\WalletTransactionResource;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Wallet\WalletTransaction;
use App\Services\Finance\LedgerEntryDescriptionResolver;
use App\Services\Wallet\WalletTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletTransactionController extends Controller
{
    public function __construct(
        protected WalletTransactionService $service
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'type', 'wallet_type_id', 'customer_id', 'employee_id',
            'search', 'from_date', 'to_date', 'per_page',
        ]);
        $paginator = $this->service->getAllTransactions($filters);

        return ApiResponse::paginated(
            'Wallet transactions retrieved successfully.',
            WalletTransactionResource::collection($paginator),
            $paginator
        );
    }

    public function store(StoreWalletTransactionRequest $request): JsonResponse
    {
        try {
            // IDM-1 (2026-08-20): forward the Idempotency-Key header so the
            // service can detect double-submits (same key → replay the
            // original result instead of creating a duplicate transaction).
            // Header is NOT in $request->validated() because it's a header,
            // not a body field. Mirrors the established Bus/Hajj/Flight/Visa
            // controller pattern.
            $payload = $request->validated();
            $idempotencyKey = $request->header('Idempotency-Key');
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $payload['idempotency_key'] = $idempotencyKey;
            }

            $transaction = $this->service->createTransaction($payload);

            // IDM-1: distinguish "first request, created" (201) from
            // "replay, already existed" (200). The transient
            // `idempotent_replay` flag is set by the service's
            // Layer-1 pre-check or Layer-2 DB-UNIQUE backstop.
            $isReplay = (bool) ($transaction->idempotent_replay ?? false);
            $status = $isReplay ? 200 : 201;
            $message = $isReplay
                ? 'تم استرجاع العملية السابقة (إعادة طلب)'
                : 'Wallet transaction created successfully.';

            $response = ApiResponse::success(
                $message,
                array_merge(
                    (new WalletTransactionResource($transaction))->resolve($request),
                    ['idempotent_replay' => $isReplay]
                ),
                $status
            );
            // Strip the transient flag from the model so downstream code
            // that touches $transaction doesn't see a non-database field.
            unset($transaction->idempotent_replay);
            return $response;
        } catch (\App\Exceptions\BusinessLogicException $e) {
            // UX-1 (2026-08-21): re-throw typed business exceptions so the
            // bootstrap/app.php withExceptions() handler can map them to
            // HTTP 409 Conflict. ValidationException is intentionally left
            // to Laravel's default handler (422). Generic \Exception is
            // also re-thrown — we no longer coerce everything to 422 here.
            throw $e;
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function show(WalletTransaction $transaction, Request $request): JsonResponse
    {
        // SEC-2 (2026-08-21) IDOR mitigation + SEC-4 soft-delete defense:
        // the route-model binding already implicitly filters out soft-deleted
        // rows (SoftDeletes global scope), but we re-check `whereNull('deleted_at')`
        // explicitly here so the contract is documented and so any future
        // change to the route binding does not silently re-introduce the leak.
        //
        // SEC-2: enforce creator-scoping for non-admin/non-owner viewers. A
        // cashier who did NOT create the transaction must not be able to
        // read it. Admin/owner bypass via `scopeVisibleTo`.
        $user = $request->user();
        $transaction->load([
            'walletType', 'customer', 'walletAccount', 'cashAccount',
            'receiveDestinationAccount',
            'employee', 'createdBy', 'incomeTransaction', 'expenseTransaction',
        ]);

        if (! $transaction) {
            return ApiResponse::error('Wallet transaction not found.', null, 404);
        }
        if ($transaction->deleted_at !== null) {
            // SEC-4: explicit 404 on soft-deleted rows. Reachable only if
            // someone bypasses route-model binding.
            return ApiResponse::error('Wallet transaction not found.', null, 404);
        }
        if ($user && ! in_array($user->role, ['admin', 'owner'], true)
            && (int) $transaction->created_by !== (int) $user->id) {
            // SEC-2: non-admin non-creator → 404 (info-leak-safe).
            return ApiResponse::error('Wallet transaction not found.', null, 404);
        }

        return ApiResponse::success(
            'Wallet transaction retrieved successfully.',
            new WalletTransactionResource($transaction)
        );
    }

    public function update(UpdateWalletTransactionRequest $request, WalletTransaction $transaction): JsonResponse
    {
        try {
            $updated = $this->service->updateTransaction($transaction, $request->validated());

            return ApiResponse::success(
                'Wallet transaction updated successfully.',
                new WalletTransactionResource($updated)
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function destroy(WalletTransaction $transaction): JsonResponse
    {
        try {
            $this->service->deleteTransaction($transaction);

            // Flush finance-listing and dashboard caches so the
            // deleted Wallet operation disappears from `finance/accounts`,
            // `deficit_accounts`, and the unified dashboard without
            // waiting for the 30s/300s TTLs to expire.
            CacheHelper::flushTags(['accounts', 'dashboard', 'wallet_transactions']);
            CacheHelper::flushNamespace();

            return ApiResponse::success('Wallet transaction deleted successfully.');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function dailySummary(Request $request): JsonResponse
    {
        $date = $request->get('date', today()->toDateString());
        $summary = $this->service->getDailySummary($date);

        return ApiResponse::success('Daily summary retrieved successfully.', $summary);
    }

    public function customerBalances(Request $request): JsonResponse
    {
        try {
            $search = $request->query('search');
            $status = $request->query('status', 'all');
            $walletTypeId = $request->query('wallet_type_id', 'all');
            $dateFrom = $request->query('from_date');
            $dateTo = $request->query('to_date');

            // SEC-2 (2026-08-21): scope balances to the viewer's own
            // transactions. Admin/owner see everything; everyone else sees
            // only the rows they themselves created.
            $user = $request->user();
            $creatorScope = $user && ! in_array($user->role, ['admin', 'owner'], true)
                ? (int) $user->id
                : null;

            $query = WalletTransaction::query()
                ->select([
                    'wallet_transactions.customer_id',
                    'wallet_transactions.customer_name',
                    DB::raw('MAX(wallet_transactions.id) as id'),
                    DB::raw("SUM(CASE WHEN wallet_transactions.type = 'send' THEN wallet_transactions.total_amount ELSE 0 END) as total_sales"),
                    DB::raw('SUM(wallet_transactions.amount_paid) as total_paid'),
                    DB::raw("SUM(CASE WHEN wallet_transactions.type = 'send' THEN wallet_transactions.total_amount ELSE -wallet_transactions.total_amount END - wallet_transactions.amount_paid) as total_debt"),
                    DB::raw('COUNT(*) as transaction_count'),
                    DB::raw('MAX(wallet_transactions.created_at) as last_transaction'),
                ])
                ->leftJoin('customers', 'wallet_transactions.customer_id', '=', 'customers.id')
                ->groupBy(['wallet_transactions.customer_id', 'wallet_transactions.customer_name'])
                ->when($creatorScope !== null, function ($q) use ($creatorScope) {
                    $q->where('wallet_transactions.created_by', $creatorScope);
                })
                ->when($search, function ($q) use ($search) {
                    $q->where(function ($inner) use ($search) {
                        $inner->where('wallet_transactions.customer_name', 'like', '%'.$search.'%')
                            ->orWhere('customers.phone', 'like', '%'.$search.'%');
                    });
                })
                ->when($walletTypeId !== 'all', function ($q) use ($walletTypeId) {
                    $q->where('wallet_transactions.wallet_type_id', $walletTypeId);
                })
                ->when($dateFrom, function ($q) use ($dateFrom) {
                    $q->whereDate('wallet_transactions.created_at', '>=', $dateFrom);
                })
                ->when($dateTo, function ($q) use ($dateTo) {
                    $q->whereDate('wallet_transactions.created_at', '<=', $dateTo);
                });

            $records = $query->get();

            $clientIds = $records->pluck('customer_id')->filter()->unique();
            // Restrict to wallet_transfer-tagged customer accounts only so
            // a customer with a fawry/bus account does not have their
            // foreign-module debts silently mixed in. (Bug fix 2026-07-27.)
            $customerAccounts = Customer::whereIn('id', $clientIds)
                ->whereHas('ledgerAccount', function ($q) {
                    $q->where('module_type', 'wallet_transfer');
                })
                ->pluck('account_id', 'id')
                ->filter();

            // Pre-load customer phones in one query to avoid N+1
            $customerPhones = Customer::whereIn('id', $clientIds)
                ->pluck('phone', 'id');

            $accountBalances = [];
            if ($customerAccounts->isNotEmpty()) {
                $balances = DB::table('account_entries')
                    ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
                    ->whereIn('account_entries.account_id', $customerAccounts->values())
                    ->where('transactions.module', 'wallet')
                    ->select('account_entries.account_id')
                    ->selectRaw('SUM(account_entries.credit) as total_credit')
                    ->selectRaw('SUM(account_entries.debit) as total_debit')
                    ->groupBy('account_entries.account_id')
                    ->get()
                    ->keyBy('account_id');

                foreach ($customerAccounts as $clientId => $accountId) {
                    $bal = $balances->get($accountId);
                    if ($bal) {
                        $accountBalances[$clientId] = (float) $bal->total_credit - (float) $bal->total_debit;
                    } else {
                        $accountBalances[$clientId] = 0.0;
                    }
                }
            }

            $formatted = $records->map(function ($r) use ($accountBalances, $customerPhones) {
                $totalSales = (float) $r->total_sales;

                if ($r->customer_id && isset($accountBalances[$r->customer_id])) {
                    $totalDebt = $accountBalances[$r->customer_id];
                    $totalPaid = $totalSales - $totalDebt;
                } else {
                    $totalPaid = (float) $r->total_paid;
                    $totalDebt = (float) $r->total_debt;
                }

                return [
                    'client_id' => $r->customer_id,
                    'client_name' => $r->customer_name,
                    'phone' => $customerPhones->get($r->customer_id) ?? '—',
                    'total_sales' => $totalSales,
                    'total_paid' => $totalPaid,
                    'total_debt' => $totalDebt,
                    'transaction_count' => $r->transaction_count,
                    'last_transaction' => $r->last_transaction,
                ];
            });

            if ($status === 'debtors') {
                $formatted = $formatted->filter(fn ($r) => $r['total_debt'] > 0);
            } elseif ($status === 'creditors') {
                $formatted = $formatted->filter(fn ($r) => $r['total_debt'] < 0);
            }

            return ApiResponse::success('Wallet customer balances retrieved successfully.', $formatted->values());
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function customerStatement(Request $request): JsonResponse
    {
        try {
            $clientId = $request->query('client_id');
            $clientName = $request->query('client_name');

            // SEC-2 (2026-08-21): creator-scope the statement. Non-admin
            // viewers only see transactions they themselves created.
            $user = $request->user();
            $isAdminViewer = $user && in_array($user->role, ['admin', 'owner'], true);
            $creatorFilter = $isAdminViewer ? null : (int) ($user?->id ?? 0);

            $customer = $clientId ? Customer::find($clientId) : null;
            $customerAccount = $customer?->account_id ? Account::find($customer->account_id) : null;

            $running = 0.0;
            $formatted = [];

            if ($customerAccount) {
                $entries = AccountEntry::with([
                    'transaction.createdBy',
                    'transaction.fromAccount',
                    'transaction.toAccount',
                    'transaction.related',
                ])
                    ->where('account_id', $customerAccount->id)
                    ->whereHas('transaction', function ($q) use ($creatorFilter) {
                        $q->where('module', 'wallet');
                        if ($creatorFilter !== null) {
                            $q->where('created_by', $creatorFilter);
                        }
                    })
                    ->orderBy('created_at', 'asc')
                    ->get();

                foreach ($entries as $entry) {
                    $tx = $entry->transaction;
                    if (! $tx) {
                        continue;
                    }

                    $debit = (float) $entry->debit;
                    $credit = (float) $entry->credit;

                    $running += ($credit - $debit);

                    $description = app(LedgerEntryDescriptionResolver::class)->resolve($entry);

                    $otherAccount = ($tx->from_account_id == $customerAccount->id)
                        ? $tx->toAccount
                        : $tx->fromAccount;
                    $walletName = $otherAccount?->name ?? '—';

                    $typeLabel = $credit > 0 ? 'عملية' : 'سداد دفعة';

                    $formatted[] = [
                        'id' => $tx->id,
                        'date' => $entry->created_at->format('Y-m-d H:i'),
                        'machine' => $walletName,
                        'type' => $typeLabel,
                        'amount' => $credit > 0 ? $credit : $debit,
                        'employee' => $tx->createdBy?->name ?? '—',
                        'description' => $description,
                        'running_balance' => $running,
                    ];
                }
            } else {
                // ── Fallback path ─────────────────────────────────────────────
                // Two cases fall through here:
                //   (A) Walk-in clients — `customer_id IS NULL` and matched by
                //       free-form `customer_name`. (Original behaviour.)
                //   (B) Registered customer whose `account_id` is null or points
                //       at a deleted Account — `customer_id` is set but there is
                //       no GL row to query. The previous implementation returned
                //       an empty list, hiding every settlement/op. We now
                //       fall back to WalletTransaction directly so the modal
                //       still shows the customer history.
                $txsQ = WalletTransaction::query()
                    ->with(['walletAccount', 'employee'])
                    ->when($creatorFilter !== null, function ($q) use ($creatorFilter) {
                        $q->where('created_by', $creatorFilter);
                    });

                if ($customer) {
                    // Registered customer without an account row — pull their wallet
                    // tx by `customer_id` (covers the case where the customer was
                    // linked at tx time but account was never linked back).
                    $txsQ->where('customer_id', $customer->id);
                } else {
                    // True walk-in — `customer_id IS NULL`, matched by name.
                    $txsQ->whereNull('customer_id')
                        ->where('customer_name', $clientName);
                }

                $txs = $txsQ->orderBy('created_at', 'asc')->get();

                foreach ($txs as $tx) {
                    $amountPaid = (float) $tx->amount_paid;
                    $totalAmount = (float) $tx->total_amount;
                    $debt = max(0.0, $totalAmount - $amountPaid);

                    $running += $debt;

                    $formatted[] = [
                        'id' => $tx->id,
                        'date' => $tx->created_at->format('Y-m-d H:i'),
                        'machine' => $tx->walletAccount?->name ?? '—',
                        'type' => 'عملية',
                        'amount' => $totalAmount,
                        'employee' => $tx->employee?->name ?? '—',
                        'description' => $tx->notes ?? '—',
                        'running_balance' => $running,
                    ];
                }
            }

            return ApiResponse::success('Statement retrieved successfully.', [
                'transactions' => array_reverse($formatted),
                'running_balance' => $running,
            ]);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
