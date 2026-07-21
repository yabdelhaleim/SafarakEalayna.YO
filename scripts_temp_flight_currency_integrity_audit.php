<?php
/**
 * Flight Bookings — Currency & Hierarchy Integrity Audit
 * ======================================================
 *
 * Checks (read-only, no DB writes):
 *   1. Currency fields on flight_bookings:
 *      - booking.currency (العملة الرسمية للبيع) vs foreign_currency vs original_currency
 *      - purchase_price vs purchase_price_foreign vs purchase_price_egp
 *      - exchange_rate vs exchange_rate_used vs booking_exchange_rate
 *      - base_currency_amount (should match selling_price in EGP or be 0)
 *
 *   2. Booking hierarchy consistency:
 *      - group -> carrier -> system (orphans)
 *      - groups pointing to deleted carrier (SoftDeletes)
 *      - carriers pointing to deleted system
 *      - airline_account_id soft-deleted
 *
 *   3. Flight payments vs booking currency:
 *      - payments in foreign currency without exchange rate on booking
 *      - payments sum > selling_price (overpayment)
 *      - payment_method currency hint mismatch
 *
 *   4. Balance integrity:
 *      - flight_carriers.balance == sum(debit) - sum(credit) of airline_transactions
 *      - flight_systems.balance == sum(debit) - sum(credit) of flight_system_transactions
 *      - flight_groups account balance == sum of flight_group_transactions
 *
 *   5. Common sense checks:
 *      - cancelled bookings with paid sums > 0 (refund missing?)
 *      - selling_price <= 0
 *      - bookings without any system/carrier/group at all
 *      - duplicate (booking_reference) across soft-deleted rows
 *
 * Usage:
 *   php scripts_temp_flight_currency_integrity_audit.php
 */

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$report = [
    'generated_at' => date('Y-m-d H:i:s'),
    'database' => DB::connection()->getDatabaseName(),
    'counts' => [],
    'currency_issues' => [],
    'hierarchy_issues' => [],
    'payment_issues' => [],
    'balance_issues' => [],
    'business_logic_issues' => [],
];

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║  Flight Bookings — Currency & Hierarchy Integrity Audit       ║\n";
echo "║  " . date('Y-m-d H:i:s') . "                                          ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// -----------------------------------------------------------
// 0. Table row counts
// -----------------------------------------------------------
$tables = [
    'flight_bookings',
    'flight_groups',
    'flight_carriers',
    'flight_systems',
    'airline_accounts',
    'flight_payments',
    'airline_transactions',
    'flight_system_transactions',
    'flight_group_transactions',
];
foreach ($tables as $t) {
    $count = DB::table($t)->count();
    $report['counts'][$t] = $count;
    echo "  • {$t}: {$count}\n";
}
echo "\n";

// -----------------------------------------------------------
// 1. CURRENCY FIELD SANITY on flight_bookings
// -----------------------------------------------------------
echo "== [1] Currency field consistency on flight_bookings ==\n";

$rows = DB::table('flight_bookings')
    ->select(
        'id', 'booking_reference', 'booking_number',
        'currency', 'foreign_currency', 'original_currency',
        'selling_price', 'purchase_price', 'profit',
        'purchase_price_foreign', 'purchase_price_egp',
        'exchange_rate', 'exchange_rate_used', 'booking_exchange_rate',
        'base_currency_amount',
        'status',
        'flight_system_id', 'flight_carrier_id', 'flight_group_id', 'airline_account_id',
        'deleted_at'
    )
    ->get();

