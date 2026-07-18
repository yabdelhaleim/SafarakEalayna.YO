<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flight\RechargeFlightSystemRequest;
use App\Models\Account;
use App\Models\Flight\AirlineTransaction;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightSystemTransaction;
use App\Models\Transaction;
use App\Services\Flight\FlightSystemRechargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FlightTreasuryController extends Controller
{
    /**
     * لوحة أرصدة الطيران: أنظمة، شركات طيران، حسابات التحصيل، وآخر حركات مالية للطيران.
     */
    public function overview(Request $request): JsonResponse
    {
        $systems = FlightSystem::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'currency', 'balance', 'credit_limit', 'is_active']);

        $carriers = FlightCarrier::query()
            ->where('is_active', true)
            ->with('system:id,name,code')
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'flight_system_id', 'currency', 'balance', 'credit_limit', 'is_active']);

        $accountTypes = [AccountType::Cashbox->value, AccountType::Wallet->value, AccountType::Bank->value];

        $accounts = Account::query()
            ->where('is_active', true)
            ->whereIn('type', $accountTypes)
            ->whereIn('module_type', ['flights', 'tourism'])
            // استثناء حسابات الإقفال والرصيد المسبق والتسوية
            // (هذه حسابات وسيطة محاسبية وليست خزائن نقدية حقيقية)
            ->where('name', 'not like', '%إقفال%')
            ->where('name', 'not like', '%(نظام)%')
            ->where('name', 'not like', '%رصيد مسبق%')
            ->where('name', 'not like', '%تسوية%')
            ->orderBy('type')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'type',
                'balance',
                'currency',
                'module_type',
                'is_active',
                'wallet_provider',
                'wallet_number',
            ]);

        $recentTransactions = Transaction::query()
            ->where('module', TransactionModule::Flight)
            ->with(['fromAccount:id,name,type', 'toAccount:id,name,type'])
            ->latest()
            ->limit(40)
            ->get(['id', 'type', 'amount', 'from_account_id', 'to_account_id', 'notes', 'related_type', 'related_id', 'created_at']);

        // ── ملخص السيولة مقسّم حسب العملة ──────────────────────────────
        // القاعدة: نجمع balance الفعلي فقط (ليس credit_limit)
        // كل عملة تُحسب على حدة حتى لا تُخلط أرقام EGP مع KWD
        $currencySummary = [];

        foreach ($systems as $sys) {
            $cur = strtoupper((string) $sys->currency);
            $currencySummary[$cur]['systems_balance'] = ($currencySummary[$cur]['systems_balance'] ?? 0) + (float) $sys->balance;
            $currencySummary[$cur]['systems_credit_limit'] = ($currencySummary[$cur]['systems_credit_limit'] ?? 0) + (float) $sys->credit_limit;
        }

        foreach ($carriers as $carrier) {
            $cur = strtoupper((string) $carrier->currency);
            $currencySummary[$cur]['carriers_balance'] = ($currencySummary[$cur]['carriers_balance'] ?? 0) + (float) $carrier->balance;
            $currencySummary[$cur]['carriers_credit_limit'] = ($currencySummary[$cur]['carriers_credit_limit'] ?? 0) + (float) $carrier->credit_limit;
        }

        foreach ($accounts as $acc) {
            $cur = strtoupper((string) ($acc->currency ?? 'EGP'));
            $currencySummary[$cur]['accounts_balance'] = ($currencySummary[$cur]['accounts_balance'] ?? 0) + (float) $acc->balance;
        }

        // حساب الإجماليات لكل عملة
        $summaryByCurrency = [];
        foreach ($currencySummary as $currency => $vals) {
            $systemsBalance = $vals['systems_balance'] ?? 0;
            $systemsCreditLimit = $vals['systems_credit_limit'] ?? 0;
            $carriersBalance = $vals['carriers_balance'] ?? 0;
            $carriersCreditLimit = $vals['carriers_credit_limit'] ?? 0;
            $accountsBalance = $vals['accounts_balance'] ?? 0;

            $summaryByCurrency[] = [
                'currency' => $currency,
                // ── الرصيد الفعلي (المال المشحون فعلاً) ──
                'systems_balance' => round($systemsBalance, 2),
                'carriers_balance' => round($carriersBalance, 2),
                'accounts_balance' => round($accountsBalance, 2),
                'total_actual' => round($systemsBalance + $carriersBalance + $accountsBalance, 2),
                // ── حدود الائتمان (ليست سيولة نقدية) ──
                'systems_credit_limit' => round($systemsCreditLimit, 2),
                'carriers_credit_limit' => round($carriersCreditLimit, 2),
                // ── المتاح للخصم (الفعلي + الائتمان) ──
                'total_available' => round(
                    $systemsBalance + $systemsCreditLimit +
                    $carriersBalance + $carriersCreditLimit +
                    $accountsBalance,
                    2
                ),
            ];
        }

        // ترتيب: EGP أولاً ثم باقي العملات
        usort($summaryByCurrency, fn ($a, $b) => $a['currency'] === 'EGP' ? -1 : ($b['currency'] === 'EGP' ? 1 : strcmp($a['currency'], $b['currency'])));

        return ApiResponse::success('Flight treasury overview', [
            'systems' => $systems,
            'carriers' => $carriers,
            'settlement_accounts' => $accounts,
            'recent_flight_transactions' => $recentTransactions,
            'liquidity_by_currency' => $summaryByCurrency,
        ]);
    }

    public function systemTransactions(Request $request, FlightSystem $system): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 30), 100);

        $paginator = FlightSystemTransaction::query()
            ->where('flight_system_id', $system->id)
            ->with(['flightBooking:id,booking_number', 'createdBy:id,name'])
            ->latest()
            ->paginate($perPage);

        return ApiResponse::success('Flight system transactions', $paginator);
    }

    public function carrierTransactions(Request $request, FlightCarrier $carrier): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 30), 100);

        $paginator = AirlineTransaction::query()
            ->where('flight_carrier_id', $carrier->id)
            ->with(['flightBooking:id,booking_number', 'createdBy:id,name'])
            ->latest()
            ->paginate($perPage);

        return ApiResponse::success('Flight carrier transactions', $paginator);
    }

    public function accountFlightTransactions(Request $request, Account $account): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 30), 100);

        $paginator = Transaction::query()
            ->where('module', TransactionModule::Flight)
            ->where(function ($q) use ($account) {
                $q->where('from_account_id', $account->id)
                    ->orWhere('to_account_id', $account->id);
            })
            ->with(['fromAccount:id,name', 'toAccount:id,name'])
            ->latest()
            ->paginate($perPage);

        return ApiResponse::success('Account flight transactions', $paginator);
    }

    /**
     * شحن رصيد نظام حجز (GDS/NDC) من حساب تحصيل (محفظة / بنك / خزينة).
     */
    public function rechargeSystem(
        RechargeFlightSystemRequest $request,
        FlightSystem $system,
        FlightSystemRechargeService $rechargeService
    ): JsonResponse {
        try {
            $validated = $request->validated();
            $source = Account::query()->findOrFail((int) $validated['from_account_id']);
            $result = $rechargeService->rechargeFromAccount(
                $system,
                $source,
                (float) $validated['amount'],
                $validated['notes'] ?? null,
            );

            $sys = $result['system'];
            $acc = $result['source_account'];
            $tx = $result['flight_system_transaction'];

            return ApiResponse::success('تم شحن رصيد نظام الحجز بنجاح.', [
                'system' => [
                    'id' => $sys->id,
                    'name' => $sys->name,
                    'code' => $sys->code,
                    'currency' => $sys->currency,
                    'balance' => (float) $sys->balance,
                    'credit_limit' => (float) $sys->credit_limit,
                    'available_balance' => $sys->available_balance,
                ],
                'source_account' => [
                    'id' => $acc->id,
                    'name' => $acc->name,
                    'type' => $acc->type instanceof \BackedEnum ? $acc->type->value : $acc->type,
                    'balance' => (float) $acc->balance,
                    'currency' => $acc->currency,
                ],
                'flight_system_transaction' => [
                    'id' => $tx->id,
                    'type' => $tx->type,
                    'amount' => (float) $tx->amount,
                    'balance_after' => (float) $tx->balance_after,
                    'description' => $tx->description,
                ],
            ]);
        } catch (\Throwable $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
