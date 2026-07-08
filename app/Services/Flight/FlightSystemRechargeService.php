<?php

namespace App\Services\Flight;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\FlightSystemTransaction;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\PrepaidLedgerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FlightSystemRechargeService
{
    public function __construct(
        protected PrepaidLedgerService $prepaidLedgerService,
        protected LedgerClearingAccounts $ledgerClearingAccounts,
    ) {}

    /**
     * يخصم من حساب مالي (محفظة/بنك/خزينة) ويزيد رصيد نظام الحجز، مع قيد تحويل لرصيد مسبق وحركة نظام.
     *
     * ✅ Defense-in-Depth #1: locks all related rows (system + source + prepaid GL) in
     *    ID-ascending order to prevent SQL deadlocks (error 1213) under concurrent writes.
     *
     * ✅ Defense-in-Depth #2: retry logic for transient snapshot conflicts (error 1020).
     *
     * @return array{system: FlightSystem, source_account: Account, flight_system_transaction: FlightSystemTransaction}
     */
    public function rechargeFromAccount(
        FlightSystem $system,
        Account $source,
        float $amount,
        ?string $notes = null
    ): array {
        $maxAttempts = 3;
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                return $this->executeRechargeTransaction($system, $source, $amount, $notes);
            } catch (\PDOException $e) {
                $msg = $e->getMessage();
                $isRetryable = str_contains($msg, '1020')
                    || str_contains($msg, 'Record has changed')
                    || str_contains($msg, '1213')
                    || str_contains($msg, 'Deadlock');

                if ($isRetryable && $attempt < $maxAttempts) {
                    Log::warning('Recharge race conflict detected, retrying', [
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'system_id' => $system->id,
                        'source_id' => $source->id,
                        'amount' => $amount,
                        'error_excerpt' => mb_substr($msg, 0, 200),
                    ]);
                    usleep(50000 * $attempt);
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * المعاملة الفعلية.
     */
    private function executeRechargeTransaction(
        FlightSystem $system,
        Account $source,
        float $amount,
        ?string $notes
    ): array {
        return DB::transaction(function () use ($system, $source, $amount, $notes) {

            // ─────────────────────────────────────────────────────────────
            // [1] Defense-in-Depth: قفل كل الـ rows بالترتيب التصاعدي للـ ID
            // ─────────────────────────────────────────────────────────────
            $prepaidId = $this->ledgerClearingAccounts->prepaidAccountId('flight_system');

            $idsToLock = [
                'system'  => (int) $system->id,
                'source'  => (int) $source->id,
                'prepaid' => (int) $prepaidId,
            ];

            asort($idsToLock);

            foreach ($idsToLock as $type => $id) {
                if ($type === 'system') {
                    FlightSystem::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                } else {
                    Account::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                }
            }

            $system = FlightSystem::query()->whereKey($system->id)->firstOrFail();
            $source = Account::query()->whereKey($source->id)->firstOrFail();

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
