<?php

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Enums\AccountType;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LiquidityAccountGroups;

class TransferTreasuryController extends Controller
{
    public function overview()
    {
        // wallet_transfer is a module under the 'office' division
        // (see AccountModuleContract::OFFICE_DIVISION_MODULES).
        //
        // We use `whereIn([$specificModule, $division])` to match the
        // pattern of every other treasury controller in the project
        // (Bus/Fawry/HajjUmra/Visa/Flight). This is backward-compatible
        // with:
        //   1. Legacy rows still tagged `module_type='wallet_transfer'`
        //      (pre-Phase-3.5 data).
        //   2. Feature tests that create liquidity accounts with the
        //      specific module tag directly (see
        //      FilamentLiquidityVueApiTest::test_wallet_transfer_…).
        // The post-Phase-3.5 saving hook on Account requires liquidity
        // rows to use a division tag, so in practice the result is the
        // same as `where('module_type', $division)`.
        $division = AccountModuleContract::divisionFor('wallet_transfer') ?? AccountModuleContract::OFFICE_MODULE_TYPE;

        // NOTE: We deliberately do NOT wrap these rows in
        // AccountResource here. AccountResource hides `balance` for
        // non-admin / non-owner users, but department managers in the
        // office division need to read balances on their own treasury
        // (this is the standard behaviour across every other office
        // treasury controller: Bus, Fawry, Online, HajjUmra, Visa all
        // return plain arrays with `balance` for every caller).
        // Mirroring that behaviour here keeps wallet treasury consistent
        // with the rest of the office division.
        $accounts = Account::query()
            ->where(function ($q) use ($division) {
                $q->whereIn('module_type', ['wallet_transfer', $division])
                  ->orWhere('module', 'wallet_transfer');
            })
            ->where('is_active', true)
            ->whereIn('type', [
                AccountType::Wallet->value,
                AccountType::Bank->value,
                AccountType::Cashbox->value,
            ])
            ->orderBy('type')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'type',
                'balance',
                'currency',
                'module_type',
                'module',
                'is_active',
                'wallet_provider',
                'wallet_number',
            ]);

        $groups = LiquidityAccountGroups::group($accounts);

        return ApiResponse::success('Wallet treasury overview retrieved successfully', [
            'wallets' => $groups['wallets'],
            'banks' => $groups['banks'],
            'cashboxes' => $groups['cashboxes'],
            // 'treasury' key kept for response-shape stability but
            // intentionally empty: AccountType::Treasury was retired in
            // Phase 3.5b. The previous value (`$map(...Bank->value)`)
            // was an alias for 'banks' that caused the same accounts to
            // appear in two UI sections. See TransferTreasury.vue and
            // WalletCreate.vue for the matching cleanup.
            'treasury' => collect(),
            'accounts' => $accounts->values(),
        ]);
    }

    public function accountTransactions(\Illuminate\Http\Request $request, Account $account)
    {
        $perPage = min((int) $request->query('per_page', 20), 100);

        $transactions = Transaction::where('from_account_id', $account->id)
            ->orWhere('to_account_id', $account->id)
            ->with(['createdBy', 'fromAccount:id,name,type', 'toAccount:id,name,type'])
            ->latest()
            ->paginate($perPage);

        return ApiResponse::success('Account transactions retrieved successfully', $transactions);
    }
}
