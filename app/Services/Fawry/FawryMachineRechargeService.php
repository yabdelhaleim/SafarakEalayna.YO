<?php

namespace App\Services\Fawry;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryMachineTransaction;
use App\Services\Finance\PrepaidLedgerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FawryMachineRechargeService
{
    public function __construct(
        protected PrepaidLedgerService $prepaidLedgerService,
    ) {}

    /**
     * يخصم من حساب مالي فوري (محفظة/بنك/خزينة) ويزيد رصيد ماكينة الشحن.
     *
     * @return array{machine: FawryMachine, source_account: Account, machine_transaction: FawryMachineTransaction}
     */
    public function rechargeFromAccount(
        FawryMachine $machine,
        Account $source,
        float $amount,
        ?string $notes = null
    ): array {
        return DB::transaction(function () use ($machine, $source, $amount, $notes) {
            $machine = FawryMachine::query()->whereKey($machine->id)->lockForUpdate()->firstOrFail();
            $source = Account::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();

            $desc = sprintf('شحن ماكينة %s (%s) من حساب: %s', $machine->name, $machine->type, $source->name);
            if ($notes !== null && $notes !== '') {
                $desc .= ' — '.$notes;
            }

            $this->prepaidLedgerService->recharge(
                prepaidKey: 'fawry',
                source: $source,
                amount: $amount,
                module: TransactionModule::Fawry,
                notes: $desc,
                relatedType: FawryMachine::class,
                relatedId: $machine->id,
            );

            // زيادة رصيد الماكينة
            $machineTx = $machine->credit($amount, $desc, (int) (Auth::id() ?: 1), null);

            Log::info('Fawry machine recharged from account', [
                'fawry_machine_id' => $machine->id,
                'from_account_id' => $source->id,
                'amount' => $amount,
                'fawry_machine_transaction_id' => $machineTx->id,
                'user_id' => Auth::id(),
            ]);

            return [
                'machine' => $machine->fresh(),
                'source_account' => $source->fresh(),
                'machine_transaction' => $machineTx,
            ];
        });
    }
}
