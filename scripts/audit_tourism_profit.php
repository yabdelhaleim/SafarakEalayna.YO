<?php
/**
 * AUDIT — Why is Tourism showing a loss?
 * Read-only diagnostic. Run on production.
 *
 * Helps answer: where does the -8,495 EGP loss in tourism come from?
 *   - real bookings with negative profits?
 *   - refunds/expenses not booked as bookings?
 *   - transactions on module='flight' that don't match any booking?
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "\n";
echo "════════════════════════════════════════════════════════════════════\n";
echo "  TOURISM LOSS AUDIT — Why is tourism at -8,495 EGP?\n";
echo "════════════════════════════════════════════════════════════════════\n\n";

echo "Environment: " . app()->environment() . "\n\n";

// ── 1. All real flight bookings + profit ──────────────────────────────────
echo "▶ [1] Real flight bookings (excluding deleted E2E backups)\n";
echo "  " . str_repeat('─', 90) . "\n";
$bookings = DB::select("
    SELECT status, currency,
           COUNT(*) AS cnt,
           COALESCE(SUM(selling_price), 0)  AS sell_sum,
           COALESCE(SUM(purchase_price), 0) AS buy_sum,
           COALESCE(SUM(profit), 0)         AS profit_sum,
           COALESCE(SUM(CASE WHEN selling_price_foreign IS NOT NULL AND selling_price_foreign > 0
                             THEN selling_price_foreign ELSE 0 END), 0) AS foreign_sell
    FROM flight_bookings
    WHERE deleted_at IS NULL
    GROUP BY status, currency
    ORDER BY status, currency
");
foreach ($bookings as $b) {
    printf("    %-10s | %-4s | %3d bookings | sell=%12.2f | buy=%12.2f | profit=%12.2f\n",
        $b->status ?? 'NULL', $b->currency ?? 'NULL', $b->cnt,
        $b->sell_sum, $b->buy_sum, $b->profit_sum);
}

$total = DB::selectOne("
    SELECT COUNT(*) AS cnt, COALESCE(SUM(profit), 0) AS total_profit
    FROM flight_bookings WHERE deleted_at IS NULL
");
echo "    ──────────────────────────────────────────────────\n";
printf("    TOTAL bookings: %d | sum(profit) = %.2f EGP\n", $total->cnt, $total->total_profit);
echo "\n";

// ── 2. Transactions linked to flight bookings (sale_gl_transaction_id) ─
echo "▶ [2] Flight-related transactions\n";
echo "  " . str_repeat('─', 90) . "\n";

$txStats = DB::select("
    SELECT t.id, t.amount, t.type, t.from_account_id, t.to_account_id, t.notes, t.module
    FROM transactions t
    WHERE t.module IN ('flight', 'tourism', 'travel')
       OR t.related_type LIKE '%flight%'
       OR t.related_type LIKE '%tourism%'
    ORDER BY t.id
");
echo "    Found " . count($txStats) . " flight/tourism-marked transactions\n";
foreach ($txStats as $t) {
    $note = mb_strimwidth((string)($t->notes ?? ''), 0, 60, '...');
    printf("    tx #%d | %.2f | %-12s | from=%d to=%d | %s\n",
        $t->id, $t->amount, $t->type ?? 'NULL', $t->from_account_id ?? 0, $t->to_account_id ?? 0, $note);
}

$txSum = DB::selectOne("
    SELECT
        COALESCE(SUM(CASE WHEN t.to_account_id IN (SELECT id FROM accounts WHERE type='cashbox' OR name LIKE '%كاش%') THEN t.amount ELSE 0 END), 0) AS cash_in,
        COALESCE(SUM(CASE WHEN t.from_account_id IN (SELECT id FROM accounts WHERE type='cashbox' OR name LIKE '%كاش%') THEN t.amount ELSE 0 END), 0) AS cash_out
    FROM transactions t
    WHERE t.module IN ('flight', 'tourism', 'travel')
       OR t.related_type LIKE '%flight%'
       OR t.related_type LIKE '%tourism%'
");
echo "    ─── Cash in/out for these tx: in=" . number_format($txSum->cash_in, 2) . " out=" . number_format($txSum->cash_out, 2) . "\n\n";

// ── 3. Customer debts in flight module ──────────────────────────────────
echo "▶ [3] Customers with flight debt (real only)\n";
echo "  " . str_repeat('─', 90) . "\n";

if (Schema::hasTable('customers')) {
    $debt = DB::select("
        SELECT c.id, c.full_name,
               COALESCE(SUM(fb.selling_price - COALESCE(fb.paid_amount, 0)), 0) AS outstanding
        FROM customers c
        INNER JOIN flight_bookings fb ON fb.customer_id = c.id AND fb.deleted_at IS NULL
        GROUP BY c.id, c.full_name
        HAVING outstanding > 0
        ORDER BY outstanding DESC
        LIMIT 20
    ");
    if (empty($debt)) {
        echo "    No customers with flight debt\n";
    } else {
        foreach ($debt as $d) {
            printf("    cust #%d %s | outstanding=%.2f\n", $d->id, $d->full_name ?? 'NULL', $d->outstanding);
        }
    }
}
echo "\n";

// ── 4. Look at deletion script's impact (sanity check) ──────────────────
echo "▶ [4] Tourism module balance: are there any accounts affected?\n";
echo "  " . str_repeat('─', 90) . "\n";

$tourAccounts = DB::select("
    SELECT id, name, balance, type, module_type
    FROM accounts
    WHERE name LIKE '%سياح%' OR name LIKE '%flight%' OR name LIKE '%طيران%' OR name LIKE '%سفر%' OR name LIKE '%tourism%'
");
foreach ($tourAccounts as $a) {
    printf("    id=%d | %s | balance=%.2f | type=%s | module=%s\n",
        $a->id, $a->name, $a->balance, $a->type ?? 'NULL', $a->module_type ?? 'NULL');
}
echo "\n";

// ── 5. Find the dashboard's view/controller ──────────────────────────────
echo "▶ [5] Where does the dashboard compute 'أرباح السياحة'?\n";
echo "  " . str_repeat('─', 90) . "\n";

// Try to grep the codebase for the calculation
$candidateFiles = [];
$paths = [
    __DIR__ . '/../app/Http/Controllers',
    __DIR__ . '/../resources/js',
];
foreach ($paths as $base) {
    if (!is_dir($base)) continue;
    $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));
    foreach ($iter as $f) {
        if (!preg_match('/\.(php|vue|js|ts)$/', $f->getPathname())) continue;
        $content = @file_get_contents($f->getPathname());
        if (preg_match('/أرباح|سياحة|tourism_profit|tourismProfit/', $content)) {
            $candidateFiles[] = $f->getPathname();
        }
    }
}
echo "    Code paths referencing 'أرباح السياحة':\n";
foreach ($candidateFiles as $f) {
    $rel = str_replace(dirname(__DIR__) . '/', '', $f);
    echo "      - {$rel}\n";
}

if (empty($candidateFiles)) {
    echo "    (no direct match — maybe search in storage/app or via api routes)\n";
}

echo "\n════════════════════════════════════════════════════════════════════\n";
echo "  AUDIT COMPLETE\n";
echo "════════════════════════════════════════════════════════════════════\n\n";