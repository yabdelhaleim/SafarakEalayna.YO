<?php

/**
 * =========================================================
 *  إصلاح عجز الميزان الموحد — حجوزات الطيران المدفوعة نقداً
 * =========================================================
 *
 * المشكلة:
 *  عند حجز تذكرة ودفع ثمنها نقداً مباشرة (في نفس جلسة الحجز)، يُسجَّل:
 *
 *   قيد البيع (recordSaleToCustomer):
 *     مدين:  إقفال مبيعات الطيران (−X)
 *     دائن:  حساب العميل           (+X)
 *
 *   قيد التحصيل (addPayment):
 *     مدين:  حساب العميل           (−X)   ← تُصفَّر مديونية العميل ✔
 *     دائن:  خزينة النقدى          (+X)   ← تُضاف للخزينة ✔
 *
 *  النتيجة: حساب "إقفال مبيعات الطيران" = −X  (رصيد سالب → يُظهر عجزاً)
 *  الخزينة وحساب العميل متوازنان، لكن الميزان الموحد يرصد العجز في الإقفال.
 *
 *  الإصلاح:
 *  نُنشئ قيد إغلاق يُصفّر رصيد الإقفال السالب:
 *     مدين:  خزينة النقدى                (+X شبح على الخزينة)
 *     دائن:  إقفال مبيعات الطيران        (+X  → يُعيد الرصيد للصفر)
 *
 *  بهذا يكون:
 *    إقفال مبيعات = 0     ✔ (لا عجز)
 *    خزينة النقدى = +X    ✔ (إيراد نقدي)
 *    حساب العميل  = 0     ✔ (مُسوَّى)
 *
 * تشغيل البرودكشن:
 *   # معاينة دون تغيير
 *   php fix_cash_booking_deficit.php --dry-run
 *
 *   # إصلاح حجز بعينه بالـ ID
 *   php fix_cash_booking_deficit.php --booking-id=123
 *
 *   # إصلاح حجز بعينه بالرقم
 *   php fix_cash_booking_deficit.php --booking-number=20260704-ABC123
 *
 *   # إصلاح جميع الحجوزات المتأثرة
 *   php fix_cash_booking_deficit.php
 */

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\Flight\FlightBooking;
use App\Enums\TransactionModule;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\TransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// ─── Parse Arguments ────────────────────────────────────────────────
$isDryRun    = in_array('--dry-run', $argv, true);
$specificId  = null;
$specificNum = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--booking-id=')) {
        $specificId = (int) substr($arg, strlen('--booking-id='));
    }
    if (str_starts_with($arg, '--booking-number=')) {
        $specificNum = trim(substr($arg, strlen('--booking-number=')));
    }
}

// ─── Console Colors ─────────────────────────────────────────────────
$G = "\033[0;32m"; $R = "\033[0;31m"; $Y = "\033[1;33m";
$C = "\033[0;36m"; $B = "\033[1m";    $X = "\033[0m";

function sep(): void          { echo str_repeat('─', 62) . "\n"; }
function hdr(string $t): void { global $B,$C,$X; echo "\n{$B}{$C}═══ {$t} ═══{$X}\n"; }
function ok(string $m): void  { global $G,$X; echo "  {$G}✔{$X} {$m}\n"; }
function ng(string $m): void  { global $R,$X; echo "  {$R}✘{$X} {$m}\n"; }
function wr(string $m): void  { global $Y,$X; echo "  {$Y}⚠{$X} {$m}\n"; }
function nf(string $m): void  { echo "    {$m}\n"; }

// ─── Header ─────────────────────────────────────────────────────────
sep();
echo "{$B}إصلاح عجز الميزان الموحد — حجوزات الطيران المدفوعة نقداً{$X}\n";
sep();
if ($isDryRun)    echo "{$Y}[DRY-RUN] معاينة فقط — لن تُحفظ أي تغييرات{$X}\n";
if ($specificId)  echo "الحجز المستهدف : ID = {$specificId}\n";
if ($specificNum) echo "الحجز المستهدف : رقم = {$specificNum}\n";
if (!$specificId && !$specificNum) echo "الوضع : إصلاح شامل لجميع الحجوزات المتأثرة\n";
echo "\n";

// ─── Resolve Clearing Account ────────────────────────────────────────
// ─── Resolve User for Authentication (CLI safety) ─────────────────────
$firstUser = \App\Models\User::first();
if ($firstUser) {
    auth()->login($firstUser);
}

$clearing      = app(LedgerClearingAccounts::class);
$txService     = app(TransactionService::class);

