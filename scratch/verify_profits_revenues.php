<?php

/**
 * التحقق من تطابق الأرباح والإيرادات: جداول العمليات ↔ Treasury ↔ P&L
 * التشغيل: php scratch/verify_profits_revenues.php [tourism|office|all]
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Finance\TreasuryService;
use App\Services\Reports\ProfitLossReportService;
use Illuminate\Support\Facades\DB;

$division = $argv[1] ?? 'all';
$errors = [];
$passed = 0;
$checks = 0;
$tolerance = 0.05;

function check(bool $ok, string $label, string $detail, array &$errors, int &$passed, int &$checks): void
{
    $checks++;
    if ($ok) {
        $passed++;
        echo "  ✅ $label — $detail\n";
    } else {
        $errors[] = "$label: $detail";
        echo "  ❌ $label — $detail\n";
    }
}

function fmt(float $n): string
{
    return number_format($n, 2);
}

echo "\n══════════════════════════════════════════════════════════════\n";
echo "  مراجعة الأرباح والإيرادات — ".strtoupper($division)."\n";
echo "  ".now()->toDateTimeString()."\n";
echo "══════════════════════════════════════════════════════════════\n\n";

$treasury = app(TreasuryService::class);
$plService = app(ProfitLossReportService::class);

// ── Helpers: حساب أرباح العمليات يدوياً (نفس منطق Treasury) ─────────────────
function manualTourismGrossProfits(): array
{
    $flight = DB::table('flight_bookings')
        ->whereNull('deleted_at')
        ->whereNotIn('status', [
            'CANCELLED', 'PENDING',
            'cancelled', 'pending',
            'PARTIALLY_REFUNDED', 'partially_refunded',
        ])
        ->get()
        ->sum(function ($booking) {
            $status = strtoupper((string) $booking->status);

            if ($status === 'REFUNDED') {
                return (float) DB::table('flight_refunds')
                    ->where('flight_booking_id', $booking->id)
                    ->sum('office_penalty');
            }

            $hasB2cRefund = DB::table('flight_refunds')
                ->where('flight_booking_id', $booking->id)
                ->exists();

            if ($hasB2cRefund) {
                return (float) DB::table('flight_refunds')
                    ->where('flight_booking_id', $booking->id)
                    ->sum('office_penalty');
            }

            return (float) $booking->profit;
        });

    $hajj = (float) DB::table('hajj_umra_bookings')
        ->whereIn('status', ['confirmed', 'completed', 'in_progress'])
        ->whereNull('deleted_at')
        ->sum('profit');

    $visa = (float) DB::table('visa_bookings')
        ->whereIn('status', ['approved', 'issued', 'submitted', 'under_review', 'completed'])
        ->whereNull('deleted_at')
        ->sum('profit');

    return [
        'flight' => round($flight, 2),
        'hajj_umra' => round($hajj, 2),
        'visa' => round($visa, 2),
        'total' => round($flight + $hajj + $visa, 2),
    ];
}

function manualOfficeGrossProfits(): array
{
    $bus = (float) DB::table('bus_bookings')
        ->whereNotIn('status', ['cancelled', 'refunded', 'partially_refunded'])
        ->whereNull('deleted_at')
        ->sum('profit');

    $online = (float) DB::table('online_transactions')
        ->whereNotIn('status', ['cancelled', 'failed'])
        ->whereNull('deleted_at')
        ->sum('profit');

    $fawry = (float) DB::table('fawry_transactions')
        ->whereNull('deleted_at')
        ->sum('profit');

    $wallet = (float) DB::table('wallet_transactions')
        ->whereNull('deleted_at')
        ->sum('service_fee');

    return [
        'bus' => round($bus, 2),
        'fawry' => round($fawry, 2),
        'online' => round($online, 2),
        'wallet' => round($wallet, 2),
        'total' => round($bus + $online + $fawry + $wallet, 2),
    ];
}

/** أرباح متوقعة من الحجوزات الملغاة/المستردة (غرامات المكتب) — للمقارنة مع P&L */
function tourismRefundPenalties(): float
{
    return (float) DB::table('flight_refunds')
        ->join('flight_bookings', 'flight_bookings.id', '=', 'flight_refunds.flight_booking_id')
        ->whereNull('flight_bookings.deleted_at')
        ->sum('flight_refunds.office_penalty');
}