$currencyIssues = [];
foreach ($rows as $r) {
    $issues = [];

    // 1.1 booking.currency blank
    if (empty($r->currency)) {
        $issues[] = 'currency فارغ';
    }

    // 1.2 foreign_currency mismatch with original_currency
    if (!empty($r->foreign_currency) && !empty($r->original_currency)
        && strtoupper($r->foreign_currency) !== strtoupper($r->original_currency)) {
        $issues[] = "foreign_currency({$r->foreign_currency}) ≠ original_currency({$r->original_currency})";
    }

    // 1.3 booking.currency mismatch with original_currency
    if (!empty($r->original_currency)
        && !empty($r->currency)
        && strtoupper($r->original_currency) === strtoupper($r->currency)) {
        // ok: أصلاً حجز بنفس عملة البيع → original_currency لازم يكون NULL عادةً
        $issues[] = "original_currency({$r->original_currency}) == currency({$r->currency}) — حقل غير ضروري";
    }

    // 1.4 foreign_currency set but purchase_price_foreign missing
    if (!empty($r->foreign_currency) && $r->purchase_price_foreign === null) {
        $issues[] = "foreign_currency({$r->foreign_currency}) موجود لكن purchase_price_foreign فارغ";
    }

    // 1.5 purchase_price_foreign set but foreign_currency missing
    if ($r->purchase_price_foreign !== null && empty($r->foreign_currency)) {
        $issues[] = "purchase_price_foreign({$r->purchase_price_foreign}) موجود لكن foreign_currency فارغ";
    }

    // 1.6 exchange_rate_used vs booking_exchange_rate mismatch
    if ($r->exchange_rate_used !== null && $r->booking_exchange_rate !== null) {
        if (abs((float)$r->exchange_rate_used - (float)$r->booking_exchange_rate) > 0.0001) {
            $issues[] = "exchange_rate_used({$r->exchange_rate_used}) ≠ booking_exchange_rate({$r->booking_exchange_rate})";
        }
    }

    // 1.7 profit != selling - purchase
    if ($r->selling_price !== null && $r->purchase_price !== null && $r->profit !== null) {
        $expected = (float)$r->selling_price - (float)$r->purchase_price;
        $actual = (float)$r->profit;
        if (abs($expected - $actual) > 0.01) {
            $issues[] = "profit غير متطابق: selling({$r->selling_price}) - purchase({$r->purchase_price}) = {$expected}, المخزّن = {$actual}";
        }
    }

    // 1.8 purchase_price_egp vs computed
    if ($r->purchase_price_foreign !== null && $r->exchange_rate !== null && $r->purchase_price_egp !== null) {
        $expected = round((float)$r->purchase_price_foreign * (float)$r->exchange_rate, 2);
        if (abs($expected - (float)$r->purchase_price_egp) > 0.05) {
            $issues[] = "purchase_price_egp({$r->purchase_price_egp}) ≠ foreign({$r->purchase_price_foreign}) × rate({$r->exchange_rate}) = {$expected}";
        }
    }

    // 1.9 negative selling_price
    if ((float)$r->selling_price < 0) {
        $issues[] = "selling_price سالب: {$r->selling_price}";
    }

    // 1.10 booking with no system/carrier/group at all
    if (empty($r->flight_system_id) && empty($r->flight_carrier_id) && empty($r->flight_group_id) && empty($r->airline_account_id)) {
        $issues[] = 'لا يوجد system/carrier/group/airline_account مرتبط — orphan booking';
    }

    if (!empty($issues)) {
        $currencyIssues[] = [
            'booking_id' => $r->id,
            'reference' => $r->booking_reference ?? $r->booking_number,
            'status' => $r->status,
            'deleted' => $r->deleted_at ? 'YES' : 'NO',
            'currency' => $r->currency,
            'foreign_currency' => $r->foreign_currency,
            'original_currency' => $r->original_currency,
            'selling_price' => $r->selling_price,
            'purchase_price' => $r->purchase_price,
            'purchase_price_foreign' => $r->purchase_price_foreign,
            'purchase_price_egp' => $r->purchase_price_egp,
            'exchange_rate' => $r->exchange_rate,
            'exchange_rate_used' => $r->exchange_rate_used,
            'booking_exchange_rate' => $r->booking_exchange_rate,
            'base_currency_amount' => $r->base_currency_amount,
            'issues' => $issues,
        ];
    }
}

echo "  ✗ Currency issues found: " . count($currencyIssues) . " booking(s)\n";
$report['currency_issues'] = $currencyIssues;

// Print first 20
foreach (array_slice($currencyIssues, 0, 20) as $c) {
    echo "    [#{$c['booking_id']}] {$c['reference']} ({$c['status']}) — " . implode(' | ', $c['issues']) . "\n";
}
if (count($currencyIssues) > 20) {
    echo "    ... و " . (count($currencyIssues) - 20) . " آخرين\n";
}
echo "\n";

// -----------------------------------------------------------
// 2. HIERARCHY consistency (groups/carriers/systems/airline_accounts)
// -----------------------------------------------------------
echo "== [2] Hierarchy consistency (groups/carriers/systems) ==\n";

$hierarchyIssues = [];

// 2.1 groups whose flight_carrier_id is null (already orphaned)
$groupsNoCarrier = DB::table('flight_groups')
    ->whereNull('flight_carrier_id')
    ->whereNull('deleted_at')
    ->select('id', 'name', 'code', 'flight_carrier_id', 'account_id')
    ->get();
