<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Enums\VisaEntryType;
use App\Enums\VisaPaymentMethod;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use Illuminate\Contracts\Console\Kernel;

echo "=== VISA ENUMS INSPECTION ===\n\n";

echo "VisaStatus Cases:\n";
foreach (VisaStatus::cases() as $case) {
    echo " - {$case->name} => '{$case->value}'\n";
}
echo "\n";

echo "VisaType Cases:\n";
foreach (VisaType::cases() as $case) {
    echo " - {$case->name} => '{$case->value}'\n";
}
echo "\n";

echo "VisaEntryType Cases:\n";
foreach (VisaEntryType::cases() as $case) {
    echo " - {$case->name} => '{$case->value}'\n";
}
echo "\n";

if (class_exists(VisaPaymentMethod::class)) {
    echo "VisaPaymentMethod Cases:\n";
    foreach (VisaPaymentMethod::cases() as $case) {
        echo " - {$case->name} => '{$case->value}'\n";
    }
}