function runDivisionChecks(string $div, TreasuryService $treasury, ProfitLossReportService $plService, array &$errors, int &$passed, int &$checks, float $tolerance): void
{
    $sep = str_repeat('─', 60);
    echo "▶ قسم: ".strtoupper($div)."\n$sep\n";

    $manual = $div === 'tourism' ? manualTourismGrossProfits() : manualOfficeGrossProfits();
    $treasuryGross = round($treasury->calculateDynamicProfits($div), 2);
    $treasuryNet = round($treasury->calculateDivisionNetProfits($div), 2);
    $opex = round($treasury->calculateOperatingExpenses($div), 2);

    check(
        abs($manual['total'] - $treasuryGross) <= $tolerance,
        'أرباح العمليات (جداول) = Treasury gross_profits',
        'يدوي='.fmt($manual['total']).' | Treasury='.fmt($treasuryGross),
        $errors, $passed, $checks
    );

    $pl = $plService->report(['category' => $div]);
    $usesLedgerNet = $div === 'office' && ($pl['meta']['transactions_included'] ?? 0) > 0;

    if ($usesLedgerNet) {
        check(
            abs($pl['netProfit'] - $treasuryNet) <= $tolerance,
            'صافي Treasury = صافي P&L (دفتر)',
            'P&L='.fmt($pl['netProfit']).' | Treasury='.fmt($treasuryNet),
            $errors, $passed, $checks
        );
    } else {
        check(
            abs($treasuryGross - $opex - $treasuryNet) <= $tolerance,
            'صافي الأرباح = إجمالي العمليات − مصروفات تشغيلية',
            'gross('.fmt($treasuryGross).') - opex('.fmt($opex).') = net('.fmt($treasuryNet).')',
            $errors, $passed, $checks
        );
    }

    $tb = $div === 'tourism' ? $treasury->getTrialBalance() : $treasury->getOfficeTrialBalance();
    check(
        abs($tb['gross_profits'] - $treasuryGross) <= $tolerance,
        'ميزان المراجعة gross_profits = calculateDynamicProfits',
        'TB='.fmt($tb['gross_profits']).' | Calc='.fmt($treasuryGross),
        $errors, $passed, $checks
    );
    check(
        abs($tb['profits'] - $treasuryNet) <= $tolerance,
        'ميزان المراجعة profits = calculateDivisionNetProfits',
        'TB='.fmt($tb['profits']).' | Calc='.fmt($treasuryNet),
        $errors, $passed, $checks
    );
    check(
        abs($tb['operating_expenses'] - $opex) <= $tolerance,
        'ميزان المراجعة operating_expenses متطابق',
        'TB='.fmt($tb['operating_expenses']).' | Calc='.fmt($opex),
        $errors, $passed, $checks
    );

    $plBreakdown = $plService->moduleBreakdown(['category' => $div]);

    echo "\n  📊 تفصيل أرباح العمليات (جداول):\n";
    foreach ($manual as $k => $v) {
        if ($k === 'total') {
            continue;
        }
        echo "     • $k: ".fmt($v)." EGP\n";
    }
    echo '     ─ إجمالي: '.fmt($manual['total'])." EGP\n";

    echo "\n  📒 تقرير P&L (الدفتر — category=$div):\n";
    echo '     • إجمالي الإيرادات: '.fmt($pl['totalRevenues'])." EGP\n";
    echo '     • تكلفة الحجوزات COGS: '.fmt($pl['totalCogs'])." EGP\n";
    echo '     • مصروفات تشغيلية: '.fmt($pl['totalExpenses'])." EGP\n";
    echo '     • مرتجعات: '.fmt($pl['totalRefunds'])." EGP\n";
    echo '     • مجمل الربح: '.fmt($pl['grossProfit'])." EGP\n";
    echo '     • صافي الربح (P&L): '.fmt($pl['netProfit'])." EGP\n";
    echo '     • معاملات مُدرجة: '.($pl['meta']['transactions_included'] ?? 0)."\n";

    if (! empty($plBreakdown['by_module'])) {
        echo "\n  📋 P&L حسب الموديول:\n";
        foreach ($plBreakdown['by_module'] as $row) {
            echo sprintf(
                "     • %-12s | إيراد=%s | COGS=%s | مصروف=%s | ربح=%s\n",
                $row['module'],
                fmt($row['income']),
                fmt($row['cogs']),
                fmt($row['expense']),
                fmt($row['profit'])
            );
        }
    }

    if ($div === 'tourism') {
        $plNetCalc = round($pl['totalRevenues'] - $pl['totalCogs'] - $pl['totalExpenses'], 2);
        check(
            abs($plNetCalc - $pl['netProfit']) <= $tolerance,
            'معادلة P&L داخلية: إيرادات − COGS − مصروفات = صافي',
            'محسوب='.fmt($plNetCalc).' | تقرير='.fmt($pl['netProfit']),
            $errors, $passed, $checks
        );

        $plGrossCalc = round($pl['totalRevenues'] - $pl['totalCogs'], 2);
        check(
            abs($plGrossCalc - $pl['grossProfit']) <= $tolerance,
            'معادلة P&L: إيرادات − COGS = مجمل الربح',
            'محسوب='.fmt($plGrossCalc).' | تقرير='.fmt($pl['grossProfit']),
            $errors, $passed, $checks
        );

        check(
            abs($treasuryGross - $pl['grossProfit']) <= max(1.0, $pl['grossProfit'] * 0.02),
            'gross_profits (Treasury) ≈ مجمل ربح P&L (دفتر)',
            'Treasury='.fmt($treasuryGross).' | P&L gross='.fmt($pl['grossProfit']),
            $errors, $passed, $checks
        );
    }

    echo "\n";
}

