<?php

namespace App\Http\Controllers\Api\V1\Visa;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\HajjUmra\VisaAgent;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisaTreasuryController extends Controller
{
    public function overview(Request $request): JsonResponse
    {
        $accountTypes = [AccountType::Cashbox->value, AccountType::Wallet->value, AccountType::Bank->value];

        $accounts = Account::query()
            ->where('is_active', true)
            ->whereIn('type', $accountTypes)
            ->whereIn('module_type', ['visas', 'tourism']) // Note: the resource uses 'visas' plural
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

        $agents = VisaAgent::query()
            ->where('is_active', true)
            ->orderBy('company_name')
            ->get(['id', 'company_name', 'contact_person', 'account_id', 'phone']);

        $recentTransactions = Transaction::query()
            ->where('module', TransactionModule::Visa)
            ->with(['fromAccount:id,name,type', 'toAccount:id,name,type'])
            ->latest()
            ->limit(40)
            ->get(['id', 'type', 'amount', 'from_account_id', 'to_account_id', 'notes', 'related_type', 'related_id', 'created_at']);

        // ── ملخص السيولة مقسّم حسب العملة ──────────────────────────────
        // Visa module: only Account entities (no FlightSystem/FlightCarrier equivalent).
        // Each currency is aggregated separately to avoid mixing EGP with KWD/SAR.
        $liquidityByCurrency = [];
        foreach ($accounts as $acc) {
            $cur = strtoupper((string) ($acc->currency ?? 'EGP'));
            $liquidityByCurrency[$cur]['accounts_balance'] = ($liquidityByCurrency[$cur]['accounts_balance'] ?? 0) + (float) $acc->balance;
        }

        $summaryByCurrency = [];
        foreach ($liquidityByCurrency as $currency => $vals) {
            $accountsBalance = $vals['accounts_balance'] ?? 0;
            $summaryByCurrency[] = [
                'currency' => $currency,
                'accounts_balance' => round($accountsBalance, 2),
                'total_actual' => round($accountsBalance, 2),
            ];
        }

        // ترتيب: EGP أولاً ثم باقي العملات
        usort($summaryByCurrency, fn ($a, $b) => $a['currency'] === 'EGP' ? -1 : ($b['currency'] === 'EGP' ? 1 : strcmp($a['currency'], $b['currency'])));

        return ApiResponse::success('Visa treasury overview', [
            'settlement_accounts' => $accounts,
            'agents' => $agents,
            'recent_visa_transactions' => $recentTransactions,
            'liquidity_by_currency' => $summaryByCurrency,
        ]);
    }

    public function accountVisaTransactions(Request $request, Account $account): JsonResponse
    {
        $perPage = min((int) $request->query('per_page', 30), 100);

        $paginator = Transaction::query()
            ->where('module', TransactionModule::Visa)
            ->where(function ($q) use ($account) {
                $q->where('from_account_id', $account->id)
                    ->orWhere('to_account_id', $account->id);
            })
            ->with(['fromAccount:id,name', 'toAccount:id,name'])
            ->latest()
            ->paginate($perPage);

        return ApiResponse::success('Account visa transactions', $paginator);
    }
}
