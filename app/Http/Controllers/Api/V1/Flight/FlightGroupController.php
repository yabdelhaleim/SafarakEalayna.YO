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
 * Update notification settings (thresholds + channels) for a group.
 *
 * Part B of the threshold-notification feature. Lets the SPA save the
 * user's preferences without touching any financial fields.
 */
public function updateNotifications(Request $request, FlightGroup $group)
{
    $data = $request->validate([
        'notification_threshold_info'    => 'nullable|numeric|min:0',
        'notification_threshold_warning' => 'nullable|numeric|min:0',
        'notification_threshold_danger'  => 'nullable|numeric|min:0',
        'notify_via_toast'               => 'boolean',
        'notify_via_widget'              => 'boolean',
        'notify_via_bell'                => 'boolean',
    ]);

    // Sanity hint: thresholds should be ordered info > warning > danger
    // (more remaining = less severe). We don't hard-fail because admins may
    // intentionally override, but we surface a warning in the response.
    $orderingWarning = null;
    $i = (float) ($data['notification_threshold_info'] ?? 0);
    $w = (float) ($data['notification_threshold_warning'] ?? 0);
    $d = (float) ($data['notification_threshold_danger'] ?? 0);
    if ($i > 0 && $w > 0 && $i <= $w) {
        $orderingWarning = 'عتبة "معلومة" يجب أن تكون أكبر من عتبة "تحذير".';
    } elseif ($w > 0 && $d > 0 && $w <= $d) {
        $orderingWarning = 'عتبة "تحذير" يجب أن تكون أكبر من عتبة "خطر".';
    }

    $group->fill([
        'notification_threshold_info'    => $data['notification_threshold_info'] ?? null,
        'notification_threshold_warning' => $data['notification_threshold_warning'] ?? null,
        'notification_threshold_danger'  => $data['notification_threshold_danger'] ?? null,
        'notify_via_toast'               => $request->boolean('notify_via_toast', true),
        'notify_via_widget'              => $request->boolean('notify_via_widget', true),
        'notify_via_bell'                => $request->boolean('notify_via_bell', true),
    ]);
    $group->save();

    return ApiResponse::success(
        $orderingWarning ?? 'تم تحديث إعدادات الإشعارات بنجاح.',
        $group->fresh()
    );
}

/**
 * Aggregate summary of group-threshold state for the dashboard widget.
 */
public function thresholdSummary(Request $request)
{
    $service = app(\App\Services\Flight\FlightGroupThresholdService::class);

    return ApiResponse::success(
        'Threshold summary retrieved',
        $service->buildSummary((int) $request->query('top', 5))
    );
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

                // 4. Part B: reset threshold tracking so that future descent triggers
                // a fresh notification after the payment improves available balance.
                $group->resetThresholdTracking();

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