if ($division === 'all' || $division === 'tourism') {
    runDivisionChecks('tourism', $treasury, $plService, $errors, $passed, $checks, $tolerance);
}

if ($division === 'all' || $division === 'office') {
    runDivisionChecks('office', $treasury, $plService, $errors, $passed, $checks, $tolerance);
}

// ── فحص إيرادات/تكاليف من الحجوزات النشطة vs COGS/إيراد تقريبي ─────────────
if ($division === 'all' || $division === 'tourism') {
    echo "▶ تحقق إيرادات/تكاليف السياحة من الحجوزات النشطة\n".str_repeat('─', 60)."\n";

    $flightActive = DB::table('flight_bookings')
        ->whereNull('deleted_at')
        ->whereIn('status', ['CONFIRMED', 'confirmed', 'TICKETED', 'ticketed', 'ISSUED', 'issued'])
        ->selectRaw('SUM(selling_price) as sell, SUM(purchase_price) as buy, SUM(profit) as profit')
        ->first();

    $hajjActive = DB::table('hajj_umra_bookings')
        ->whereIn('status', ['confirmed', 'completed', 'in_progress'])
        ->whereNull('deleted_at')
        ->selectRaw('SUM(selling_price) as sell, SUM(purchase_price) as buy, SUM(profit) as profit')
        ->first();

    $visaActive = DB::table('visa_bookings')
        ->whereIn('status', ['approved', 'issued', 'submitted', 'under_review', 'completed'])
        ->whereNull('deleted_at')
        ->selectRaw('SUM(selling_price + COALESCE(service_fee,0)) as sell, SUM(purchase_price) as buy, SUM(profit) as profit')
        ->first();

    $sellTotal = (float) ($flightActive->sell ?? 0) + (float) ($hajjActive->sell ?? 0) + (float) ($visaActive->sell ?? 0);
    $buyTotal = (float) ($flightActive->buy ?? 0) + (float) ($hajjActive->buy ?? 0) + (float) ($visaActive->buy ?? 0);
    $profitTotal = (float) ($flightActive->profit ?? 0) + (float) ($hajjActive->profit ?? 0) + (float) ($visaActive->profit ?? 0);

    echo '  حجوزات نشطة — بيع: '.fmt($sellTotal).' | شراء: '.fmt($buyTotal).' | ربح جداول: '.fmt($profitTotal)."\n";

    check(
        abs($sellTotal - $buyTotal - $profitTotal) <= max(1.0, $profitTotal * 0.01 + $tolerance),
        'معادلة الحجوزات: البيع − الشراء ≈ الربح (نشطة)',
        'بيع−شراء='.fmt($sellTotal - $buyTotal).' | ربح='.fmt($profitTotal),
        $errors, $passed, $checks
    );
    echo "\n";
}

echo "══════════════════════════════════════════════════════════════\n";
echo "  الخلاصة\n";
echo "══════════════════════════════════════════════════════════════\n";
$fail = $checks - $passed;
$pct = $checks > 0 ? round($passed / $checks * 100, 1) : 0;
echo "  فحوصات: $checks | نجح: $passed | فشل: $fail | نسبة النجاح: {$pct}%\n";

if (! empty($errors)) {
    echo "\n  المشاكل:\n";
    foreach ($errors as $i => $e) {
        echo '    '.($i + 1).". $e\n";
    }
} else {
    echo "\n  🎉 الأرباح والإيرادات متسقة عبر الجداول والميزان وP&L.\n";
}
echo "\n══════════════════════════════════════════════════════════════\n\n";

exit(empty($errors) ? 0 : 1);
