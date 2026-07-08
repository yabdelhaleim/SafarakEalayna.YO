<?php

namespace App\Services\Flight;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Flight\AirlineTransaction;
use App\Models\Flight\FlightCarrier;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\PrepaidLedgerService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FlightCarrierRechargeService
{
    public function __construct(
        protected PrepaidLedgerService $prepaidLedgerService,
        protected LedgerClearingAccounts $ledgerClearingAccounts,
    ) {}

    /**
     * يخصم من حساب مالي (محفظة/بنك/خزينة) ويزيد رصيد ناقل الطيران،
     * مع تسجيل قيد تحويل لرصيد مسبق وحركة airline_transaction.
     *
     * ✅ Defense-in-Depth #1: locks all related rows (carrier + source + prepaid GL) in
     *    ID-ascending order to prevent SQL deadlocks (error 1213) under concurrent writes.
     *
     * ✅ Defense-in-Depth #2: retry logic for transient snapshot conflicts (error 1020) which
     *    are inevitable in REPEATABLE READ isolation when concurrent transactions touch the
     *    same row. With 3 retries and backoff (50ms, 100ms, 150ms), concurrent writes will
     *    serialize cleanly without throwing to the user.
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
        $maxAttempts = 3;
        $attempt = 0;

        while (true) {
            $attempt++;
            try {
                return $this->executeRechargeTransaction($carrier, $source, $amount, $notes);
            } catch (\PDOException $e) {
                $msg = $e->getMessage();

                // أخطاء قابلة لإعادة المحاولة:
                //  1020 — Record has changed since last read (snapshot conflict)
                //  1213 — Deadlock found (extremely unlikely with our ID-ascending locks)
                $isRetryable = str_contains($msg, '1020')
                    || str_contains($msg, 'Record has changed')
                    || str_contains($msg, '1213')
                    || str_contains($msg, 'Deadlock');

                if ($isRetryable && $attempt < $maxAttempts) {
                    Log::warning('Recharge race conflict detected, retrying', [
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'carrier_id' => $carrier->id,
                        'source_id' => $source->id,
                        'amount' => $amount,
                        'error_code' => str_contains($msg, '1020') ? '1020-snapshot' : '1213-deadlock',
                        'error_excerpt' => mb_substr($msg, 0, 200),
                    ]);
                    // backoff: 50ms, 100ms, 150ms between retries
                    usleep(50000 * $attempt);
                    continue;
                }

                throw $e;
            }
        }
    }

    /**
     * المعاملة الفعلية (مفصولة عن retry عشان تكون نظيفة).
     */
    private function executeRechargeTransaction(
        FlightCarrier $carrier,
        Account $source,
        float $amount,
        ?string $notes
    ): array {
        return DB::transaction(function () use ($carrier, $source, $amount, $notes) {
            // ─────────────────────────────────────────────────────────────
            // [1] تطبيع العملة (فشل سريع قبل أي locks)
            // ─────────────────────────────────────────────────────────────
            if (strtoupper($source->currency) !== strtoupper($carrier->currency)) {
                throw new \RuntimeException(
                    "تضارب في العملة: الحساب المصدر بعملة ({$source->currency}) ".
                    "لا يتطابق مع عملة الناقل ({$carrier->currency}). ".
                    'يرجى اختيار حساب بنفس عملة الناقل.'
                );
            }

            // ─────────────────────────────────────────────────────────────
            // [2] Defense-in-Depth: قفل كل الـ rows بالترتيب التصاعدي للـ ID
            //    (carrier, source, prepaid GL) — يمنع deadlock بشكل مطلق.
            //    داخل نفس الـ transaction، re-locks على نفس الـ row هي no-op.
            // ─────────────────────────────────────────────────────────────
            $prepaidId = $this->ledgerClearingAccounts->prepaidAccountId('flight_carrier');

            $idsToLock = [
                'carrier' => (int) $carrier->id,
                'source'  => (int) $source->id,
                'prepaid' => (int) $prepaidId,
            ];

            // ترتيب تصاعدي حسب الـ ID → نفس الترتيب في كل عملية
            asort($idsToLock);

            foreach ($idsToLock as $type => $id) {
                if ($type === 'carrier') {
                    FlightCarrier::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                } else {
                    Account::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                }
            }

            // الآن كل الـ locks محجوزة — نعيد fetch الكيانات المعرّفة فعلياً
            $carrier = FlightCarrier::query()->whereKey($carrier->id)->firstOrFail();
            $source  = Account::query()->whereKey($source->id)->firstOrFail();

            // ─────────────────────────────────────────────────────────────
            // [3] وصف العملية
            // ─────────────────────────────────────────────────────────────
            $desc = sprintf('شحن رصيد ناقل %s (%s) من حساب: %s', $carrier->name, $carrier->code, $source->name);
            if ($notes !== null && $notes !== '') {
                $desc .= ' — '.$notes;
            }

            // ─────────────────────────────────────────────────────────────
            // [4] قيد تحويل في prepaid GL (داخل نفس الـ transaction)
            // ─────────────────────────────────────────────────────────────
            $this->prepaidLedgerService->recharge(
                prepaidKey: 'flight_carrier',
                source: $source,
                amount: $amount,
                module: TransactionModule::Flight,
                notes: $desc,
                relatedType: FlightCarrier::class,
                relatedId: $carrier->id,
            );

            // ─────────────────────────────────────────────────────────────
            // [5] زيادة رصيد الناقل (عبر debit()/credit() الآمن)
            // ─────────────────────────────────────────────────────────────
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
