<?php

namespace App\Services\Fawry;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryMachineTransaction;
use App\Services\Finance\TransactionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FawryMachineRechargeService
{
    public function __construct(
        protected TransactionService $transactionService
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

            // تسجيل قيد مالي بالخصم من حساب التمويل
            $this->transactionService->recordExpense([
                'amount' => $amount,
                'from_account_id' => $source->id,
                'module' => TransactionModule::Fawry->value,
                'related_type' => FawryMachine::class,
                'related_id' => $machine->id,
                'notes' => $desc,
            ]);

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
