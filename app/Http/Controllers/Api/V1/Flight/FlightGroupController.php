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
            ->with('carrier:id,name,code,currency')
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
        $resolver = app(\App\Services\Finance\LedgerEntryDescriptionResolver::class);

        $transactions = $group->groupTransactions()
            ->with([
                'booking.customer',
                'booking.passengers',
                'booking.fromAirport',
                'booking.toAirport',
                'createdBy:id,name',
            ])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($tx) use ($resolver) {
                if ($tx->booking) {
                    $prefix = $tx->type === 'debt' ? 'تكلفة شراء' : 'سداد دفعة';
                    $tx->setAttribute('description', $prefix.' — '.$resolver->forFlightBooking($tx->booking));
                } else {
                    $tx->setAttribute('description', $tx->notes ?: 'حركة مجموعة طيران');
                }

                return $tx;
            });

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

                $linkedBookingId = null;
                if (! $isReceiving) {
                    $matchingDebts = $group->groupTransactions()
                        ->where('type', 'debt')
                        ->whereNotNull('flight_booking_id')
                        ->where('amount', $request->amount)
                        ->orderByDesc('created_at')
                        ->get();
                    if ($matchingDebts->count() === 1) {
                        $linkedBookingId = $matchingDebts->first()->flight_booking_id;
                    }
                }

                // 1. Create B2B group transaction record
                $transaction = FlightGroupTransaction::create([
                    'flight_group_id' => $group->id,
                    'flight_booking_id' => $linkedBookingId,
                    'type' => $isReceiving ? 'debt' : 'payment',
                    'amount' => $request->amount,
                    'notes' => $request->notes ?? ($isReceiving ? 'تحصيل دفعة من المجموعة' : 'تسديد دفعة للمجموعة'),
                    'created_by' => $userId,
                ]);

                // 2. Create actual financial transaction
                $transactionService = app(TransactionService::class);
                if ($group->account_id === null) {
                    $group->loadMissing('carrier');
                    $currency = $group->carrier?->currency ?: 'EGP';
                    $account = \App\Models\Account::create([
                        'name' => 'حساب مجموعة طيران: ' . ($group->name ?: 'غير مسمى'),
                        'type' => \App\Enums\AccountType::Supplier->value,
                        'currency' => $currency,
                        'balance' => 0.00,
                        'is_active' => true,
                        'owner_type' => \App\Models\Account::OWNER_TYPE_OWNER,
                        'module_type' => 'flights',
                        'notes' => 'حساب مجموعة تلقائي مضاف من النظام.',
                        'created_by' => $userId,
                    ]);
                    $group->account_id = $account->id;
                    $group->save();
                }

                $vaultAccountId = (int) $request->account_id;
                $groupAccountId = (int) $group->account_id;

                $fromId = $isReceiving ? $groupAccountId : $vaultAccountId;
                $toId = $isReceiving ? $vaultAccountId : $groupAccountId;

                $fromAccount = \App\Models\Account::findOrFail($fromId);
                $toAccount = \App\Models\Account::findOrFail($toId);
                $fromCurrency = strtoupper($fromAccount->currency);
                $toCurrency = strtoupper($toAccount->currency);

                $transferData = [
                    'amount' => (float) $request->amount,
                    'from_account_id' => $fromId,
                    'to_account_id' => $toId,
                    'module' => TransactionModule::Flight->value,
                    'related_type' => FlightGroupTransaction::class,
                    'related_id' => $transaction->id,
                    'notes' => $request->notes ?? ($isReceiving ? 'سند قبض - تحصيل من مجموعة طيران: '.$group->name : 'سند صرف - دفع لمجموعة طيران: '.$group->name),
                    'created_by' => $userId,
                ];

                if ($fromCurrency !== $toCurrency) {
                    $foreignCurrency = $fromCurrency === 'EGP' ? $toCurrency : $fromCurrency;
                    $rate = app(\App\Services\Finance\TreasuryService::class)->getAveragePurchaseRate($foreignCurrency);
                    if ($rate <= 0) {
                        $rate = 1.0;
                    }
                    if ($fromCurrency === 'EGP') {
                        $transferData['converted_amount'] = (float) $request->amount / $rate;
                    } else {
                        $transferData['converted_amount'] = (float) $request->amount * $rate;
                    }
                }

                $transactionService->recordTransfer($transferData);

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
