<?php

namespace App\Http\Controllers\Api\V1\Online;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Online\StoreOnlineTransactionRequest;
use App\Http\Requests\Online\UpdateOnlineTransactionRequest;
use App\Http\Resources\Online\OnlineTransactionResource;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Online\OnlineTransaction;
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
                'service_type_id',
                'provider_id',
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

            $paginator = $this->service->getAll($filters);

            return ApiResponse::paginated(
                'تم جلب معاملات الخدمات الأونلاين بنجاح.',
                OnlineTransactionResource::collection($paginator),
                $paginator,
            );
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 500);
        }
    }

    public function store(StoreOnlineTransactionRequest $request): JsonResponse
    {
        try {
            $tx = $this->service->create($request->validated());

            return ApiResponse::success(
                'تم تنفيذ معاملة الخدمة بنجاح.',
                new OnlineTransactionResource($tx),
                201,
            );
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function show(OnlineTransaction $onlineTransaction): JsonResponse
    {
        try {
            $tx = $this->service->getById($onlineTransaction->id);

            return ApiResponse::success(
                'تم جلب المعاملة بنجاح.',
                new OnlineTransactionResource($tx),
            );
        } catch (\Throwable $e) {
            return ApiResponse::error('المعاملة غير موجودة.', null, 404);
        }
    }

    public function update(UpdateOnlineTransactionRequest $request, OnlineTransaction $onlineTransaction): JsonResponse
    {
        try {
            $tx = $this->service->update($onlineTransaction, $request->validated());

            return ApiResponse::success(
                'تم تحديث المعاملة بنجاح.',
                new OnlineTransactionResource($tx),
            );
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function destroy(OnlineTransaction $onlineTransaction): JsonResponse
    {
        try {
            $this->service->delete($onlineTransaction);

            return ApiResponse::success('تم حذف المعاملة بنجاح.');
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function dailySummary(Request $request): JsonResponse
    {
        try {
            $request->validate(['date' => 'required|date_format:Y-m-d']);
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
            $serviceTypeId = $request->query('service_type_id', 'all');
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
                ->when($serviceTypeId !== 'all', function ($q) use ($serviceTypeId) {
                    $q->where('online_transactions.service_type_id', $serviceTypeId);
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
                        $q->where('module', 'online');
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

                    $description = $tx->notes ?: 'معاملة مالية خدمات أونلاين';

                    $otherAccount = ($tx->from_account_id == $customerAccount->id)
                        ? $tx->toAccount
                        : $tx->fromAccount;
                    $accountName = $otherAccount?->name ?? '—';

                    $typeLabel = $credit > 0 ? 'عملية' : 'سداد دفعة';

                    $formatted[] = [
                        'id' => $tx->id,
                        'date' => $entry->created_at->format('Y-m-d H:i'),
                        'machine' => $accountName,
                        'type' => $typeLabel,
                        'amount' => $credit > 0 ? $credit : $debit,
                        'employee' => $tx->createdBy?->name ?? '—',
                        'description' => $description,
                        'running_balance' => $running,
                    ];
                }
            } else {
                $txs = OnlineTransaction::query()
                    ->with(['account', 'employee'])
                    ->whereNull('customer_id')
                    ->where('customer_name', $clientName)
                    ->where('status', 'completed')
                    ->orderBy('created_at', 'asc')
                    ->get();

                $running = 0.0;
                $formatted = [];
                foreach ($txs as $tx) {
                    $amountPaid = (float) ($tx->amount_paid ?? $tx->selling_price);
                    $totalAmount = (float) $tx->selling_price;
                    $debt = $totalAmount - $amountPaid;

                    $running += $debt;

                    $formatted[] = [
                        'id' => $tx->id,
                        'date' => $tx->created_at->format('Y-m-d H:i'),
                        'machine' => $tx->account?->name ?? '—',
                        'type' => 'عملية',
                        'amount' => $totalAmount,
                        'employee' => $tx->employee?->name ?? '—',
                        'notes' => $tx->notes ?? '—',
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
