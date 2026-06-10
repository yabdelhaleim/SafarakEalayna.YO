<?php

namespace App\Http\Controllers\Api\V1\Fawry;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fawry\StoreFawryTransactionRequest;
use App\Http\Requests\Fawry\UpdateFawryTransactionRequest;
use App\Http\Resources\Fawry\FawryTransactionResource;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Fawry\FawryTransaction;
use App\Services\Fawry\FawryTransactionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class FawryTransactionController extends Controller
{
    public function __construct(
        protected FawryTransactionService $transactionService
    ) {}

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'operation_type',
                'payment_method',
                'employee_id',
                'from_date',
                'to_date',
                'search',
                'per_page',
            ]);

            $paginator = $this->transactionService->getAllTransactions($filters);

            return ApiResponse::paginated(
                'Fawry transactions retrieved successfully.',
                FawryTransactionResource::collection($paginator),
                $paginator
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function store(StoreFawryTransactionRequest $request): JsonResponse
    {
        try {
            $transaction = $this->transactionService->createTransaction($request->validated());

            return ApiResponse::success(
                'Fawry transaction created successfully.',
                new FawryTransactionResource($transaction),
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function show(FawryTransaction $fawryTransaction): JsonResponse
    {
        try {
            $fawryTransaction->load([
                'employee',
                'account',
                'expenseTransaction',
                'incomeTransaction',
                'operationTypeRow',
                'paymentMethodRow',
            ]);

            return ApiResponse::success(
                'Fawry transaction retrieved successfully.',
                new FawryTransactionResource($fawryTransaction)
            );
        } catch (ModelNotFoundException $e) {
            return ApiResponse::error('Fawry transaction not found.', null, 404);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function update(UpdateFawryTransactionRequest $request, FawryTransaction $fawryTransaction): JsonResponse
    {
        try {
            $transaction = $this->transactionService->updateTransaction(
                $fawryTransaction,
                $request->validated()
            );

            return ApiResponse::success(
                'Fawry transaction updated successfully.',
                new FawryTransactionResource($transaction)
            );
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function destroy(FawryTransaction $fawryTransaction): JsonResponse
    {
        try {
            $this->transactionService->deleteTransaction($fawryTransaction);

            return ApiResponse::success(
                'Fawry transaction deleted successfully.',
                null
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function dailySummary(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'date' => 'required|date_format:Y-m-d',
            ]);
        } catch (\Exception $e) {
            return ApiResponse::error('Invalid date format.', null, 422);
        }

        try {
            $summary = $this->transactionService->getDailySummary($request->date);

            return ApiResponse::success(
                'Daily summary retrieved successfully.',
                $summary
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function customerBalances(Request $request): JsonResponse
    {
        try {
            $search = $request->query('search');
            $status = $request->query('status', 'all');
            $machine = $request->query('machine', 'all');
            $dateFrom = $request->query('from_date');
            $dateTo = $request->query('to_date');

            $query = FawryTransaction::query()
                ->select([
                    'fawry_transactions.client_id',
                    'fawry_transactions.client_name',
                    DB::raw('MAX(fawry_transactions.id) as id'),
                    DB::raw('SUM(fawry_transactions.selling_price) as total_sales'),
                    DB::raw('SUM(fawry_transactions.amount) as total_paid'),
                    DB::raw('SUM(fawry_transactions.selling_price - fawry_transactions.amount) as total_debt'),
                    DB::raw('COUNT(*) as transaction_count'),
                    DB::raw('MAX(fawry_transactions.created_at) as last_transaction'),
                ])
                ->leftJoin('customers', 'fawry_transactions.client_id', '=', 'customers.id')
                ->groupBy(['fawry_transactions.client_id', 'fawry_transactions.client_name'])
                ->when($search, function ($q) use ($search) {
                    $q->where(function ($inner) use ($search) {
                        $inner->where('fawry_transactions.client_name', 'like', '%'.$search.'%')
                            ->orWhere('customers.phone', 'like', '%'.$search.'%');
                    });
                })
                ->when($machine !== 'all', function ($q) use ($machine) {
                    $q->whereHas('account', function ($inner) use ($machine) {
                        $inner->where('name', 'like', '%'.$machine.'%');
                    });
                })
                ->when($dateFrom, function ($q) use ($dateFrom) {
                    $q->whereDate('fawry_transactions.created_at', '>=', $dateFrom);
                })
                ->when($dateTo, function ($q) use ($dateTo) {
                    $q->whereDate('fawry_transactions.created_at', '<=', $dateTo);
                });

            $records = $query->get();

            // Fetch fawry ledger balance for each client using eager loaded aggregate query to prevent N+1 queries
            $clientIds = $records->pluck('client_id')->filter()->unique();
            $customerAccounts = Customer::whereIn('id', $clientIds)
                ->pluck('account_id', 'id')
                ->filter();

            $accountBalances = [];
            if ($customerAccounts->isNotEmpty()) {
                $balances = DB::table('account_entries')
                    ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
                    ->whereIn('account_entries.account_id', $customerAccounts->values())
                    ->where('transactions.module', 'fawry')
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
                $customer = $r->client_id ? Customer::find($r->client_id) : null;
                $totalSales = (float) $r->total_sales;

                if ($r->client_id && isset($accountBalances[$r->client_id])) {
                    $totalDebt = $accountBalances[$r->client_id];
                    $totalPaid = $totalSales - $totalDebt;
                } else {
                    $totalPaid = (float) $r->total_paid;
                    $totalDebt = (float) $r->total_debt;
                }

                return [
                    'client_id' => $r->client_id,
                    'client_name' => $r->client_name,
                    'phone' => $customer?->phone ?? '—',
                    'total_sales' => $totalSales,
                    'total_paid' => $totalPaid,
                    'total_debt' => $totalDebt,
                    'transaction_count' => $r->transaction_count,
                    'last_transaction' => $r->last_transaction,
                ];
            });

            // Filter by status on the dynamically calculated balance list
            if ($status === 'debtors') {
                $formatted = $formatted->filter(fn ($r) => $r['total_debt'] > 0);
            } elseif ($status === 'creditors') {
                $formatted = $formatted->filter(fn ($r) => $r['total_debt'] < 0);
            }

            return ApiResponse::success('Customer balances retrieved successfully.', $formatted->values());
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
                // Fetch fawry-related entries for this customer account
                $entries = AccountEntry::with([
                    'transaction.createdBy',
                    'transaction.fromAccount',
                    'transaction.toAccount',
                    'transaction.related',
                ])
                    ->where('account_id', $customerAccount->id)
                    ->whereHas('transaction', function ($q) {
                        $q->where('module', 'fawry');
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

                    // Credits (sale/invoice) increase the customer's debt balance
                    // Debits (repayments/receipts) decrease the customer's debt balance
                    $running += ($credit - $debit);

                    // Resolve the description/statement text
                    $description = app(\App\Services\Finance\LedgerEntryDescriptionResolver::class)->resolve($entry);

                    // Determine machine/account involved
                    $otherAccount = ($tx->from_account_id == $customerAccount->id)
                        ? $tx->toAccount
                        : $tx->fromAccount;
                    $machineName = $otherAccount?->name ?? '—';

                    // Process type label
                    // If credit > 0, it's a sale (سحب رصيد / مديونية)
                    // If debit > 0, it's a payment (سداد مديونية / إيداع)
                    $typeLabel = $credit > 0 ? 'سحب' : 'إيداع';

                    $formatted[] = [
                        'id' => $tx->id,
                        'date' => $entry->created_at->format('Y-m-d H:i'),
                        'machine' => $machineName,
                        'type' => $typeLabel,
                        'amount' => $credit > 0 ? $credit : $debit,
                        'employee' => $tx->createdBy?->name ?? '—',
                        'description' => $description,
                        'running_balance' => $running,
                    ];
                }
            } else {
                // Walk-in client fallback (no ledger account)
                $txs = FawryTransaction::query()
                    ->with(['account', 'employee'])
                    ->whereNull('client_id')
                    ->where('client_name', $clientName)
                    ->orderBy('created_at', 'asc')
                    ->get();

                $running = 0.0;
                $formatted = [];
                foreach ($txs as $tx) {
                    $amount = (float) $tx->amount;
                    $sellingPrice = (float) $tx->selling_price;
                    $debt = $sellingPrice - $amount;

                    // Running debt logic
                    $running += $debt;

                    $formatted[] = [
                        'id' => $tx->id,
                        'date' => $tx->created_at->format('Y-m-d H:i'),
                        'machine' => $tx->account?->name ?? '—',
                        'type' => 'سحب',
                        'amount' => $sellingPrice,
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
