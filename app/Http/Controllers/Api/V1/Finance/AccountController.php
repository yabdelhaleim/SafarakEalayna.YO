<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreAccountRequest;
use App\Http\Requests\Finance\StoreTransferRequest;
use App\Http\Requests\Finance\UpdateAccountRequest;
use App\Http\Resources\Finance\AccountEntryResource;
use App\Http\Resources\Finance\AccountResource;
use App\Http\Resources\Finance\TransferHistoryResource;
use App\Http\Resources\Finance\TransferResource;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\Finance\AccountService;
use App\Services\Finance\TransactionService;
use App\Services\Reports\ProfitLossReportService;
use App\Services\Reports\ReportFinanceService;
use App\Support\Finance\AccountModuleDivision;
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
                'expense' => (float) $row['expense'],
                'profit' => (float) $row['profit'],
            ];
        }

        $liquidity = [
            'cashbox' => (float) $liquidityAccounts->where('type', AccountType::Cashbox)->sum('balance'),
            'bank' => (float) $liquidityAccounts->where('type', AccountType::Bank)->sum('balance'),
            'wallet' => (float) $liquidityAccounts->where('type', AccountType::Wallet)->sum('balance'),
            'treasury' => (float) $liquidityAccounts->where('type', AccountType::Treasury)->sum('balance'),
            'post' => (float) $liquidityAccounts->where('type', AccountType::Post)->sum('balance'),
        ];

        $reportFinance = app(ReportFinanceService::class);
        $recentTransactions = Transaction::query()
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
            });

        $data = [
            'items' => AccountResource::collection($paginator->items()),
            'pagination' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ];

        if ($request->user() && ($request->user()->role === 'admin' || $request->user()->role === 'owner')) {
            $data['stats'] = [
                'total_balance' => (float) $liquidityAccounts->sum('balance'),
                'active_count' => $liquidityAccounts->where('is_active', true)->count(),
                'tourism_count' => $liquidityAccounts->whereIn('module_type', AccountModuleDivision::TOURISM)->count(),
                'office_count' => $liquidityAccounts->whereIn('module_type', AccountModuleDivision::OFFICE)->count(),
                'performance' => $performance,
                'liquidity' => $liquidity,
                'recent_transactions' => $recentTransactions,
                'deficit_accounts' => $liquidityAccounts->where('balance', '<', 0)->map(fn (Account $a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'balance' => (float) $a->balance,
                    'currency' => $a->currency,
                ])->values(),
            ];
        }

        return ApiResponse::success(__('accounts.list_success'), $data)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = $this->accountService->createAccount($request->validated());

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

        return ApiResponse::success('Account updated successfully.', new AccountResource($account), 200);
    }

    public function deactivate(Account $account): JsonResponse
    {
        $this->accountService->deactivateAccount($account);

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

            $transfer = $this->transactionService->recordTransfer($data);

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
        $query = Transaction::with(['createdBy', 'fromAccount', 'toAccount'])
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
}
