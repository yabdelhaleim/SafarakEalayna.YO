<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Helpers\ApiResponse;
use App\Helpers\CacheHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreAccountRequest;
use App\Http\Requests\Finance\StoreTransferRequest;
use App\Http\Requests\Finance\UpdateAccountRequest;
use App\Http\Resources\Finance\AccountEntryResource;
use App\Http\Resources\Finance\AccountResource;
use App\Http\Resources\Finance\TransferHistoryResource;
use App\Http\Resources\Finance\TransferResource;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Fawry\FawryTransaction;
use App\Models\Flight\FlightBooking;
use App\Models\HajjUmraBooking;
use App\Models\Online\OnlineTransaction;
use App\Models\Transaction;
use App\Models\VisaBooking;
use App\Models\Wallet\WalletTransaction;
use App\Services\Finance\AccountService;
use App\Services\Finance\TransactionService;
use App\Services\Finance\TreasuryService;
use App\Services\Reports\ProfitLossReportService;
use App\Services\Reports\ReportFinanceService;
use App\Support\Finance\AccountModuleDivision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function __construct(
        protected AccountService $accountService,
        protected TransactionService $transactionService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $params = $request->all();
        $params['page'] = $request->get('page', 1);
        $userRole = $request->user()?->role ?? 'guest';
        $cacheKey = 'accounts_list_'.$userRole.'_'.md5(serialize($params));

        // Cache TTL reduced 60s → 30s so financial listings update faster
        // for end users. Combined with explicit `flushNamespace()` on writes
        // (store/update/deactivate/transfer) this keeps listings ~real-time.
        $data = CacheHelper::tags(['accounts'])->remember($cacheKey, 30, function () use ($request) {
            $paginator = $this->accountService->getAllAccounts($request->all());

            $baseQuery = $this->accountService->buildAccountsQuery($request->all());

            $liquidityAccounts = (clone $baseQuery)
                ->whereIn('type', AccountModuleDivision::LIQUIDITY_TYPES)
                ->get();

            $performance = [];
            $moduleBreakdown = app(ProfitLossReportService::class)->moduleBreakdown([
                'from_date' => now()->startOfMonth()->toDateString(),
                'to_date' => now()->toDateString(),
            ]);
            foreach ($moduleBreakdown['by_module'] as $row) {
                $performance[$row['module']] = [
                    'income' => (float) $row['income'],
                    'cogs' => (float) ($row['cogs'] ?? 0),
                    'expense' => (float) $row['expense'],
                    'profit' => (float) $row['profit'],
                ];
            }

            $treasuryService = app(TreasuryService::class);
            $liquidity = [
                'cashbox' => (float) $liquidityAccounts->where('type', AccountType::Cashbox)->sum(fn ($a) => (float) $a->balance * $treasuryService->getAveragePurchaseRate($a->currency ?: 'EGP')),
                'bank' => (float) $liquidityAccounts->where('type', AccountType::Bank)->sum(fn ($a) => (float) $a->balance * $treasuryService->getAveragePurchaseRate($a->currency ?: 'EGP')),
                'wallet' => (float) $liquidityAccounts->where('type', AccountType::Wallet)->sum(fn ($a) => (float) $a->balance * $treasuryService->getAveragePurchaseRate($a->currency ?: 'EGP')),
                // 'treasury' and 'post' removed in Phase 3.5b cleanup:
                // their AccountType enum cases are gone from the DB schema.
            ];

            $reportFinance = app(ReportFinanceService::class);
            $recentTransactions = Transaction::query()
                // Hide transactions linked to soft-deleted operations so the
                // finance dashboard does not surface "عكس: تحصيل فوري" /
                // "عكس: تكلفة عملية فوري" rows after the operator cancels
                // a Fawry / Online / Wallet / booking. The audit trail
                // remains in `transactions` for accountants — it is only
                // excluded from the dashboard view.
                ->where(function ($q) {
                    $this->excludeSoftDeletedOperations($q);
                })
                ->with(['createdBy', 'fromAccount:id,type', 'toAccount:id,type'])
                ->latest()
                ->take(10)
                ->get()
                ->map(function ($t) use ($reportFinance) {
                    $type = $t->type instanceof TransactionType ? $t->type->value : (string) $t->type;
                    $module = $t->module instanceof TransactionModule ? $t->module->value : (string) $t->module;
                    $fromType = $t->fromAccount?->type;
                    $toType = $t->toAccount?->type;

                    return $reportFinance->enrichLedgerRowArray((object) [
                        'id' => $t->id,
                        'type' => $type,
                        'amount' => (float) $t->amount,
                        'module' => $module,
                        'notes' => $t->notes,
                        'created_at' => $t->created_at->toDateTimeString(),
                        'created_by_name' => $t->createdBy?->name,
                        'from_account_id' => $t->from_account_id,
                        'to_account_id' => $t->to_account_id,
                        'from_account_type' => $fromType instanceof AccountType ? $fromType->value : (string) ($fromType ?? ''),
                        'to_account_type' => $toType instanceof AccountType ? $toType->value : (string) ($toType ?? ''),
                    ]);
                })->toArray();

            $itemsArray = AccountResource::collection($paginator->items())->toArray($request);

            $resData = [
                'items' => $itemsArray,
                'pagination' => [
                    'total' => $paginator->total(),
                    'per_page' => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'has_more' => $paginator->hasMorePages(),
                ],
            ];

            if ($request->user() && ($request->user()->role === 'admin' || $request->user()->role === 'owner')) {
                // Only show "deficit" alerts whose negative balance is
                // attributable to an ACTIVE (non-soft-deleted) operation.
                // A balance that is negative only because of soft-deleted
                // Fawry / Online / Wallet / booking operations is the
                // expected post-cancellation state — it should not be
                // surfaced as an outstanding deficit in the UI.
                $deficitAccountIds = $this->deficitAccountIdsFromActiveOperations(
                    $liquidityAccounts->pluck('id')->all()
                );

                $resData['stats'] = [
                    'total_balance' => (float) $liquidityAccounts->sum(fn ($a) => (float) $a->balance * $treasuryService->getAveragePurchaseRate($a->currency ?: 'EGP')),
                    'active_count' => $liquidityAccounts->where('is_active', true)->count(),
                    'tourism_count' => $liquidityAccounts->whereIn('module_type', AccountModuleDivision::TOURISM)->count(),
                    'office_count' => $liquidityAccounts->whereIn('module_type', AccountModuleDivision::OFFICE)->count(),
                    'performance' => $performance,
                    'liquidity' => $liquidity,
                    'recent_transactions' => $recentTransactions,
                    'deficit_accounts' => $liquidityAccounts
                        ->whereIn('id', $deficitAccountIds)
                        ->map(fn (Account $a) => [
                            'id' => $a->id,
                            'name' => $a->name,
                            'balance' => (float) $a->balance,
                            'currency' => $a->currency,
                        ])->values()->toArray(),
                ];
            }

            return $resData;
        });

        return ApiResponse::success(__('accounts.list_success'), $data)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = $this->accountService->createAccount($request->validated());
        // Invalidate any cached account listings immediately so the new
        // account shows up without waiting for the TTL to expire.
        CacheHelper::flushTags(['accounts']);

        return ApiResponse::success('Account created successfully.', new AccountResource($account), 201);
    }

    public function show(Account $account): JsonResponse
    {
        // Account model doesn't have additional relations to load

        return ApiResponse::success('Account retrieved successfully.', new AccountResource($account), 200);
    }

    public function update(UpdateAccountRequest $request, Account $account): JsonResponse
    {
        $account = $this->accountService->updateAccount($account, $request->validated());
        CacheHelper::flushTags(['accounts']);

        return ApiResponse::success('Account updated successfully.', new AccountResource($account), 200);
    }

    public function deactivate(Account $account): JsonResponse
    {
        $this->accountService->deactivateAccount($account);
        CacheHelper::flushTags(['accounts']);

        return ApiResponse::success('Account deactivated successfully.', null, 200);
    }

    public function statement(Request $request, Account $account): JsonResponse
    {
        $data = $this->accountService->getAccountStatement($account, $request->all());

        return ApiResponse::success('Account statement retrieved.', [
            'items' => AccountEntryResource::collection($data['items']),
            'pagination' => $data['pagination'],
            'stats' => $data['stats'],
        ]);
    }

    public function transfer(StoreTransferRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            if ($request->hasFile('attachment')) {
                $data['attachment_path'] = $request->file('attachment')->store('transactions/attachments', 'public');
            }

            if (empty($data['to_account_id']) && ! empty($data['to_account_name'])) {
                $fromAccount = Account::findOrFail($data['from_account_id']);
                $module = $data['module'] ?? 'general';
                $moduleType = AccountModuleDivision::resolveModuleTypeKey(null, $module);

                $toAccount = Account::where('type', AccountType::Expense->value)
                    ->where('name', trim($data['to_account_name']))
                    ->where('module_type', $moduleType)
                    ->first();

                if (! $toAccount) {
                    $toAccount = Account::create([
                        'name' => trim($data['to_account_name']),
                        'type' => AccountType::Expense->value,
                        'currency' => $fromAccount->currency,
                        'balance' => 0.00,
                        'is_active' => true,
                        'module_type' => $moduleType,
                        'module' => $module,
                        'owner_type' => Account::OWNER_TYPE_OWNER,
                        'notes' => 'حساب مصروف مضاف تلقائياً عند تسجيل المصروف.',
                        'created_by' => auth()->id() ?? 1,
                    ]);
                }

                $data['to_account_id'] = $toAccount->id;
            }

            $transfer = $this->transactionService->recordTransfer($data);

            // Transfer mutates two accounts' balances — flush the namespace
            // so any cached account listings reflect the new balances
            // immediately on the next request.
            CacheHelper::flushTags(['accounts']);

            return ApiResponse::success('Transfer completed successfully.', new TransferResource($transfer), 201);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * List transfer-type transactions with pagination and filters.
     */
    public function transferHistory(Request $request): JsonResponse
    {
        $query = Transaction::with(['createdBy', 'fromAccount', 'toAccount', 'transfer'])
            ->where('type', TransactionType::Transfer->value)
            ->whereNotNull('from_account_id')
            ->whereNotNull('to_account_id')
            ->latest();

        if ($request->filled('from_date')) {
            $query->whereDate('created_at', '>=', $request->from_date);
        }
        if ($request->filled('to_date')) {
            $query->whereDate('created_at', '<=', $request->to_date);
        }
        if ($request->filled('from_account_id')) {
            $query->where('from_account_id', $request->from_account_id);
        }
        if ($request->filled('to_account_id')) {
            $query->where('to_account_id', $request->to_account_id);
        }

        $perPage = min(max((int) $request->get('per_page', 20), 1), 100);

        $summaryQuery = clone $query;
        $summary = [
            'total_amount' => (float) $summaryQuery->sum('amount'),
            'today_count' => (int) (clone $query)->whereDate('created_at', now()->toDateString())->count(),
        ];

        $paginated = $query->paginate($perPage)->withQueryString();

        return ApiResponse::success('Transfer history retrieved.', [
            'items' => TransferHistoryResource::collection($paginated->items()),
            'pagination' => [
                'total' => $paginated->total(),
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
            ],
            'summary' => $summary,
        ]);
    }

    /**
     * Soft-deletable related models whose reversed transactions must NOT
     * appear in finance dashboard widgets (recent activity, deficit alert,
     * performance breakdown). Mirrors the exclusion table in
     * {@see ProfitLossReportService::applySoftDeleteExclusion()}.
     *
     * @return array<int, array{0: class-string, 1: string}>
     */
    private function softDeletableRelatedModels(): array
    {
        return [
            [FawryTransaction::class, 'fawry_transactions'],
            [OnlineTransaction::class, 'online_transactions'],
            [WalletTransaction::class, 'wallet_transactions'],
            [BusBooking::class, 'bus_bookings'],
            [FlightBooking::class, 'flight_bookings'],
            [HajjUmraBooking::class, 'hajj_umra_bookings'],
            [VisaBooking::class, 'visa_bookings'],
        ];
    }

    /**
     * Apply the soft-delete exclusion to an Eloquent transaction query.
     * Used by the recent-activity widget so cancelled Fawry / Online /
     * Wallet / booking operations do not surface in the dashboard list.
     *
     * Each `whereNotExists` is AND-chained so a row is only kept when
     * NONE of the soft-deletable related tables have a matching deleted
     * record. Using `orWhereNotExists` would be a logic bug — a row
     * whose fawry_transactions counterpart is soft-deleted would still
     * be surfaced because the bus_bookings branch would be true.
     */
    private function excludeSoftDeletedOperations(Builder $q): void
    {
        foreach ($this->softDeletableRelatedModels() as [$class, $table]) {
            $q->whereNotExists(function ($sub) use ($class, $table) {
                $sub->select(\DB::raw(1))
                    ->from($table)
                    ->whereColumn("$table.id", '=', 'transactions.related_id')
                    ->where('transactions.related_type', '=', $class)
                    ->whereNotNull("$table.deleted_at");
            });
        }
    }

    /**
     * Return account IDs whose negative stored balance is NOT fully
     * explained by soft-deleted operations. Used to populate
     * `deficit_accounts` so the dashboard only warns about deficits
     * caused by active operations.
     *
     * @param  array<int, int>  $liquidityAccountIds
     * @return array<int, int>
     */
    private function deficitAccountIdsFromActiveOperations(array $liquidityAccountIds): array
    {
        if ($liquidityAccountIds === []) {
            return [];
        }

        // Map each liquidity account to its "active" balance =
        // SUM(credit - debit) for entries whose transaction is either:
        //   - NOT linked to a soft-deletable related model, OR
        //   - linked to one whose row is still active.
        $rows = \DB::table('accounts as a')
            ->leftJoin('account_entries as ae', 'ae.account_id', '=', 'a.id')
            ->leftJoin('transactions as t', 't.id', '=', 'ae.transaction_id')
            ->whereIn('a.id', $liquidityAccountIds)
            ->where('a.balance', '<', 0)
            ->where(function ($q) {
                $q->whereNull('t.id')
                    ->orWhere(function ($active) {
                        foreach ($this->softDeletableRelatedModels() as [$class, $table]) {
                            $active->whereNotExists(function ($sub) use ($class, $table) {
                                $sub->select(\DB::raw(1))
                                    ->from($table)
                                    ->whereColumn("$table.id", '=', 't.related_id')
                                    ->where('t.related_type', '=', $class)
                                    ->whereNotNull("$table.deleted_at");
                            });
                        }
                    });
            })
            ->groupBy('a.id')
            ->select('a.id', \DB::raw('COALESCE(SUM(ae.credit - ae.debit), 0) as active_net'))
            ->get();

        return $rows
            ->filter(fn ($r) => ((float) $r->active_net) < 0.0)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