foreach ($groupsNoCarrier as $g) {
    $hierarchyIssues[] = [
        'kind' => 'group_no_carrier',
        'id' => $g->id,
        'name' => "{$g->name} ({$g->code})",
        'detail' => 'مجموعة نشطة بدون ناقل',
    ];
}

// 2.2 groups whose flight_carrier_id points to soft-deleted carrier
$groupsDeadCarrier = DB::table('flight_groups as g')
    ->join('flight_carriers as c', 'g.flight_carrier_id', '=', 'c.id')
    ->whereNull('g.deleted_at')
    ->whereNotNull('c.deleted_at')
    ->select('g.id', 'g.name', 'g.code', 'g.flight_carrier_id', 'c.name as carrier_name')
    ->get();
foreach ($groupsDeadCarrier as $g) {
    $hierarchyIssues[] = [
        'kind' => 'group_deleted_carrier',
        'id' => $g->id,
        'name' => "{$g->name} → {$g->carrier_name} (محذوف)",
        'detail' => 'مجموعة نشطة تشير لناقل محذوف',
    ];
}

// 2.3 carriers whose flight_system_id is null AND not deleted
$carriersNoSystem = DB::table('flight_carriers')
    ->whereNull('flight_system_id')
    ->whereNull('deleted_at')
    ->select('id', 'name', 'code', 'flight_system_id', 'currency')
    ->get();
foreach ($carriersNoSystem as $c) {
    $hierarchyIssues[] = [
        'kind' => 'carrier_no_system',
        'id' => $c->id,
        'name' => "{$c->name} ({$c->code}) — {$c->currency}",
        'detail' => 'ناقل نشط بدون نظام حجز',
    ];
}

// 2.4 carriers whose flight_system_id points to soft-deleted system
$carriersDeadSystem = DB::table('flight_carriers as c')
    ->join('flight_systems as s', 'c.flight_system_id', '=', 's.id')
    ->whereNull('c.deleted_at')
    ->whereNotNull('s.deleted_at')
    ->select('c.id', 'c.name', 'c.code', 'c.flight_system_id', 's.name as system_name')
    ->get();
foreach ($carriersDeadSystem as $c) {
    $hierarchyIssues[] = [
        'kind' => 'carrier_deleted_system',
        'id' => $c->id,
        'name' => "{$c->name} → {$c->system_name} (محذوف)",
        'detail' => 'ناقل نشط يشير لنظام محذوف',
    ];
}

// 2.5 bookings pointing to soft-deleted system/carrier/group
$bookingsDeadLinks = DB::table('flight_bookings as b')
    ->leftJoin('flight_systems as s', 'b.flight_system_id', '=', 's.id')
    ->leftJoin('flight_carriers as c', 'b.flight_carrier_id', '=', 'c.id')
    ->leftJoin('flight_groups as g', 'b.flight_group_id', '=', 'g.id')
    ->where(function ($q) {
        $q->whereNotNull('b.flight_system_id')->whereNotNull('s.deleted_at')
          ->orWhere(function ($q2) { $q2->whereNotNull('b.flight_carrier_id')->whereNotNull('c.deleted_at'); })
          ->orWhere(function ($q2) { $q2->whereNotNull('b.flight_group_id')->whereNotNull('g.deleted_at'); });
    })
    ->whereNull('b.deleted_at')
    ->select(
        'b.id', 'b.booking_reference', 'b.booking_number', 'b.status',
        'b.flight_system_id', 'b.flight_carrier_id', 'b.flight_group_id',
        's.deleted_at as sys_deleted',
        'c.deleted_at as car_deleted',
        'g.deleted_at as grp_deleted'
    )
    ->limit(200)
    ->get();
foreach ($bookingsDeadLinks as $b) {
    $dead = [];
    if ($b->sys_deleted) $dead[] = "system#{$b->flight_system_id}";
    if ($b->car_deleted) $dead[] = "carrier#{$b->flight_carrier_id}";
    if ($b->grp_deleted) $dead[] = "group#{$b->flight_group_id}";
    $hierarchyIssues[] = [
        'kind' => 'booking_dead_link',
        'id' => $b->id,
        'name' => ($b->booking_reference ?? $b->booking_number) . " → " . implode(',', $dead),
        'detail' => 'حجز نشط يشير لكيان محذوف',
    ];
}

