<?php

namespace App\Services\Flight;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightSystemTransaction;
use App\Services\Finance\PrepaidLedgerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FlightSystemRechargeService
{
    public function __construct(
        protected PrepaidLedgerService $prepaidLedgerService,
    ) {}

    /**
     * يخصم من حساب مالي (محفظة/بنك/خزينة) ويزيد رصيد نظام الحجز، مع قيد تحويل لرصيد مسبق وحركة نظام.
     *
     * @return array{system: FlightSystem, source_account: Account, flight_system_transaction: FlightSystemTransaction}
     */
    public function rechargeFromAccount(
        FlightSystem $system,
        Account $source,
        float $amount,
        ?string $notes = null
    ): array {
        return DB::transaction(function () use ($system, $source, $amount, $notes) {
            $system = FlightSystem::query()->whereKey($system->id)->lockForUpdate()->firstOrFail();
            $source = Account::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();

            $desc = sprintf('شحن رصيد نظام %s من حساب: %s', $system->name, $source->name);
            if ($notes !== null && $notes !== '') {
                $desc .= ' — '.$notes;
            }

            $this->prepaidLedgerService->recharge(
                prepaidKey: 'flight_system',
                source: $source,
                amount: $amount,
                module: TransactionModule::Flight,
                notes: $desc,
                relatedType: FlightSystem::class,
                relatedId: $system->id,
            );

            $systemTx = $system->credit($amount, $desc, (int) (Auth::id() ?: 1), null);

            Log::info('Flight system recharged from account', [
                'flight_system_id' => $system->id,
                'from_account_id' => $source->id,
                'amount' => $amount,
                'flight_system_transaction_id' => $systemTx->id,
                'user_id' => Auth::id(),
            ]);

            return [
                'system' => $system->fresh(),
                'source_account' => $source->fresh(),
                'flight_system_transaction' => $systemTx,
            ];
        });
    }
}
