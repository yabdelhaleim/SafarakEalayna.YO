<?php

namespace App\Http\Controllers\Api\V1\Online;

use App\Helpers\ApiResponse;
use App\Helpers\CacheHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Online\StoreOnlineTransactionRequest;
use App\Http\Requests\Online\UpdateOnlineTransactionRequest;
use App\Http\Resources\Online\OnlineTransactionResource;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Online\OnlineTransaction;
use App\Services\Finance\LedgerEntryDescriptionResolver;
use App\Services\Online\OnlineTransactionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OnlineTransactionController extends Controller
{
    public function __construct(
        protected OnlineTransactionService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'service_type_code',
                'provider_code',
                'customer_id',
                'employee_id',
                'payment_method',
                'account_id',
                'status',
                'from_date',
                'to_date',
                'search',
                'per_page',
            ]);
            $filters['page'] = $request->get('page', 1);
            $filters['with_trashed'] = $request->boolean('with_trashed');

            $cacheKey = 'online_transactions_list_'.md5(serialize($filters));

            $data = CacheHelper::tags(['online_transactions'])->remember($cacheKey, 60, function () use ($filters) {
                $paginator = $this->service->getAll($filters);

                return [
                    'items' => OnlineTransactionResource::collection($paginator)->resolve(),
                    'pagination' => [
                        'total' => $paginator->total(),
                        'per_page' => $paginator->perPage(),
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'has_more' => $paginator->hasMorePages(),
                    ],
                ];
            });

            return ApiResponse::success('تم جلب معاملات الخدمات الأونلاين بنجاح.', $data);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 500);
        }
    }

    public function store(StoreOnlineTransactionRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            // SEC-4 idempotency: accept the key via the IETF-draft header
            // (`Idempotency-Key`). The header is the conventional transport
            // for idempotency tokens; it takes precedence over any body
            // value so callers can use either channel. Length is bounded
            // (100 chars) to match the column constraint.
            $headerKey = trim((string) $request->header('Idempotency-Key', ''));
            if ($headerKey !== '') {
                $data['idempotency_key'] = mb_substr($headerKey, 0, 100);
            }

            $tx = $this->service->create($data);

            // SEC-4 contract: a replay (same key, same actor, row already
            // exists) returns HTTP 200 + the same body as the original 201
            // plus an `idempotent_replay: true` flag. New creations stay on
            // HTTP 201. The transient flag is consumed by this controller —
            // it's a service-layer signal, not part of the persisted model.
            $isReplay = (bool) ($tx->idempotent_replay ?? false);
            unset($tx->idempotent_replay);

            return ApiResponse::success(
                'تم تنفيذ معاملة الخدمة بنجاح.',
                array_merge(
                    (new OnlineTransactionResource($tx))->resolve(),
                    ['idempotent_replay' => $isReplay],
                ),
                $isReplay ? 200 : 201,
            );
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function show(OnlineTransaction $onlineTransaction): JsonResponse
    {
        try {
            // SEC-3: IDOR hardening. Only admins / owners / the transaction's
            // owning employee may view this row. Anyone else → 403.
            $this->authorize('view', $onlineTransaction);

            $tx = $this->service->getById($onlineTransaction->id);

            return ApiResponse::success(
                'تم جلب المعاملة بنجاح.',
                new OnlineTransactionResource($tx),
            );
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return ApiResponse::error('غير مصرح لك بعرض هذه المعاملة.', null, 403);
        } catch (\Throwable $e) {
            return ApiResponse::error('المعاملة غير موجودة.', null, 404);
        }
    }

    public function update(UpdateOnlineTransactionRequest $request, OnlineTransaction $onlineTransaction): JsonResponse
    {
        try {
            // SEC-3: IDOR hardening. Only admins / owners / the transaction's
            // owning employee may PATCH this row.
            $this->authorize('update', $onlineTransaction);

            $tx = $this->service->update($onlineTransaction, $request->validated());

            return ApiResponse::success(
                'تم تحديث المعاملة بنجاح.',
                new OnlineTransactionResource($tx),
            );
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return ApiResponse::error('غير مصرح لك بتعديل هذه المعاملة.', null, 403);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function destroy(int $onlineTransaction): JsonResponse
    {
        // F-3 fix: route-model binding via `OnlineTransaction $onlineTransaction`
        // would 404 on soft-deleted rows, breaking retry-idempotency at the
        // HTTP layer. Resolve manually with withTrashed() so the service-level
        // idempotency guard (`delete()` returns true on already-deleted rows
        // without reversing GL again) takes over.
        try {
            $tx = OnlineTransaction::withTrashed()->findOrFail($onlineTransaction);

            // SEC-3: IDOR hardening. Defense-in-depth — the route is already
            // gated by `role:admin`, but the policy ensures ownership is
            // enforced even if the route middleware is loosened.
            $this->authorize('delete', $tx);

            $this->service->delete($tx);

            // Flush finance-listing and dashboard caches so the
            // deleted Online operation disappears from `finance/accounts`,
            // `deficit_accounts`, and the unified dashboard without
            // waiting for the 30s/300s TTLs to expire.
            CacheHelper::flushTags(['accounts', 'dashboard', 'online_transactions']);
            CacheHelper::flushNamespace();

            return ApiResponse::success('تم حذف المعاملة بنجاح.');
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return ApiResponse::error('غير مصرح لك بحذف هذه المعاملة.', null, 403);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function dailySummary(Request $request): JsonResponse
    {
        // Validate first so ValidationException propagates to the global
        // exception handler in bootstrap/app.php, which renders the
        // standard `{success:false, message, errors:{field:[...]}}` envelope.
        $request->validate(['date' => 'required|date_format:Y-m-d']);

        try {
            $summary = $this->service->getDailySummary($request->string('date')->toString());

            return ApiResponse::success('تم جلب الملخص اليومي بنجاح.', $summary);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function customerBalances(Request $request): JsonResponse
    {
        try {
            $search = $request->query('search');
            $status = $request->query('status', 'all');
            $serviceTypeCode = $request->query('service_type_code', 'all');
            $dateFrom = $request->query('from_date');
            $dateTo = $request->query('to_date');

            $query = OnlineTransaction::query()
                ->select([
                    'online_transactions.customer_id',
                    'online_transactions.customer_name',
                    DB::raw('MAX(online_transactions.id) as id'),
                    DB::raw('SUM(online_transactions.selling_price) as total_sales'),
                    DB::raw('SUM(COALESCE(online_transactions.amount_paid, online_transactions.selling_price)) as total_paid'),
                    DB::raw('SUM(online_transactions.selling_price - COALESCE(online_transactions.amount_paid, online_transactions.selling_price)) as total_debt'),
                    DB::raw('COUNT(*) as transaction_count'),
                    DB::raw('MAX(online_transactions.created_at) as last_transaction'),
                ])
                ->leftJoin('customers', 'online_transactions.customer_id', '=', 'customers.id')
                ->where('online_transactions.status', 'completed')
                ->groupBy(['online_transactions.customer_id', 'online_transactions.customer_name'])
                ->when($search, function ($q) use ($search) {
                    $q->where(function ($inner) use ($search) {
                        $inner->where('online_transactions.customer_name', 'like', '%'.$search.'%')
                            ->orWhere('customers.phone', 'like', '%'.$search.'%');
                    });
                })
                ->when($serviceTypeCode !== 'all', function ($q) use ($serviceTypeCode) {
                    $q->where('online_transactions.service_type_code', $serviceTypeCode);
                })
                ->when($dateFrom, function ($q) use ($dateFrom) {
                    $q->whereDate('online_transactions.created_at', '>=', $dateFrom);
                })
                ->when($dateTo, function ($q) use ($dateTo) {
                    $q->whereDate('online_transactions.created_at', '<=', $dateTo);
                });

            $records = $query->get();

            $clientIds = $records->pluck('customer_id')->filter()->unique();
            $customerAccounts = Customer::whereIn('id', $clientIds)
                ->pluck('account_id', 'id')
                ->filter();

            $accountBalances = [];
            if ($customerAccounts->isNotEmpty()) {
                $balances = DB::table('account_entries')
                    ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
                    ->whereIn('account_entries.account_id', $customerAccounts->values())
                    ->where('transactions.module', 'online')
                    ->when($dateFrom, function ($q) use ($dateFrom) {
                        $q->whereDate('account_entries.created_at', '>=', $dateFrom);
                    })
                    ->when($dateTo, function ($q) use ($dateTo) {
                        $q->whereDate('account_entries.created_at', '<=', $dateTo);
                    })
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

            $formatted = $records->map(function ($r) use ($accountBalances) {
                $customer = $r->customer_id ? Customer::find($r->customer_id) : null;
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
                    'phone' => $customer?->phone ?? '—',
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

            return ApiResponse::success('Online customer balances retrieved successfully.', $formatted->values());
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function customerStatement(Request $request): JsonResponse
    {
        try {
            $clientId = $request->query('client_id');
            $clientName = $request->query('client_name');
            $dateFrom = $request->query('from_date');
            $dateTo = $request->query('to_date');

            // Pagination — large statement ledgers (1000+ entries) used to
            // crush the API. We now support `page` + `per_page` (default 50,
            // max 200) and return envelope metadata so the UI can render
            // "next/prev" controls. `running_balance` reflects the cumulative
            // up to the LAST page returned (across all prior pages inclusive).
            $perPage = (int) $request->query('per_page', 50);
            $perPage = max(1, min($perPage, 200));
            $page = max(1, (int) $request->query('page', 1));

            $customer = $clientId ? Customer::find($clientId) : null;
            $customerAccount = $customer?->account_id ? Account::find($customer->account_id) : null;

            if ($customerAccount) {
                // Two-phase fetch so we can compute the running balance
                // correctly across pagination: first pull the COMPLETE list
                // of (id, drift) tuples in chronological order, then slice
                // out the requested page. This keeps the running balance
                // monotonically increasing across pages.
                $allEntries = AccountEntry::with([
                    'transaction.createdBy',
                    'transaction.fromAccount',
                    'transaction.toAccount',
                    'transaction.related' => function ($morph) {
                        $morph->morphWith([
                            // OnlineTransaction relations were renamed to *Row helpers
                            // (serviceTypeRow / providerRow) after migration
                            // 2026_08_28_000000_convert_online_service_type_and_provider_to_text
                            // converted the FK columns to free-text codes.
                            OnlineTransaction::class => ['serviceTypeRow', 'providerRow'],
                        ]);
                    },
                ])
                    ->where('account_id', $customerAccount->id)
                    ->whereHas('transaction', function ($q) {
                        $q->where('module', 'online');
                    })
                    ->when($dateFrom, function ($q) use ($dateFrom) {
                        $q->whereDate('account_entries.created_at', '>=', $dateFrom);
                    })
                    ->when($dateTo, function ($q) use ($dateTo) {
                        $q->whereDate('account_entries.created_at', '<=', $dateTo);
                    })
                    ->orderBy('created_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();

                $running = 0.0;
                $fullFormatted = [];

                foreach ($allEntries as $entry) {
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
                    $accountName = $otherAccount?->name ?? '—';

                    $isReversal = str_starts_with((string) ($entry->notes ?? ''), 'عكس')
                        || str_starts_with((string) ($tx->notes ?? ''), 'عكس');

                    if ($isReversal) {
                        $typeLabel = $credit > 0 ? 'إلغاء سداد' : 'إلغاء عملية';
                    } else {
                        $typeLabel = $credit > 0 ? 'عملية' : 'سداد دفعة';
                    }

                    $fullFormatted[] = [
                        'id' => $tx->id,
                        'date' => $entry->created_at->format('Y-m-d H:i'),
                        'machine' => $accountName,
                        'type' => $typeLabel,
                        'is_reversal' => $isReversal,
                        'amount' => $credit > 0 ? $credit : $debit,
                        'employee' => $tx->createdBy?->name ?? '—',
                        'description' => $description,
                        'running_balance' => $running,
                    ];
                }

                $total = count($fullFormatted);
                $reversed = array_reverse($fullFormatted);
                $paged = array_slice($reversed, ($page - 1) * $perPage, $perPage);
                $lastPage = max(1, (int) ceil($total / $perPage));
            } else {
                // ── Fallback path ─────────────────────────────────────────────
                // Two cases fall through here:
                //   (A) Walk-in clients — `customer_id IS NULL`, matched by free-form
                //       `customer_name`.
                //   (B) Registered customer without an account row — `customer_id`
                //       is set but no GL row to query. The previous implementation
                //       returned an empty list, hiding every online tx for that
                //       customer. We now fall back to OnlineTransaction directly.
                $txsQ = OnlineTransaction::query()
                    ->with(['account', 'employee'])
                    ->where('status', 'completed');

                if ($customer) {
                    $txsQ->where('customer_id', $customer->id);
                } else {
                    $txsQ->whereNull('customer_id')
                        ->where('customer_name', $clientName);
                }

                $txsQ->when($dateFrom, function ($q) use ($dateFrom) {
                    $q->whereDate('created_at', '>=', $dateFrom);
                })->when($dateTo, function ($q) use ($dateTo) {
                    $q->whereDate('created_at', '<=', $dateTo);
                });

                $allTxs = $txsQ->orderBy('created_at', 'asc')
                    ->orderBy('id', 'asc')
                    ->get();

                $running = 0.0;
                $fullFormatted = [];
                foreach ($allTxs as $tx) {
                    $amountPaid = (float) ($tx->amount_paid ?? $tx->selling_price);
                    $totalAmount = (float) $tx->selling_price;
                    $debt = $totalAmount - $amountPaid;

                    $running += $debt;

                    $fullFormatted[] = [
                        'id' => $tx->id,
                        'date' => $tx->created_at->format('Y-m-d H:i'),
                        'machine' => $tx->account?->name ?? '—',
                        'type' => 'عملية',
                        'is_reversal' => false,
                        'amount' => $totalAmount,
                        'employee' => $tx->employee?->name ?? '—',
                        'notes' => $tx->notes ?? '—',
                        'running_balance' => $running,
                    ];
                }

                $total = count($fullFormatted);
                $reversed = array_reverse($fullFormatted);
                $paged = array_slice($reversed, ($page - 1) * $perPage, $perPage);
                $lastPage = max(1, (int) ceil($total / $perPage));
            }

            return ApiResponse::success('Statement retrieved successfully.', [
                'transactions' => $paged,
                'running_balance' => $running,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $perPage,
                    'current_page' => $page,
                    'last_page' => $lastPage,
                ],
            ]);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