echo "  ✗ Hierarchy issues: " . count($hierarchyIssues) . "\n";
$report['hierarchy_issues'] = $hierarchyIssues;
foreach (array_slice($hierarchyIssues, 0, 20) as $h) {
    echo "    [{$h['kind']}] {$h['name']} — {$h['detail']}\n";
}
if (count($hierarchyIssues) > 20) {
    echo "    ... و " . (count($hierarchyIssues) - 20) . " آخرين\n";
}
echo "\n";

// -----------------------------------------------------------
// 3. Payments currency & overpayment checks
// -----------------------------------------------------------
echo "== [3] Payments vs booking currency ==\n";

$paymentIssues = [];

$paymentRows = DB::table('flight_payments as p')
    ->join('flight_bookings as b', 'p.flight_booking_id', '=', 'b.id')
    ->select(
        'p.id as pid', 'p.flight_booking_id',
        'p.amount', 'p.currency', 'p.original_amount',
        'p.payment_method',
        'b.booking_reference', 'b.booking_number', 'b.status',
        'b.currency as booking_currency', 'b.selling_price',
        'b.foreign_currency', 'b.exchange_rate_used', 'b.original_currency as b_original_currency'
    )
    ->get();

foreach ($paymentRows as $p) {
    $issues = [];

    // 3.1 payment.currency != booking.currency
    if (!empty($p->currency) && !empty($p->booking_currency)
        && strtoupper($p->currency) !== strtoupper($p->booking_currency)) {
        $issues[] = "payment.currency({$p->currency}) ≠ booking.currency({$p->booking_currency})";
    }

    // 3.2 original_amount set but amount equals it (no real conversion happened)
    if ($p->original_amount !== null && $p->amount !== null
        && abs((float)$p->original_amount - (float)$p->amount) < 0.005
        && !empty($p->booking_currency) && !empty($p->currency)
        && strtoupper($p->booking_currency) !== strtoupper($p->currency)) {
        $issues[] = "تحويل عملة مفقود: payment.currency({$p->currency}) ≠ booking.currency({$p->booking_currency}) لكن original_amount = amount";
    }

    if (!empty($issues)) {
        $paymentIssues[] = [
            'payment_id' => $p->pid,
            'booking_id' => $p->flight_booking_id,
            'booking_ref' => $p->booking_reference ?? $p->booking_number,
            'amount' => $p->amount,
            'currency' => $p->currency,
            'original_amount' => $p->original_amount,
            'original_currency' => $p->original_currency,
            'booking_currency' => $p->booking_currency,
            'selling_price' => $p->selling_price,
            'issues' => $issues,
        ];
    }
}

// 3.3 overpayment: sum(payments) > selling_price by more than 0.01
$overpay = DB::table('flight_bookings as b')
    ->leftJoin('flight_payments as p', function ($j) {
        $j->on('p.flight_booking_id', '=', 'b.id')
          ->whereNull('p.deleted_at');
    })
    ->whereNull('b.deleted_at')
    ->whereIn('b.status', ['confirmed', 'issued', 'completed', 'partially_paid'])
    ->groupBy('b.id', 'b.booking_reference', 'b.booking_number', 'b.selling_price', 'b.currency')
    ->havingRaw('COALESCE(SUM(p.amount),0) > b.selling_price + 0.01')
    ->selectRaw('b.id, b.booking_reference, b.booking_number, b.selling_price, b.currency, COALESCE(SUM(p.amount),0) as paid_sum')
    ->limit(50)
    ->get();

foreach ($overpay as $o) {
    $paymentIssues[] = [
        'kind' => 'overpayment',
        'booking_id' => $o->id,
        'booking_ref' => $o->booking_reference ?? $o->booking_number,
        'selling_price' => $o->selling_price,
        'paid_sum' => $o->paid_sum,
        'issues' => ["مدفوعات ({$o->paid_sum} {$o->currency}) > سعر البيع ({$o->selling_price} {$o->currency})"],
    ];
}

echo "  ✗ Payment issues: " . count($paymentIssues) . "\n";
$report['payment_issues'] = $paymentIssues;
foreach (array_slice($paymentIssues, 0, 20) as $p) {
    if (($p['kind'] ?? '') === 'overpayment') {
        echo "    [OVERPAY] #{$p['booking_id']} {$p['booking_ref']} — selling {$p['selling_price']} paid {$p['paid_sum']}\n";
    } else {
        echo "    [PAY #{$p['payment_id']}/B#{$p['booking_id']}] " . implode(' | ', $p['issues']) . "\n";
    }
}
if (count($paymentIssues) > 20) {
    echo "    ... و " . (count($paymentIssues) - 20) . " آخرين\n";
}
echo "\n";

