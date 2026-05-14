<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Finance\StoreAccountRequest;
use App\Http\Requests\Finance\StoreTransferRequest;
use App\Http\Requests\Finance\UpdateAccountRequest;
use App\Http\Resources\Finance\AccountEntryResource;
use App\Http\Resources\Finance\AccountResource;
use App\Http\Resources\Finance\TransferResource;
use App\Models\Account;
use App\Services\Finance\AccountService;
use App\Services\Finance\TransactionService;
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
        // Execute the service to get accounts and totals
        $accounts = $this->accountService->getAllAccounts($request->all());
        
        // Calculate Statistics directly from the retrieved office accounts
        $allOfficeAccounts = \App\Models\Account::whereIn('owner_type', ['office', 'owner'])
            ->where('name', 'not like', '%عميل%')
            ->where('name', 'not like', '%شركة%')
            ->where('name', 'not like', '%مورد%')
            ->where('name', 'not like', '%ذممة%')
            ->where('name', 'not like', '%sad%')
            ->get();

        $performance = [];
        $activeModules = \App\Models\Transaction::select('module')->distinct()->pluck('module');
        foreach ($activeModules as $m) {
            $moduleKey = ($m instanceof \App\Enums\TransactionModule) ? $m->value : (string)$m;
            if (empty($moduleKey)) continue;
            $income = \App\Models\Transaction::where('module', $moduleKey)->where('type', 'income')->sum('amount');
            $expense = \App\Models\Transaction::where('module', $moduleKey)->where('type', 'expense')->sum('amount');
            $performance[$moduleKey] = [
                'income' => (float) $income,
                'expense' => (float) $expense,
                'profit' => (float) ($income - $expense),
            ];
        }

        $liquidity = [
            'cashbox' => (float) $allOfficeAccounts->where('type', 'cashbox')->sum('balance'),
            'bank' => (float) $allOfficeAccounts->where('type', 'bank')->sum('balance'),
            'wallet' => (float) $allOfficeAccounts->where('type', 'wallet')->sum('balance'),
            'treasury' => (float) $allOfficeAccounts->where('type', 'treasury')->sum('balance'),
        ];

        $recentTransactions = \App\Models\Transaction::with('createdBy')->latest()->take(10)->get()->map(fn($t) => [
            'id' => $t->id,
            'type' => $t->type,
            'amount' => (float) $t->amount,
            'module' => $t->module,
            'notes' => $t->notes,
            'created_at' => $t->created_at->toDateTimeString(),
            'created_by_name' => $t->createdBy?->name,
        ]);

        return response()->json([
            'status' => true,
            'message' => __('accounts.list_success'),
            'data' => AccountResource::collection($accounts),
            'stats' => [
                'total_balance' => (float) $allOfficeAccounts->sum('balance'),
                'active_count' => $allOfficeAccounts->where('is_active', true)->count(),
                'performance' => $performance,
                'liquidity' => $liquidity,
                'recent_transactions' => $recentTransactions,
                'deficit_accounts' => $allOfficeAccounts->where('balance', '<', 0)->values(),
            ]
        ], 200);
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

        return response()->json([
            'status' => true,
            'message' => 'Account statement retrieved.',
            'data' => [
                'items' => AccountEntryResource::collection($data['items']),
                'pagination' => $data['pagination'],
                'stats' => $data['stats']
            ]
        ], 200);
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
}