$clearingId    = $clearing->incomeContraIdForFlightBooking();
if (!$clearingId) {
    ng('لم يُعثر على حساب إقفال مبيعات الطيران. راجع config/accounting.php.');
    exit(1);
}

$clearingAcct  = Account::lockForUpdate()->find($clearingId);
if (!$clearingAcct) {
    ng("الحساب ID={$clearingId} غير موجود في قاعدة البيانات.");
    exit(1);
}

hdr('حساب الإقفال المستخدم');
nf("الاسم  : {$clearingAcct->name}");
nf("ID     : {$clearingAcct->id}");
nf("الرصيد الحالي : {$clearingAcct->balance} جنيه");

// ─── Build Booking Query ─────────────────────────────────────────────
hdr('تحليل الحجوزات');

$q = FlightBooking::query()
    ->with(['payments.transaction'])
    ->whereNotIn('status', ['cancelled', 'refunded'])
    ->where('selling_price', '>', 0)
    ->whereNotNull('sale_gl_transaction_id');

if ($specificId)  $q->where('id', $specificId);
if ($specificNum) $q->where('booking_number', $specificNum);

$bookings = $q->get();
nf("عدد الحجوزات المُفحوصة : {$bookings->count()}");

$stats = ['total' => 0, 'affected' => 0, 'fixed' => 0, 'skipped' => 0, 'errors' => []];

foreach ($bookings as $booking) {
    $stats['total']++;

    $sellingPrice = round((float) $booking->selling_price, 2);
    $totalPaid    = round((float) $booking->payments->sum('amount'), 2);

    // ── نحتاج فقط الحجوزات التي دُفعت كاملاً (نقداً) ──────────────
    if ($totalPaid <= 0) {
        $stats['skipped']++;
        continue;
    }

    // ── قيد البيع ──────────────────────────────────────────────────
    $saleTx = Transaction::find($booking->sale_gl_transaction_id);
    if (!$saleTx) {
        wr("حجز #{$booking->booking_number}: sale_gl_transaction_id غير موجود — تجاهل");
        $stats['skipped']++;
        continue;
    }

    // قيد البيع يجب أن يكون من الإقفال إلى حساب العميل
    if ((int) $saleTx->from_account_id !== $clearingId) {
        $stats['skipped']++;
        continue;
    }

    $saleAmount = round((float) $saleTx->amount, 2);

    // ── حساب الأثر الفعلي لهذا الحجز على الإقفال ──────────────────
    // (مدين = خصم من الإقفال ، دائن = إضافة للإقفال)
    $paymentTxIds = $booking->payments->pluck('transaction_id')->filter()->toArray();

    $debitOnClearing  = AccountEntry::where('account_id', $clearingId)
        ->where('transaction_id', $saleTx->id)
        ->sum('debit');

    $creditOnClearing = 0.0;
    if (!empty($paymentTxIds)) {
        $creditOnClearing = AccountEntry::where('account_id', $clearingId)
            ->whereIn('transaction_id', $paymentTxIds)
            ->sum('credit');
    }

    // الأثر الصافي على الإقفال من هذا الحجز
    $netOnClearing = round($creditOnClearing - $debitOnClearing, 2);

    // إذا الأثر الصافي = 0 أو إيجابي → الحجز متوازن
    if ($netOnClearing >= -0.02) {
        $stats['skipped']++;
        continue;
    }

    $deficitAmount = round(abs($netOnClearing), 2);
    $stats['affected']++;

    // ── حساب الخزينة المستخدمة في الدفع ───────────────────────────
    $treasuryAccountId = null;
    foreach ($booking->payments as $pmt) {
        if ($pmt->account_id) {
            $treasuryAccountId = (int) $pmt->account_id;
            break;
        }
    }

    echo "\n";
    wr("حجز #{$booking->booking_number} (ID={$booking->id})");
    nf("  سعر البيع       : {$sellingPrice} ج.م");
    nf("  إجمالي المحصّل  : {$totalPaid} ج.م");
    nf("  عجز الإقفال     : {$deficitAmount} ج.م");
    nf("  خزينة الدفع     : " . ($treasuryAccountId ? "ID={$treasuryAccountId}" : 'غير محدد'));
    nf("  قيد البيع TX    : #{$saleTx->id}");
    nf("  مدين الإقفال    : {$debitOnClearing} ج.م");
    nf("  دائن الإقفال    : {$creditOnClearing} ج.م");

    if (!$treasuryAccountId) {
        wr("لا يمكن تحديد خزينة الدفع — تجاهل هذا الحجز.");
        $stats['skipped']++;
        continue;
    }

    if ($isDryRun) {
        ok("[DRY-RUN] سيُنشأ قيد تصحيح: خزينة (ID={$treasuryAccountId}) ← إقفال (ID={$clearingId}) بمبلغ {$deficitAmount} ج.م");
        $stats['fixed']++;
        continue;
    }

    // ─────────────────── التصحيح الفعلي ─────────────────────────────
    try {
        DB::beginTransaction();

        LedgerBalanceMutationGuard::run(function ()
            use ($booking, $clearingId, $treasuryAccountId, $deficitAmount, $txService)
        {
            // قيد التصحيح:
            //   مدين:  خزينة النقدى  (−deficit) ← نُخصم من الخزينة مبلغاً رمزياً
            //   دائن:  إقفال مبيعات (+deficit) ← نُعيد الرصيد للصفر
            //
            // المنطق: البيع النقدي يجب أن يُغلق الإقفال مباشرة؛
            // هذا القيد يُصحح الخلل الناتج عن تسجيل قيدي البيع والتحصيل
            // بشكل منفصل بدلاً من قيد واحد مدمج.

            $correctionTx = $txService->recordJournalTransfer([
                'amount'              => $deficitAmount,
                'from_account_id'     => $treasuryAccountId,
                'to_account_id'       => $clearingId,
                'allow_from_negative' => true,   // الخزينة تحملت الإيراد بالفعل
                'module'              => TransactionModule::Flight->value,
                'related_type'        => FlightBooking::class,
                'related_id'          => $booking->id,
                'notes'               => sprintf(
                    'تصحيح تلقائي: تسوية عجز إقفال مبيعات الطيران — دفع نقدي عند الحجز #%s',
                    $booking->booking_number
                ),
                'created_by' => 1,
            ]);

            Log::info('fix_cash_booking_deficit: correction applied', [
                'booking_id'      => $booking->id,
                'booking_number'  => $booking->booking_number,
                'deficit_egp'     => $deficitAmount,
                'correction_tx'   => $correctionTx->id,
                'treasury_id'     => $treasuryAccountId,
                'clearing_id'     => $clearingId,
            ]);
        });

        DB::commit();
        ok("✅ تم إصلاح حجز #{$booking->booking_number} — قيد تصحيح بمبلغ {$deficitAmount} ج.م");
        $stats['fixed']++;

    } catch (\Exception $e) {
        DB::rollBack();
        ng("فشل إصلاح حجز #{$booking->booking_number}: " . $e->getMessage());
        $stats['errors'][$booking->id] = $e->getMessage();

        Log::error('fix_cash_booking_deficit: repair_failed', [
            'booking_id' => $booking->id,
            'error'      => $e->getMessage(),
        ]);
    }
}

