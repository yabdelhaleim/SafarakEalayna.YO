<?php

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Enums\AccountType;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Resources\Finance\AccountResource;
use App\Models\Account;
use App\Models\Transaction;
use App\Support\Finance\AccountModuleContract;

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

        $accounts = Account::query()
            ->whereIn('module_type', ['wallet_transfer', $division])
            ->where('is_active', true)
            ->whereIn('type', [
                AccountType::Wallet->value,
                AccountType::Bank->value,
                AccountType::Cashbox->value,
            ])
            ->orderBy('type')
            ->orderBy('name')
            ->get()
            ->groupBy(fn ($acc) => $acc->type instanceof AccountType ? $acc->type->value : (string) $acc->type);

        $map = fn ($items) => AccountResource::collection(collect($items ?? []))->resolve();

        return ApiResponse::success('Wallet treasury overview retrieved successfully', [
            'wallets' => $map($accounts->get(AccountType::Wallet->value)),
            'banks' => $map($accounts->get(AccountType::Bank->value)),
            'cashboxes' => $map($accounts->get(AccountType::Cashbox->value)),
            // 'treasury' key kept for response-shape stability but
            // intentionally empty: AccountType::Treasury was retired in
            // Phase 3.5b. The previous value (`$map(...Bank->value)`)
            // was an alias for 'banks' that caused the same accounts to
            // appear in two UI sections. See TransferTreasury.vue and
            // WalletCreate.vue for the matching cleanup.
            'treasury' => $map(collect()),
            'accounts' => $map($accounts->flatten(1)),
        ]);
    }

    public function accountTransactions(Account $account)
    {
        $transactions = Transaction::where('from_account_id', $account->id)
            ->orWhere('to_account_id', $account->id)
            ->with(['createdBy'])
            ->latest()
            ->paginate(20);

        return ApiResponse::success('Account transactions retrieved successfully', $transactions);
    }
}
