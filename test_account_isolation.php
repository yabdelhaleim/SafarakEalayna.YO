<?php
/**
 * Test 1: Simple Account fetch
 */
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Test 1: Account::first() — does the cast work?\n";
echo "═══════════════════════════════════════════════════════════════\n";

try {
    $a = \App\Models\Account::first();
    if ($a === null) {
        echo "No accounts in DB. Cannot test.\n";
    } else {
        echo "First account id: " . $a->id . " name: " . $a->name . "\n";
        echo "Raw type attribute: ";
        var_dump($a->getAttributes()['type']);
        echo "Cast type: ";
        var_dump($a->type);
        echo "Type gettype: " . gettype($a->type) . "\n";
        if ($a->type instanceof \BackedEnum) {
            echo "Is BackedEnum: YES\n";
            echo "String cast: " . (string)$a->type . "\n";
            echo "->value: " . $a->type->value . "\n";
        } else {
            echo "Is BackedEnum: NO (problem!)\n";
        }
    }
} catch (\Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    echo "Class: " . get_class($e) . "\n";
}

echo "\n";

/**
 * Test 2: Account::create with enum type
 */
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Test 2: Account::create with enum type\n";
echo "═══════════════════════════════════════════════════════════════\n";

try {
    $uniqueName = 'Test Writeoff ' . rand(1000, 9999);
    $a = \App\Models\Account::create([
        'name'       => $uniqueName,
        'type'       => \App\Enums\AccountType::Expense,
        'currency'   => 'EGP',
        'balance'    => 0,
        'is_active'  => 1,
        'owner_type' => 'owner',
    ]);
    echo "Created id: " . $a->id . "\n";
    echo "Created type: ";
    var_dump($a->type);
    \App\Models\Account::find($a->id)->delete();
    echo "Cleaned up test row\n";
} catch (\Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    echo "Class: " . get_class($e) . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}

echo "\n";

/**
 * Test 3: DB::table() insert then fetch
 */
echo "═══════════════════════════════════════════════════════════════\n";
echo "  Test 3: DB::table() insert + fetch via model\n";
echo "═══════════════════════════════════════════════════════════════\n";

try {
    $now = now();
    $uniqueName = 'Test Writeoff DB ' . rand(1000, 9999);
    DB::table('accounts')->insert([
        'name'        => $uniqueName,
        'type'        => 'expense',
        'currency'    => 'EGP',
        'balance'     => 0,
        'is_active'   => 1,
        'owner_type'  => 'owner',
        'created_at'  => $now,
        'updated_at'  => $now,
    ]);
    echo "DB::table insert OK\n";

    $a = \App\Models\Account::where('name', $uniqueName)->first();
    if ($a === null) {
        echo "Could not fetch the row we just inserted\n";
    } else {
        echo "Fetched id: " . $a->id . "\n";
        echo "Type gettype: " . gettype($a->type) . "\n";
        if ($a->type instanceof \BackedEnum) {
            echo "Type is enum with value: " . $a->type->value . "\n";
        } else {
            echo "Type is NOT enum\n";
        }
        $a->delete();
        echo "Cleaned up test row\n";
    }
} catch (\Throwable $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
    echo "Class: " . get_class($e) . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
