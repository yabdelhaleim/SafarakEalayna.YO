<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Enums\TransactionModule;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightGroupTransaction;
use App\Services\Finance\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class FlightGroupController extends Controller
{
    /**
     * Get groups by carrier
     */
    public function getByCarrier(Request $request, $carrierId)
    {
        $groups = FlightGroup::active()
            ->byCarrier($carrierId)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'commission_rate', 'is_active']);

        return ApiResponse::success('Flight groups retrieved successfully', $groups);
    }

    /**
     * Get all active groups
     */
    public function index(Request $request)
    {
        $groups = FlightGroup::active()
            ->with('carrier:id,name,code')
            ->withSum(['groupTransactions as total_debt' => function ($q) {
                $q->where('type', 'debt');
            }], 'amount')
            ->withSum(['groupTransactions as total_payment' => function ($q) {
                $q->where('type', 'payment');
            }], 'amount')
            ->orderBy('name')
            ->get()
            ->map(function ($group) {
                $group->balance = ($group->total_debt ?? 0) - ($group->total_payment ?? 0);

                return $group;
            });

        return ApiResponse::success('Flight groups retrieved successfully', $groups);
    }

    /**
     * Get single group
     */
    public function show(FlightGroup $group)
    {
        $group->load('carrier');

        $totalDebt = $group->groupTransactions()->where('type', 'debt')->sum('amount');
        $totalPayment = $group->groupTransactions()->where('type', 'payment')->sum('amount');
        $group->balance = $totalDebt - $totalPayment;

        return ApiResponse::success('Flight group retrieved successfully', $group);
    }

    /**
     * Get a group's statement (history of all transactions)
     */
    public function statement(Request $request, FlightGroup $group)
    {
        $transactions = $group->groupTransactions()
            ->with(['booking:id,booking_number,pnr,airline_name', 'createdBy:id,name'])
            ->orderBy('created_at', 'desc')
            ->get();

        $totalDebt = $group->groupTransactions()->where('type', 'debt')->sum('amount');
        $totalPayment = $group->groupTransactions()->where('type', 'payment')->sum('amount');
        $balance = $totalDebt - $totalPayment;

        return ApiResponse::success('Flight group statement retrieved successfully', [
            'group' => $group->load('carrier'),
            'transactions' => $transactions,
            'summary' => [
                'total_debt' => $totalDebt,
                'total_payment' => $totalPayment,
                'balance' => $balance,
            ],
        ]);
    }

    /**
     * Record a payment (reducing outstanding debt) for a group
     */
    public function payDebt(Request $request, FlightGroup $group)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'notes' => 'nullable|string',
            'account_id' => 'required|exists:accounts,id',
            'type' => 'nullable|string|in:payment,debt',
        ]);

        $userId = Auth::id() ?: 1;

        try {
            return DB::transaction(function () use ($group, $request, $userId) {
                // Calculate current B2B balance
                $totalDebt = $group->groupTransactions()->where('type', 'debt')->sum('amount');
                $totalPayment = $group->groupTransactions()->where('type', 'payment')->sum('amount');
                $currentBalance = $totalDebt - $totalPayment;

                $reqType = $request->type;
                if ($reqType === 'payment') {
                    if ($currentBalance < 0) {
                        return ApiResponse::error('رصيد المجموعة سالب — استخدم سند قبض وليس سند صرف.', null, 422);
                    }
                    $isReceiving = false;
                } elseif ($reqType === 'debt') {
                    if ($currentBalance > 0) {
                        return ApiResponse::error('رصيد المجموعة مستحق لهم — استخدم سند صرف وليس سند قبض.', null, 422);
                    }
                    $isReceiving = true;
                } else {
                    $isReceiving = $currentBalance < 0;
                }

                if (abs($currentBalance) < 0.00001) {
                    return ApiResponse::error('لا يوجد رصيد مستحق على هذه المجموعة.', null, 422);
                }

                // 1. Create B2B group transaction record
                $transaction = FlightGroupTransaction::create([
                    'flight_group_id' => $group->id,
                    'flight_booking_id' => null,
                    'type' => $isReceiving ? 'debt' : 'payment',
                    'amount' => $request->amount,
                    'notes' => $request->notes ?? ($isReceiving ? 'تحصيل دفعة من المجموعة' : 'تسديد دفعة للمجموعة'),
                    'created_by' => $userId,
                ]);

                // 2. Create actual financial transaction
                $transactionService = app(TransactionService::class);
                if ($isReceiving) {
                    // Record a deposit/income to increase the selected account's balance
                    $transactionService->recordIncome([
                        'amount' => (float) $request->amount,
                        'to_account_id' => (int) $request->account_id,
                        'module' => TransactionModule::Flight->value,
                        'related_type' => FlightGroupTransaction::class,
                        'related_id' => $transaction->id,
                        'notes' => $request->notes ?? ('سند قبض - تحصيل من مجموعة طيران: '.$group->name),
                        'created_by' => $userId,
                    ]);
                } else {
                    // Record an expense to deduct from the selected account's balance
                    $transactionService->recordExpense([
                        'amount' => (float) $request->amount,
                        'from_account_id' => (int) $request->account_id,
                        'module' => TransactionModule::Flight->value,
                        'related_type' => FlightGroupTransaction::class,
                        'related_id' => $transaction->id,
                        'notes' => $request->notes ?? ('سند صرف - دفع لمجموعة طيران: '.$group->name),
                        'created_by' => $userId,
                    ]);
                }

                // 3. Recalculate balance
                $totalDebt = $group->groupTransactions()->where('type', 'debt')->sum('amount');
                $totalPayment = $group->groupTransactions()->where('type', 'payment')->sum('amount');
                $newBalance = $totalDebt - $totalPayment;

                return ApiResponse::success(
                    $isReceiving ? 'تم تسجيل سند القبض وتحصيل الدفعة بنجاح.' : 'تم تسجيل سند الصرف وتأكيد السداد بنجاح.',
                    [
                        'transaction_id' => $transaction->id,
                        'new_balance' => $newBalance,
                    ]
                );
            });
        } catch (\Exception $e) {
            return ApiResponse::error('فشل تسجيل السداد: '.$e->getMessage(), null, 422);
        }
    }
}
