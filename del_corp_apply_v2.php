<?php
/**
 * APPLY V2: حذف آمن لـ FLT-20260706-97F3BD بـ MANUAL GL REVERSAL
 *
 * ⚠️ ليه V2؟ بيانات الحجز فيها تناقضات:
 *    - selling_price = 1,265,550 KWD (العملة غلط — الرقم ده EGP فعلياً)
 *    - purchase_price_foreign = 45 KWD  ← لكن الـ GL اتحرّك 7,425 KWD (100x فرق)
 *    - cancelBooking() هيحسب رجوع الناقل = 7,425 ÷ 157.5 ≈ 47 KWD (غلط!)
 *
 *    V2 بتتجاهل cancelBooking() وبتعمل MANUAL reversal للـ GL بالظبط
 *    من AccountEntry.balance_after.
 *
 * الاستخدام:
 *   1) شغّل del_corp_diag.php أولاً وراجع الأرقام
 *   2) أنشئ ملف التأكيد: touch /tmp/del_corp_97F3BD.confirmed
 *   3) شغّل: php artisan tinker --execute='require "/tmp/del_corp_apply_v2.php";'
 */

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Flight\AirlineTransaction;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightPassenger;
use App\Models\Flight\FlightSegment;
use App\Models\Flight\FlightTicket;
use App\Models\Transaction;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;

$bookingRef = 'FLT-20260706-97F3BD';
$confirmFile = '/tmp/del_corp_97F3BD.confirmed';

echo "\n=========================================================\n";
echo "  APPLY V2: حذف الحجز {$bookingRef} (manual GL reversal)\n";
echo "=========================================================\n";

// [0] بوابة الأمان
if (! file_exists($confirmFile)) {
    echo "\n✗ ملف التأكيد غير موجود: {$confirmFile}\n";
    echo "  شغّل: touch {$confirmFile}\n";
    exit(10);
}
echo "  ✓ ملف التأكيد موجود\n";

// [1] البحث عن الحجز
$booking = FlightBooking::withTrashed()
    ->with(['customer.ledgerAccount', 'flightCarrier'])
    ->where(function ($q) use ($bookingRef) {
        $q->where('booking_reference', $bookingRef)
            ->orWhere('booking_number', $bookingRef)
            ->orWhere('pnr', $bookingRef);
    })
    ->first();

if (! $booking) { echo "\n✗ الحجز غير موجود\n"; exit(20); }
if ($booking->trashed()) { echo "\n⚠ الحجز محذوف بالفعل\n"; exit(21); }

$bookingId = (int) $booking->id;

echo "\n[1] ملخص الحجز\n";
echo "─────────────────────────────────────────────────────────\n";
printf("  ID:           #%d\n", $booking->id);
printf("  Ref:          %s\n", $booking->booking_reference);
printf("  PNR:          %s\n", $booking->pnr ?? '-');
printf("  Customer:     %s (id=%d)\n",
    $booking->customer?->full_name ?? '-', $booking->customer_id
);
printf("  Carrier:      %s (id=%d, %s)\n",
    $booking->flightCarrier?->name ?? '-',
    $booking->flightCarrier?->id ?? 0,
    $booking->flightCarrier?->currency ?? '-'
);

// حفظ أرصدة قبل
$customerBalanceBefore = $booking->customer?->ledgerAccount
    ? (float) $booking->customer->ledgerAccount->balance : 0.0;
$carrierBalanceBefore  = $booking->flightCarrier
    ? (float) $booking->flightCarrier->balance : null;
$carrierCurrency       = $booking->flightCarrier?->currency;

// جمع كل الـ transactions والـ entries
$txs = Transaction::where('related_type', 'App\\Models\\Flight\\FlightBooking')
    ->where('related_id', $bookingId)
    ->orderBy('id')
    ->get();

if ($txs->isEmpty()) {
    echo "\n✗ لا توجد transactions مرتبطة — لن أكمل (احتياطياً).\n";
    exit(30);
}

$txIds = $txs->pluck('id')->all();
$entries = AccountEntry::whereIn('transaction_id', $txIds)
    ->with('account:id,name,type,balance,currency,module_type')
    ->orderBy('id')
    ->get();

echo "\n[2] الـ Transactions والـ AccountEntries المرتبطة\n";
echo "─────────────────────────────────────────────────────────\n";
foreach ($txs as $t) {
    printf("  Tx#%-4d | %-10s | amount=%.2f | from=%s | to=%s\n",
        $t->id, $t->type?->value ?? '-', (float) $t->amount,
        $t->from_account_id ?? '-', $t->to_account_id ?? '-'
    );
}

echo "\n  ── AccountEntries (قبل العكس) ──\n";
foreach ($entries as $e) {
    $acc = $e->account;
    printf("    Ent#%-4d | acct=%-3d %-40s | D=%10.2f | C=%10.2f | bal_after=%12.2f %s\n",
        $e->id, $acc->id ?? 0, mb_substr($acc->name ?? '?', 0, 40),
        (float) $e->debit, (float) $e->credit, (float) $e->balance_after, $acc->currency ?? ''
    );
}

