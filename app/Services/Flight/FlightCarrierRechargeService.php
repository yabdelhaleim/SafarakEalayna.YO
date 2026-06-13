<?php

namespace App\Services\Flight;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Flight\AirlineTransaction;
use App\Models\Flight\FlightCarrier;
use App\Services\Finance\PrepaidLedgerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FlightCarrierRechargeService
{
    public function __construct(
        protected PrepaidLedgerService $prepaidLedgerService,
    ) {}

    /**
     * يخصم من حساب مالي (محفظة/بنك/خزينة) ويزيد رصيد ناقل الطيران،
     * مع تسجيل قيد تحويل لرصيد مسبق وحركة airline_transaction.
     *
     * @return array{carrier: FlightCarrier, source_account: Account, airline_transaction: AirlineTransaction}
     *
     * @throws \Exception إذا كانت عملة الحساب لا تتطابق مع عملة الناقل
     */
    public function rechargeFromAccount(
        FlightCarrier $carrier,
        Account $source,
        float $amount,
        ?string $notes = null
    ): array {
        return DB::transaction(function () use ($carrier, $source, $amount, $notes) {
            $carrier = FlightCarrier::query()->whereKey($carrier->id)->lockForUpdate()->firstOrFail();
            $source = Account::query()->whereKey($source->id)->lockForUpdate()->firstOrFail();

            // التحقق من تطابق العملة
            if (strtoupper($source->currency) !== strtoupper($carrier->currency)) {
                throw new \RuntimeException(
                    "تضارب في العملة: الحساب المصدر بعملة ({$source->currency}) ".
                    "لا يتطابق مع عملة الناقل ({$carrier->currency}). ".
                    'يرجى اختيار حساب بنفس عملة الناقل.'
                );
            }

            $desc = sprintf('شحن رصيد ناقل %s (%s) من حساب: %s', $carrier->name, $carrier->code, $source->name);
            if ($notes !== null && $notes !== '') {
                $desc .= ' — '.$notes;
            }

            $this->prepaidLedgerService->recharge(
                prepaidKey: 'flight_carrier',
                source: $source,
                amount: $amount,
                module: TransactionModule::Flight,
                notes: $desc,
                relatedType: FlightCarrier::class,
                relatedId: $carrier->id,
            );

            // زيادة رصيد الناقل وتسجيل حركة airline_transaction
            $carrierTx = $carrier->credit($amount, $desc, (int) (Auth::id() ?: 1), null);

            Log::info('Flight carrier recharged from account', [
                'flight_carrier_id' => $carrier->id,
                'flight_carrier_name' => $carrier->name,
                'from_account_id' => $source->id,
                'amount' => $amount,
                'currency' => $carrier->currency,
                'airline_transaction_id' => $carrierTx->id,
                'user_id' => Auth::id(),
            ]);

            return [
                'carrier' => $carrier->fresh(),
                'source_account' => $source->fresh(),
                'airline_transaction' => $carrierTx,
            ];
        });
    }
}
