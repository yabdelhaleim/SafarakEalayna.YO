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
use Illuminate\Validation\ValidationException;

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

            if (! $machine->is_active) {
                throw ValidationException::withMessages([
                    'machine' => 'لا يمكن شحن ماكينة فوري غير مفعّلة.',
                ]);
            }

            $desc = sprintf('شحن ماكينة %s (%s) من حساب: %s', $machine->name, $machine->type, $source->name);
            if ($notes !== null && $notes !== '') {
                $desc .= ' — '.$notes;
            }

            $ledgerTransaction = $this->prepaidLedgerService->recharge(
                prepaidKey: 'fawry',
                source: $source,
                amount: $amount,
                module: TransactionModule::Fawry,
                notes: $desc,
                relatedType: FawryMachine::class,
                relatedId: $machine->id,
            );

            // The machine balance is denominated in EGP. For a foreign-currency
            // source, credit it with the amount actually posted to the prepaid
            // EGP account rather than the source-currency amount.
            $machineCreditAmount = (float) $ledgerTransaction->entries()
                ->where('account_id', $ledgerTransaction->to_account_id)
                ->value('credit');

            if ($machineCreditAmount <= 0) {
                throw new \RuntimeException('تعذر تحديد قيمة الشحن المضافة لرصيد ماكينة فوري.');
            }

            $machineTx = $machine->credit($machineCreditAmount, $desc, (int) (Auth::id() ?: 1), null);

            Log::info('Fawry machine recharged from account', [
                'fawry_machine_id' => $machine->id,
                'from_account_id' => $source->id,
                'source_amount' => $amount,
                'machine_credit_amount' => $machineCreditAmount,
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