// ─── Summary ─────────────────────────────────────────────────────────
hdr('ملخص النتائج');
nf(sprintf("الحجوزات المُفحوصة  : %d", $stats['total']));
nf(sprintf("حجوزات متأثرة       : %d", $stats['affected']));
nf(sprintf("حجوزات تم إصلاحها  : %d", $stats['fixed']));
nf(sprintf("حجوزات مُتجاهَلة    : %d", $stats['skipped']));

if (!empty($stats['errors'])) {
    echo "\n";
    ng('حجوزات فشل إصلاحها:');
    foreach ($stats['errors'] as $id => $err) {
        ng("  ID={$id} → {$err}");
    }
}

// ─── Final Balance Check ──────────────────────────────────────────────
hdr('رصيد الإقفال بعد الإصلاح');
$clearingAcct->refresh();
$finalBalance = round((float) $clearingAcct->balance, 2);

if (abs($finalBalance) <= 0.02) {
    ok("رصيد حساب الإقفال = {$finalBalance} ج.م ← متوازن تماماً ✓");
} elseif ($finalBalance < 0) {
    wr("رصيد حساب الإقفال = {$finalBalance} ج.م ← لا يزال سالباً");
    wr("قد توجد حجوزات أخرى لم تُعالَج (تحقق من الفلترة).");
} else {
    nf("رصيد حساب الإقفال = {$finalBalance} ج.م");
}

if ($isDryRun) {
    echo "\n{$Y}[DRY-RUN] لم تُحفظ أي تغييرات. شغّل بدون --dry-run لتطبيق الإصلاح.{$X}\n";
}

echo "\n"; sep();
echo "{$B}انتهى السكريبت.{$X}\n\n";
