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
            ->where('name', 'not like', '%إقفال%')
            ->where('name', 'not like', '%(نظام)%')
            ->where('name', 'not like', '%ذممة%')
            ->where('name', 'not like', '%sad%')
            ->get();

        // Calculate Statistics efficiently using aggregate query
        $performance = [];
        $moduleStats = \App\Models\Transaction::select('module', 'type', \Illuminate\Support\Facades\DB::raw('SUM(amount) as total'))
            ->groupBy('module', 'type')
            ->get();

        foreach ($moduleStats as $stat) {
            $moduleKey = ($stat->module instanceof \App\Enums\TransactionModule) ? $stat->module->value : (string)$stat->module;
            if (empty($moduleKey)) continue;

            if (!isset($performance[$moduleKey])) {
                $performance[$moduleKey] = ['income' => 0.0, 'expense' => 0.0, 'profit' => 0.0];
            }

            if ($stat->type === 'income') {
                $performance[$moduleKey]['income'] = (float) $stat->total;
            } elseif ($stat->type === 'expense') {
                $performance[$moduleKey]['expense'] = (float) $stat->total;
            }
            
            $performance[$moduleKey]['profit'] = $performance[$moduleKey]['income'] - $performance[$moduleKey]['expense'];
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

        return ApiResponse::success(__('accounts.list_success'), [
            'items' => AccountResource::collection($accounts),
            'stats' => [
                'total_balance' => (float) $allOfficeAccounts->sum('balance'),
                'active_count' => $allOfficeAccounts->where('is_active', true)->count(),
                'performance' => $performance,
                'liquidity' => $liquidity,
                'recent_transactions' => $recentTransactions,
                'deficit_accounts' => $allOfficeAccounts->where('balance', '<', 0)->values(),
            ]
        ]);
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
            'stats' => $data['stats']
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
}