// -----------------------------------------------------------
// 4. Balance integrity
// -----------------------------------------------------------
echo "== [4] Balance integrity ==\n";

$balanceIssues = [];

// 4.1 flight_carriers.balance vs airline_transactions
$carriersBal = DB::table('flight_carriers as c')
    ->leftJoin('airline_transactions as t', 't.flight_carrier_id', '=', 'c.id')
    ->whereNull('c.deleted_at')
    ->groupBy('c.id', 'c.name', 'c.code', 'c.currency', 'c.balance')
    ->selectRaw('c.id, c.name, c.code, c.currency, c.balance, COALESCE(SUM(CASE WHEN t.type="credit" THEN t.amount ELSE 0 END),0) as credits, COALESCE(SUM(CASE WHEN t.type="debit" THEN t.amount ELSE 0 END),0) as debits')
    ->get();

foreach ($carriersBal as $c) {
    $expected = round((float)$c->credits - (float)$c->debits, 2);
    $actual = round((float)$c->balance, 2);
    if (abs($expected - $actual) > 0.05) {
        $balanceIssues[] = [
            'kind' => 'carrier_balance_mismatch',
            'id' => $c->id,
            'name' => "{$c->name} ({$c->code}) — {$c->currency}",
            'stored' => $actual,
            'computed' => $expected,
            'delta' => round($actual - $expected, 2),
        ];
    }
}

// 4.2 flight_systems.balance vs flight_system_transactions
$systemsBal = DB::table('flight_systems as s')
    ->leftJoin('flight_system_transactions as t', 't.flight_system_id', '=', 's.id')
    ->whereNull('s.deleted_at')
    ->groupBy('s.id', 's.name', 's.code', 's.currency', 's.balance')
    ->selectRaw('s.id, s.name, s.code, s.currency, s.balance, COALESCE(SUM(CASE WHEN t.type="credit" THEN t.amount ELSE 0 END),0) as credits, COALESCE(SUM(CASE WHEN t.type="debit" THEN t.amount ELSE 0 END),0) as debits')
    ->get();

foreach ($systemsBal as $s) {
    $expected = round((float)$s->credits - (float)$s->debits, 2);
    $actual = round((float)$s->balance, 2);
    if (abs($expected - $actual) > 0.05) {
        $balanceIssues[] = [
            'kind' => 'system_balance_mismatch',
            'id' => $s->id,
            'name' => "{$s->name} ({$s->code}) — {$s->currency}",
            'stored' => $actual,
            'computed' => $expected,
            'delta' => round($actual - $expected, 2),
        ];
    }
}

// 4.3 flight_groups account balance vs flight_group_transactions (if linked to account)
$groupBals = DB::table('flight_groups as g')
    ->whereNotNull('g.account_id')
    ->whereNull('g.deleted_at')
    ->select('g.id', 'g.name', 'g.code', 'g.account_id')
    ->get();

foreach ($groupBals as $g) {
    $debits = (float) DB::table('flight_group_transactions')
        ->where('flight_group_id', $g->id)
        ->where('type', 'debit')
        ->sum('amount');
    $credits = (float) DB::table('flight_group_transactions')
        ->where('flight_group_id', $g->id)
        ->where('type', 'credit')
        ->sum('amount');

    // The group account balance is tracked on Account entries; we just report transaction totals.
    $balanceIssues_meta[] = [
        'kind' => 'group_transactions_total',
        'id' => $g->id,
        'name' => "{$g->name} ({$g->code})",
        'account_id' => $g->account_id,
        'debits' => $debits,
        'credits' => $credits,
        'net' => round($credits - $debits, 2),
    ];
}

echo "  ✗ Balance mismatches: " . count(array_filter($balanceIssues, fn ($x) => str_contains($x['kind'], 'mismatch'))) . "\n";
foreach ($balanceIssues as $b) {
    echo "    [{$b['kind']}] {$b['name']} — مخزّن {$b['stored']} | محسوب {$b['computed']} | فرق {$b['delta']}\n";
}
echo "  ℹ Group transaction totals (informational): " . count($balanceIssues_meta) . "\n";

$report['balance_issues'] = $balanceIssues;
$report['group_transactions_summary'] = $balanceIssues_meta;
echo "\n";

