<?php
/**
 * Targeted test: Why is (string)$enum failing?
 */

echo "═══════════════════════════════════════════════════════════════\n";
echo "  Test: string cast on BackedEnum\n";
echo "═══════════════════════════════════════════════════════════════\n";

echo "PHP version: " . PHP_VERSION . "\n\n";

// Test 1: Direct (string) cast on enum
echo "Test 1: Direct (string)\\$enum\n";
$e = \App\Enums\AccountType::Cashbox;
try {
    $result = (string)$e;
    echo "  OK: '$result'\n";
} catch (\Throwable $ex) {
    echo "  FAILED: " . get_class($ex) . ": " . $ex->getMessage() . "\n";
}

// Test 2: ->value
echo "\nTest 2: ->value\n";
try {
    $result = $e->value;
    echo "  OK: '$result'\n";
} catch (\Throwable $ex) {
    echo "  FAILED: " . get_class($ex) . ": " . $ex->getMessage() . "\n";
}

// Test 3: ->name
echo "\nTest 3: ->name\n";
try {
    $result = $e->name;
    echo "  OK: '$result'\n";
} catch (\Throwable $ex) {
    echo "  FAILED: " . get_class($ex) . ": " . $ex->getMessage() . "\n";
}

// Test 4: Check Stringable interface
echo "\nTest 4: instanceof Stringable\n";
echo "  Is Stringable: " . ($e instanceof \Stringable ? "YES" : "NO") . "\n";

// Test 5: __toString() direct
echo "\nTest 5: __toString() direct\n";
try {
    $result = $e->__toString();
    echo "  OK: '$result'\n";
} catch (\Throwable $ex) {
    echo "  FAILED: " . get_class($ex) . ": " . $ex->getMessage() . "\n";
}

// Test 6: Cast via var_export + trim
echo "\nTest 6: var_export + trim\n";
try {
    $result = trim(var_export($e, true), "'");
    echo "  OK: '$result'\n";
} catch (\Throwable $ex) {
    echo "  FAILED: " . get_class($ex) . ": " . $ex->getMessage() . "\n";
}

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  CONCLUSION\n";
echo "═══════════════════════════════════════════════════════════════\n";
echo "  - Test 1 (string cast): WORKED? → use it in the script\n";
echo "  - Test 1 (string cast): FAILED? → use ->value (\\\$enum->value) instead\n";
echo "═══════════════════════════════════════════════════════════════\n";
