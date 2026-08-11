<?php
/**
 * DEBUG — Calls TreasuryService directly to see what it returns.
 * Run via:  cd /var/www/safarakealayna && php scripts/debug_tourism_call.php
 *
 * Read-only.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Finance\TreasuryService;

echo "==========================================\n";
echo "  Calling TreasuryService directly\n";
echo "==========================================\n\n";

$svc = app(TreasuryService::class);

// [A] trial_balance — same path the dashboard uses
echo "[A] GET: trial_balance (full)\n";
$tb = $svc->getTrialBalance();
if ($tb) {
    foreach (['base_capital', 'gross_profits', 'operating_expenses', 'profits',
              'current_capital', 'expected_capital', 'variance', 'status'] as $k) {
        $val = $tb[$k] ?? null;
        if (is_numeric($val)) {
            echo "    " . str_pad($k, 22) . " = " . number_format((float)$val, 2) . "\n";
        } else {
            echo "    " . str_pad($k, 22) . " = " . ($val ?? 'NULL') . "\n";
        }
    }
} else {
    echo "    (no trial_balance returned)\n";
}

echo "\n[B] GET: office_trial_balance (full)\n";
$otb = $svc->getOfficeTrialBalance();
if ($otb) {
    foreach (['base_capital', 'gross_profits', 'operating_expenses', 'profits',
              'current_capital', 'expected_capital', 'variance', 'status'] as $k) {
        $val = $otb[$k] ?? null;
        if (is_numeric($val)) {
            echo "    " . str_pad($k, 22) . " = " . number_format((float)$val, 2) . "\n";
        } else {
            echo "    " . str_pad($k, 22) . " = " . ($val ?? 'NULL') . "\n";
        }
    }
}

// [C, D, E] Direct method calls
echo "\n[C] DIRECT: calculateDivisionNetProfits('tourism') = "
    . number_format($svc->calculateDivisionNetProfits('tourism'), 2) . "\n";

echo "[D] DIRECT: calculateDynamicProfits('tourism') = "
    . number_format($svc->calculateDynamicProfits('tourism'), 2) . "\n";

echo "[E] DIRECT: calculateOperatingExpenses('tourism') = "
    . number_format($svc->calculateOperatingExpenses('tourism'), 2) . "\n";

echo "\n==========================================\n";
echo "  Expected dashboard values: trial_balance.profits = -8,495\n";
echo "==========================================\n";
