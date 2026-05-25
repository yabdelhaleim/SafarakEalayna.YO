<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

// Check treasury resources directly
$classes = [
    App\Filament\Admin\Resources\FlightTreasuries\FlightTreasuryResource::class,
    App\Filament\Admin\Resources\FlightGeneralTreasuries\FlightGeneralTreasuryResource::class,
    App\Filament\Admin\Resources\FlightCarriers\FlightCarrierResource::class,
];

foreach ($classes as $class) {
    echo $class . PHP_EOL;
    echo '  shouldRegisterNavigation: ' . ($class::shouldRegisterNavigation() ? 'yes' : 'no') . PHP_EOL;
    echo '  navigationGroup: ' . ($class::getNavigationGroup() ?? '(null)') . PHP_EOL;
    echo '  navigationSort: ' . ($class::getNavigationSort() ?? '(null)') . PHP_EOL;
    echo '  navigationLabel: ' . ($class::getNavigationLabel() ?? '(null)') . PHP_EOL;
    echo PHP_EOL;
}
