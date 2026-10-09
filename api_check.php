<?php

use App\Services\Finance\TreasuryService;
use Illuminate\Contracts\Console\Kernel;

chdir('/var/www/safarakealayna');
require '/var/www/safarakealayna/vendor/autoload.php';
$app = require '/var/www/safarakealayna/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Just call the service directly - bypasses controller
$svc = app(TreasuryService::class);
$overview = $svc->getTreasuryOverview();

echo "═══════════════════════════════════════\n";
echo "  What the API returns RIGHT NOW\n";
echo "═══════════════════════════════════════\n";
echo 'test_time: '.date('Y-m-d H:i:s')."\n\n";

if (isset($overview['trial_balance'])) {
    $tb = $overview['trial_balance'];
    echo "tourism.trial_balance:\n";
    echo '  profits              = '.number_format($tb['profits'] ?? 0, 2)." ← the +7,557 number?\n";
    echo '  gross_profits        = '.number_format($tb['gross_profits'] ?? 0, 2)."\n";
    echo '  operating_expenses   = '.number_format($tb['operating_expenses'] ?? 0, 2)."\n";
    echo '  variance             = '.number_format($tb['variance'] ?? 0, 2)."\n";
    echo '  status               = '.($tb['status'] ?? 'NULL')."\n";
}

if (isset($overview['office_trial_balance'])) {
    $otb = $overview['office_trial_balance'];
    echo "\noffice.trial_balance:\n";
    echo '  profits              = '.number_format($otb['profits'] ?? 0, 2)."\n";
    echo '  variance             = '.number_format($otb['variance'] ?? 0, 2)."\n";
    echo '  status               = '.($otb['status'] ?? 'NULL')."\n";
}
