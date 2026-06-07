<?php

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Wallet\StoreWalletTransactionRequest;
use App\Http\Requests\Wallet\UpdateWalletTransactionRequest;
use App\Http\Resources\Wallet\WalletTransactionResource;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Wallet\WalletTransaction;
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
            $transaction = $this->service->createTransaction($request->validated());

            return ApiResponse::success(
                'Wallet transaction created successfully.',
                new WalletTransactionResource($transaction),
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function show(WalletTransaction $transaction): JsonResponse
    {
        $transaction->load([
            'walletType', 'customer', 'walletAccount', 'cashAccount',
            'employee', 'createdBy', 'incomeTransaction', 'expenseTransaction',
        ]);

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
            $customerAccounts = Customer::whereIn('id', $clientIds)
                ->pluck('account_id', 'id')
                ->filter();

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

            $customer = $clientId ? Customer::find($clientId) : null;
            $customerAccount = $customer?->account_id ? Account::find($customer->account_id) : null;

            if ($customerAccount) {
                $entries = AccountEntry::with([
                    'transaction.createdBy',
                    'transaction.fromAccount',
                    'transaction.toAccount',
                ])
                    ->where('account_id', $customerAccount->id)
                    ->whereHas('transaction', function ($q) {
                        $q->where('module', 'wallet');
                    })
                    ->orderBy('created_at', 'asc')
                    ->get();

                $running = 0.0;
                $formatted = [];

                foreach ($entries as $entry) {
                    $tx = $entry->transaction;
                    if (! $tx) {
                        continue;
                    }

                    $debit = (float) $entry->debit;
                    $credit = (float) $entry->credit;

                    $running += ($credit - $debit);

                    $description = $tx->notes ?: 'معاملة مالية محفظة';

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
                // Walk-in client fallback
                $txs = WalletTransaction::query()
                    ->with(['walletAccount', 'employee'])
                    ->whereNull('customer_id')
                    ->where('customer_name', $clientName)
                    ->orderBy('created_at', 'asc')
                    ->get();

                $running = 0.0;
                $formatted = [];
                foreach ($txs as $tx) {
                    $amountPaid = (float) $tx->amount_paid;
                    $totalAmount = (float) $tx->total_amount;
                    $debt = $totalAmount - $amountPaid;

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