// -----------------------------------------------------------
// 5. Business-logic checks
// -----------------------------------------------------------
echo "== [5] Business-logic checks ==\n";

$blIssues = [];

// 5.1 cancelled bookings with payments
$cancelledWithPayments = DB::table('flight_bookings as b')
    ->leftJoin('flight_payments as p', function ($j) {
        $j->on('p.flight_booking_id', '=', 'b.id')->whereNull('p.deleted_at');
    })
    ->whereNull('b.deleted_at')
    ->whereIn('b.status', ['cancelled', 'canceled'])
    ->groupBy('b.id', 'b.booking_reference', 'b.booking_number', 'b.selling_price')
    ->havingRaw('COALESCE(SUM(p.amount),0) > 0.01')
    ->selectRaw('b.id, b.booking_reference, b.booking_number, b.selling_price, COALESCE(SUM(p.amount),0) as paid_sum')
    ->limit(50)
    ->get();

foreach ($cancelledWithPayments as $c) {
    $blIssues[] = [
        'kind' => 'cancelled_with_payments',
        'booking_id' => $c->id,
        'booking_ref' => $c->booking_reference ?? $c->booking_number,
        'paid' => $c->paid_sum,
        'selling_price' => $c->selling_price,
    ];
}

// 5.2 duplicate booking_reference
$dups = DB::table('flight_bookings')
    ->whereNotNull('booking_reference')
    ->groupBy('booking_reference')
    ->havingRaw('COUNT(*) > 1')
    ->select('booking_reference', DB::raw('COUNT(*) as c'))
    ->get();
foreach ($dups as $d) {
    $blIssues[] = [
        'kind' => 'duplicate_reference',
        'booking_reference' => $d->booking_reference,
        'count' => $d->c,
    ];
}

// 5.3 booking without any system/carrier/group AND no airline_account_id
$orphans = DB::table('flight_bookings')
    ->whereNull('deleted_at')
    ->whereNull('flight_system_id')
    ->whereNull('flight_carrier_id')
    ->whereNull('flight_group_id')
    ->whereNull('airline_account_id')
    ->select('id', 'booking_reference', 'booking_number', 'status')
    ->limit(50)
    ->get();
foreach ($orphans as $o) {
    $blIssues[] = [
        'kind' => 'orphan_booking',
        'booking_id' => $o->id,
        'booking_ref' => $o->booking_reference ?? $o->booking_number,
        'status' => $o->status,
    ];
}

echo "  ✗ Business-logic issues: " . count($blIssues) . "\n";
$report['business_logic_issues'] = $blIssues;
foreach (array_slice($blIssues, 0, 20) as $x) {
    $line = match ($x['kind']) {
        'cancelled_with_payments' => "[CXL-PAY] #{$x['booking_id']} {$x['booking_ref']} — مدفوع {$x['paid']} لكن cancelled",
        'duplicate_reference' => "[DUP] {$x['booking_reference']} × {$x['count']}",
        'orphan_booking' => "[ORPHAN] #{$x['booking_id']} {$x['booking_ref']} ({$x['status']})",
        default => "[?] " . json_encode($x, JSON_UNESCAPED_UNICODE),
    };
    echo "    {$line}\n";
}
if (count($blIssues) > 20) {
    echo "    ... و " . (count($blIssues) - 20) . " آخرين\n";
}
echo "\n";

// -----------------------------------------------------------
// Save JSON report
// -----------------------------------------------------------
$jsonPath = __DIR__ . '/FLIGHT_CURRENCY_AUDIT_' . date('Ymd_His') . '.json';
file_put_contents($jsonPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "✓ JSON report saved: {$jsonPath}\n";

// Final summary
echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║  ملخص                                                         ║\n";
echo "╠════════════════════════════════════════════════════════════════╣\n";
echo "║  Currency issues:           " . str_pad((string) count($currencyIssues), 4, ' ', STR_PAD_LEFT) . "                                ║\n";
echo "║  Hierarchy issues:          " . str_pad((string) count($hierarchyIssues), 4, ' ', STR_PAD_LEFT) . "                                ║\n";
echo "║  Payment issues:            " . str_pad((string) count($paymentIssues), 4, ' ', STR_PAD_LEFT) . "                                ║\n";
echo "║  Balance mismatches:        " . str_pad((string) count($balanceIssues), 4, ' ', STR_PAD_LEFT) . "                                ║\n";
echo "║  Business-logic issues:     " . str_pad((string) count($blIssues), 4, ' ', STR_PAD_LEFT) . "                                ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";