// حفظ أرصدة الحسابات المعنية قبل
$accountIds = $entries->pluck('account_id')->unique()->values()->all();
$balancesBefore = Account::whereIn('id', $accountIds)->pluck('balance', 'id');

echo "\n  ── أرصدة الحسابات قبل ──\n";
foreach ($balancesBefore as $aid => $bal) {
    $acc = Account::find($aid);
    printf("    Acct#%-3d %-40s | balance=%.4f %s\n",
        $aid, mb_substr($acc?->name ?? '?', 0, 40), (float) $bal, $acc?->currency ?? ''
    );
}

echo "\n[3] تنفيذ MANUAL GL REVERSAL + hard-delete (DB::transaction)\n";
echo "─────────────────────────────────────────────────────────\n";

$carrierCreditAmount = null;  // مقدار الرصيد اللي هنرجعه للناقل (KWD)
$accountReversals = [];       // للطباعة بعدين

try {
    DB::transaction(function () use (
        $booking, $bookingId, $txs, $entries, $accountIds,
        &$carrierCreditAmount, &$accountReversals
    ) {

        // ── [3a] حساب الأرصدة المعكوسة + تطبيقها على Account.balance ──
        echo "    [3a] عكس أرصدة الحسابات...\n";

        // أنواع debit-normal: أصول/مصروفات
        $debitNormalTypes = [
            \App\Enums\AccountType::Cashbox->value,
            \App\Enums\AccountType::Wallet->value,
            \App\Enums\AccountType::Bank->value,
            \App\Enums\AccountType::Treasury->value,
            \App\Enums\AccountType::Post->value,
            \App\Enums\AccountType::Expense->value,
        ];

        LedgerBalanceMutationGuard::run(function () use ($entries, $debitNormalTypes, &$accountReversals) {
            // تجميع حسب الحساب (لو في أكتر من entry لنفس الحساب)
            $byAccount = $entries->groupBy('account_id');

            foreach ($byAccount as $acctId => $acctEntries) {
                $account = Account::lockForUpdate()->find($acctId);
                if (! $account) continue;

                $typeValue = $account->type instanceof \BackedEnum ? $account->type->value : (string) $account->type;
                $isDebitNormal = in_array($typeValue, $debitNormalTypes, true);

                // مجموع التأثير على الرصيد
                $netEffect = 0.0;
                foreach ($acctEntries as $e) {
                    $d = (float) $e->debit;
                    $c = (float) $e->credit;
                    if ($isDebitNormal) {
                        // debit-normal: balance += D - C
                        $netEffect += $d - $c;
                    } else {
                        // credit-normal: balance += C - D
                        $netEffect += $c - $d;
                    }
                }

                // العكس: طرح التأثير
                $newBalance = (float) $account->balance - $netEffect;
                $account->balance = $newBalance;
                $account->save();

                $accountReversals[] = [
                    'account_id' => $account->id,
                    'name'       => $account->name,
                    'currency'   => $account->currency,
                    'old'        => (float) $account->balance + $netEffect,
                    'new'        => $newBalance,
                    'delta'      => -$netEffect,
                ];
            }
        });

        // ── [3b] رجوع رصيد الناقل (FlightCarrier.available_balance) ──
        echo "    [3b] رجوع رصيد FlightCarrier...\n";
        if ($booking->flight_carrier_id && $booking->flightCarrier) {
            // مقدار الخصم الأصلي = AirlineTransaction الوحيد المرتبط بالحجز (type=debit)
            $airlineDebit = AirlineTransaction::where('flight_booking_id', $bookingId)
                ->where('type', 'debit')
                ->first();
            if ($airlineDebit) {
                $debitAmount = (float) $airlineDebit->amount;
                $carrier = \App\Models\Flight\FlightCarrier::lockForUpdate()->find($booking->flight_carrier_id);
                if ($carrier) {
                    $oldCarrierBalance = (float) $carrier->balance;
                    // استخدم credit() الرسمي (يحترم observer guard + ينشئ AirlineTransaction)
                    // الـ AirlineTransaction اللي هيتعمل هنا هيتحذف في Step 3e، فمفيش تضاعف.
                    $carrier->credit(
                        amount: $debitAmount,
                        description: 'Hard-delete reversal for booking #'.$booking->id.' ('.$bookingRef.')',
                        userId: 1,
                        bookingId: $bookingId
                    );
                    $carrierCreditAmount = $debitAmount;
                    printf("      ✓ Carrier %.4f → %.4f %s (delta: +%.4f)\n",
                        $oldCarrierBalance, (float) $carrier->fresh()->balance, $carrier->currency, $debitAmount
                    );
                }
            }
        }

        // ── [3c] حذف AccountEntries ──
        echo "    [3c] حذف AccountEntries...\n";
        $entryCount = AccountEntry::whereIn('id', $entries->pluck('id'))->forceDelete();
        printf("      ✓ account_entries deleted: %d\n", $entryCount);

        // ── [3d] حذف Transactions ──
        echo "    [3d] حذف Transactions المرتبطة...\n";
        $txCount = Transaction::whereIn('id', $txs->pluck('id'))->forceDelete();
        printf("      ✓ transactions deleted: %d\n", $txCount);

        // ── [3e] حذف AirlineTransaction ──
        echo "    [3e] حذف AirlineTransaction المرتبطة...\n";
        $airlineTxCount = AirlineTransaction::where('flight_booking_id', $bookingId)->forceDelete();
        printf("      ✓ airline_transactions deleted: %d\n", $airlineTxCount);

        // ── [3f] حذف السجلات الفرعية ──
        echo "    [3f] حذف السجلات الفرعية (passengers/tickets/segments)...\n";
        $paxCount = FlightPassenger::where('flight_booking_id', $bookingId)->forceDelete();
        $tktCount = FlightTicket::where('flight_booking_id', $bookingId)->forceDelete();
        $segCount = FlightSegment::where('flight_booking_id', $bookingId)->forceDelete();
        printf("      ✓ passengers=%d, tickets=%d, segments=%d\n",
            $paxCount, $tktCount, $segCount
        );

        // ── [3g] حذف flight_bookings عبر DB::table (يتجاوز model event) ──
        echo "    [3g] حذف flight_bookings عبر DB::table...\n";
        $deleted = DB::table('flight_bookings')->where('id', $bookingId)->delete();
        if ($deleted === 0) {
            throw new \RuntimeException("فشل حذف flight_bookings (id={$bookingId})");
        }
        printf("      ✓ rows deleted: %d\n", $deleted);
    });

    echo "\n  ✓ كل العمليات تمت بنجاح.\n";
} catch (\Throwable $e) {
    echo "\n  ✗ FATAL ERROR: ".$e->getMessage()."\n";
    echo "  → تم عمل ROLLBACK — قاعدة البيانات لم تتغير.\n";
    exit(99);
}

