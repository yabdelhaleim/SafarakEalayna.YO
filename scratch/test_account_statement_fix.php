<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Account;
use App\Services\Finance\AccountService;
use App\Http\Resources\Finance\AccountEntryResource;

try {
    $account = Account::findOrFail(29);
    $service = app(AccountService::class);
    
    echo "Account name: " . $account->name . "\n";
    echo "Account balance: " . $account->balance . "\n\n";

    // Test without filters
    echo "--- Fetching statement (No filters) ---\n";
    $result = $service->getAccountStatement($account, []);
    
    echo "Stats:\n";
    print_r($result['stats']);
    
    echo "\nItems count: " . count($result['items']) . "\n";
    
    // Test serialization of first item
    if (count($result['items']) > 0) {
        $firstItem = $result['items'][0];
        echo "First item raw class: " . get_class($firstItem) . "\n";
        echo "First item debit: " . $firstItem->debit . ", credit: " . $firstItem->credit . ", balance_after: " . $firstItem->balance_after . "\n";
        
        $resource = new AccountEntryResource($firstItem);
        $serialized = $resource->toArray(request());
        echo "\nSerialized Resource structure:\n";
        print_r($serialized);
    }

    // Test with date filter (e.g. from_date = today)
    echo "\n--- Fetching statement with from_date filter ---\n";
    $today = date('Y-m-d');
    $resultWithFilter = $service->getAccountStatement($account, ['from_date' => $today]);
    echo "Stats with filter:\n";
    print_r($resultWithFilter['stats']);
    echo "Filtered items count: " . count($resultWithFilter['items']) . "\n";

    echo "\nSUCCESS: Statement retrieved and formatted correctly!\n";

} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