// [4] التحقق بعد الحذف
echo "\n[4] التحقق بعد الحذف\n";
echo "─────────────────────────────────────────────────────────\n";

// أرصدة الحسابات بعد
echo "  ── أرصدة الحسابات بعد ──\n";
foreach ($accountReversals as $r) {
    printf("    Acct#%-3d %-40s | %.4f → %.4f %s (delta: %+.4f)\n",
        $r['account_id'], mb_substr($r['name'], 0, 40),
        $r['old'], $r['new'], $r['currency'], $r['delta']
    );
}

// رصيد العميل بعد
if ($booking->customer?->ledgerAccount) {
    $customerBalanceAfter = (float) Account::where('id', $booking->customer->ledgerAccount->id)->value('balance');
    printf("\n  Customer:  %.2f → %.2f EGP (delta: %+.2f)\n",
        $customerBalanceBefore, $customerBalanceAfter,
        $customerBalanceAfter - $customerBalanceBefore
    );
}

// رصيد الناقل بعد
if ($booking->flightCarrier) {
    $carrierBalanceAfter = (float) \App\Models\Flight\FlightCarrier::where('id', $booking->flightCarrier->id)->value('balance');
    printf("  Carrier:   %.4f → %.4f %s (delta: %+.4f)\n",
        $carrierBalanceBefore, $carrierBalanceAfter, $carrierCurrency,
        $carrierBalanceAfter - $carrierBalanceBefore
    );
}

// تحقق نهائي
echo "\n  ── تحقق نهائي ──\n";
$stillExists = FlightBooking::withTrashed()->where('id', $bookingId)->exists();
printf("    FlightBooking#%d still exists: %s\n",
    $bookingId, $stillExists ? '✗ YES (FAIL!)' : '✓ NO (success)'
);
$remainingTx = Transaction::where('related_type', 'App\\Models\\Flight\\FlightBooking')
    ->where('related_id', $bookingId)->count();
printf("    Transactions remaining: %s\n", $remainingTx === 0 ? '✓ 0' : "✗ {$remainingTx}");
$remainingEntries = AccountEntry::whereIn('transaction_id', $txIds)->count();
printf("    AccountEntries remaining: %s\n", $remainingEntries === 0 ? '✓ 0' : "✗ {$remainingEntries}");
$remainingAirlineTx = AirlineTransaction::where('flight_booking_id', $bookingId)->count();
printf("    AirlineTransactions remaining: %s\n", $remainingAirlineTx === 0 ? '✓ 0' : "✗ {$remainingAirlineTx}");

echo "\n=========================================================\n";
echo "  ✓ تم حذف الحجز #{$bookingId} ({$bookingRef}) بالكامل.\n";
echo "=========================================================\n";
echo "  للسلامة الإضافية، تحقق في Filament:\n";
echo "    - /admin/flight-bookings (الحجز غير ظاهر)\n";
echo "    - /admin/customers (رصد العميل رجع)\n";
echo "    - /admin/flight-carriers (رصيد الناقل رجع)\n";
echo "  ونظّف ملف التأكيد: rm {$confirmFile}\n";
echo "=========================================================\n";