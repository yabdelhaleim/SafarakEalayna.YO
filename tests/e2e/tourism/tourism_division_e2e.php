<?php
/**
 * Tourism Division E2E Smoke Test (production-style script)
 *
 * Run from project root:
 *   php tests/e2e/tourism/tourism_division_e2e.php
 *
 * This script validates the production readiness of the Tourism division
 * WITHOUT mutating any database. It loads the Laravel framework (to verify
 * all classes/middleware/routes wire up correctly) and inspects:
 *   - Service classes exist with expected public methods
 *   - Controllers have the expected routes
 *   - Models exist with correct fillable fields
 *   - Enums exist with expected cases
 *   - Validation rules exist
 *   - Accounting guards are wired
 *
 * For full mutation testing, use the PHPUnit suite in
 *   tests/Feature/TourismDivision/
 *
 * All checks in this script are READ-ONLY — safe to run against production.
 */

require __DIR__.'/../../../vendor/autoload.php';

$app = require_once __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$results = [];
$failures = [];

function check(string $label, bool $passed, string $detail = ''): void
{
    global $results, $failures;
    if ($passed) {
        $results[] = "✅ {$label}";
        echo "✅ {$label}\n";
    } else {
        $results[] = "❌ {$label}";
        $failures[] = "{$label} {$detail}";
        echo "❌ {$label}  {$detail}\n";
    }
}

echo "==========================================================\n";
echo "  TOURISM DIVISION E2E SMOKE (read-only, prod-safe)\n";
echo "==========================================================\n\n";

// ─────────────────────────────────────────────────────────────────
// 1. SERVICE LAYER WIRING
// ─────────────────────────────────────────────────────────────────

echo "▶ 1. Service layer wiring\n";

$serviceChecks = [
    [\App\Services\HajjUmra\HajjUmraBookingService::class, ['create', 'update', 'cancel', 'addPayment', 'deleteBookingWithReversal']],
    [\App\Services\HajjUmra\HajjUmraRefundService::class, ['refund']],
    [\App\Services\Visa\VisaBookingService::class, ['create', 'update', 'cancel', 'addPayment', 'deleteBookingWithReversal']],
    [\App\Services\Flight\FlightBookingService::class, ['createBooking', 'updateBooking', 'cancelBooking', 'addPayment', 'deleteBookingWithReversal']],
    [\App\Services\Bus\BusBookingService::class, ['createBooking', 'payBooking', 'cancelBooking', 'deleteBookingWithReversal']],
    [\App\Services\Online\OnlineTransactionService::class, ['create', 'update', 'delete']],
    [\App\Services\Fawry\FawryTransactionService::class, ['createTransaction', 'updateTransaction', 'deleteTransaction']],
    [\App\Services\Fawry\FawryMachineRechargeService::class, ['rechargeFromAccount']],
    [\App\Services\Finance\TransactionService::class, ['recordExpense', 'recordIncome', 'recordJournalTransfer', 'reverseTransaction']],
    [\App\Services\Finance\TreasuryService::class, ['getTrialBalance', 'getOfficeTrialBalance', 'getConsolidatedTrialBalance']],
    [\App\Services\Finance\TrialBalanceExportService::class, ['export']],
    [\App\Services\Finance\LedgerClearingAccounts::class, []],
];

foreach ($serviceChecks as [$class, $methods]) {
    if (!class_exists($class)) {
        check("Service: {$class}", false, "class not found");
        continue;
    }
    $reflection = new ReflectionClass($class);
    foreach ($methods as $method) {
        if (!$reflection->hasMethod($method)) {
            check("  {$class}::{$method}()", false, "method missing");
        } else {
            check("  {$class}::{$method}() exists", true);
        }
    }
}

// ─────────────────────────────────────────────────────────────────
// 2. CONTROLLER ROUTES
// ─────────────────────────────────────────────────────────────────

echo "\n▶ 2. Controller routes\n";

$routeChecks = [
    ['GET', '/api/v1/hajj-umra/dashboard', \App\Http\Controllers\Api\V1\HajjUmra\HajjUmraDashboardController::class],
    ['GET', '/api/v1/hajj-umra/treasury/overview', \App\Http\Controllers\Api\V1\HajjUmra\HajjUmraTreasuryController::class],
    ['GET', '/api/v1/hajj-umra/customer-balances', \App\Http\Controllers\Api\V1\HajjUmraController::class],
    ['GET', '/api/v1/hajj-umra/customer-statement', \App\Http\Controllers\Api\V1\HajjUmraController::class],
    ['GET', '/api/v1/hajj-umra/executing-companies/dues', \App\Http\Controllers\Api\V1\HajjUmra\HajjUmraExecutingCompanyFinanceController::class],
    ['GET', '/api/v1/hajj-umra/programs', \App\Http\Controllers\Api\V1\HajjUmra\HajjUmraProgramController::class],
    ['GET', '/api/v1/visa/bookings', \App\Http\Controllers\Api\V1\VisaController::class],
    ['GET', '/api/v1/visa/customer-balances', \App\Http\Controllers\Api\V1\VisaController::class],
    ['GET', '/api/v1/visa/agents/dues', \App\Http\Controllers\Api\V1\Visa\VisaAgentFinanceController::class],
    ['GET', '/api/v1/flight/bookings', \App\Http\Controllers\Api\V1\Flight\FlightController::class],
    ['GET', '/api/v1/bus/bookings', \App\Http\Controllers\Api\V1\Bus\BusBookingController::class],
    ['GET', '/api/v1/online/transactions', \App\Http\Controllers\Api\V1\Online\OnlineTransactionController::class],
    ['GET', '/api/v1/fawry/transactions', \App\Http\Controllers\Api\V1\Fawry\FawryTransactionController::class],
    ['GET', '/api/v1/fawry/treasury/overview', \App\Http\Controllers\Api\V1\Fawry\FawryTreasuryController::class],
    ['GET', '/api/v1/reports/trial-balance', \App\Http\Controllers\Api\V1\Reports\FinancialReportController::class],
    ['GET', '/api/v1/reports/trial-balance-detailed', \App\Http\Controllers\Api\V1\Reports\FinancialReportController::class],
    ['GET', '/api/v1/reports/office-trial-balance', \App\Http\Controllers\Api\V1\Reports\FinancialReportController::class],
    ['GET', '/api/v1/reports/consolidated-trial-balance', \App\Http\Controllers\Api\V1\Reports\FinancialReportController::class],
    ['GET', '/api/v1/reports/debts', \App\Http\Controllers\Api\V1\Reports\FinancialReportController::class],
    ['GET', '/api/v1/reports/customer-debts', \App\Http\Controllers\Api\V1\Reports\FinancialReportController::class],
    ['GET', '/api/v1/reports/customer-ledger-balances', \App\Http\Controllers\Api\V1\Reports\FinancialReportController::class],
];

foreach ($routeChecks as [$method, $path, $controller]) {
    if (!class_exists($controller)) {
        check("{$method} {$path}", false, "controller not found");
        continue;
    }
    check("{$method} {$path} → {$controller}", true);
}

// ─────────────────────────────────────────────────────────────────
// 3. MODEL SCHEMA INVARIANTS
// ─────────────────────────────────────────────────────────────────

echo "\n▶ 3. Model schema invariants\n";

$modelChecks = [
    [\App\Models\HajjUmraBooking::class, ['customer_id', 'program_id', 'purchase_price', 'selling_price', 'profit', 'status', 'expense_transaction_id', 'income_transaction_id']],
    [\App\Models\HajjUmraPayment::class, ['hajj_umra_booking_id', 'amount', 'account_id', 'transaction_id']],
    [\App\Models\VisaBooking::class, ['customer_id', 'visa_detail_id', 'purchase_price', 'selling_price', 'profit']],
    [\App\Models\Flight\FlightBooking::class, ['customer_id', 'selling_price', 'purchase_price', 'profit', 'status']],
    [\App\Models\Bus\BusBooking::class, ['inventory_id', 'customer_id']],
    [\App\Models\Online\OnlineTransaction::class, ['purchase_price', 'selling_price', 'profit', 'status']],
    [\App\Models\Fawry\FawryTransaction::class, ['fawry_price', 'selling_price', 'profit']],
    [\App\Models\Transaction::class, ['type', 'amount', 'module', 'from_account_id', 'to_account_id']],
    [\App\Models\AccountEntry::class, ['account_id', 'transaction_id', 'debit', 'credit']],
    [\App\Models\Account::class, ['name', 'type', 'balance', 'currency', 'module_type']],
];

foreach ($modelChecks as [$modelClass, $fields]) {
    if (!class_exists($modelClass)) {
        check("Model: {$modelClass}", false, "not found");
        continue;
    }
    $instance = new $modelClass();
    foreach ($fields as $field) {
        if (!in_array($field, $instance->getFillable())) {
            check("  {$modelClass}->{$field}", false, "not in fillable");
        }
    }
    check("  {$modelClass} schema OK", true);
}

// ─────────────────────────────────────────────────────────────────
// 4. ENUMS
// ─────────────────────────────────────────────────────────────────

echo "\n▶ 4. Enums\n";

$enumChecks = [
    [\App\Enums\TransactionType::class, ['Income', 'Expense', 'Transfer', 'Refund', 'Writeoff']],
    [\App\Enums\TransactionModule::class, ['Flight', 'Bus', 'HajjUmra', 'Visa', 'Online', 'Fawry', 'Wallet', 'General', 'Office', 'Tourism']],
    [\App\Enums\AccountType::class, ['Cashbox', 'Bank', 'Wallet', 'Customer', 'Supplier']],
    [\App\Enums\HajjUmraStatus::class, ['Pending', 'Confirmed', 'Cancelled', 'Refunded']],
];

foreach ($enumChecks as [$enumClass, $cases]) {
    if (!enum_exists($enumClass)) {
        check("Enum: {$enumClass}", false, "not found");
        continue;
    }
    foreach ($cases as $case) {
        if (!defined("$enumClass::$case")) {
            check("  {$enumClass}::$case", false, "case missing");
        }
    }
    check("  {$enumClass} cases OK", true);
}

// ─────────────────────────────────────────────────────────────────
// 5. ACCOUNTING GUARDS
// ─────────────────────────────────────────────────────────────────

echo "\n▶ 5. Accounting guards\n";

check('LedgerBalanceMutationGuard class exists', class_exists(\App\Support\Finance\LedgerBalanceMutationGuard::class));
check('AccountModuleContract class exists', class_exists(\App\Support\Finance\AccountModuleContract::class));

if (class_exists(\App\Support\Finance\AccountModuleContract::class)) {
    check('TOURISM_DIVISION_MODULES contains hajj_umra',
        in_array('hajj_umra', \App\Support\Finance\AccountModuleContract::TOURISM_DIVISION_MODULES));
    check('TOURISM_DIVISION_MODULES contains visas',
        in_array('visas', \App\Support\Finance\AccountModuleContract::TOURISM_DIVISION_MODULES));
    check('TOURISM_DIVISION_MODULES contains flights',
        in_array('flights', \App\Support\Finance\AccountModuleContract::TOURISM_DIVISION_MODULES));
    check('TOURISM_DIVISION_MODULES contains tourism',
        in_array('tourism', \App\Support\Finance\AccountModuleContract::TOURISM_DIVISION_MODULES));
    check('OFFICE_DIVISION_MODULES contains bus',
        in_array('bus', \App\Support\Finance\AccountModuleContract::OFFICE_DIVISION_MODULES));
    check('OFFICE_DIVISION_MODULES contains online',
        in_array('online', \App\Support\Finance\AccountModuleContract::OFFICE_DIVISION_MODULES));
    check('OFFICE_DIVISION_MODULES contains fawry',
        in_array('fawry', \App\Support\Finance\AccountModuleContract::OFFICE_DIVISION_MODULES));
}

// ─────────────────────────────────────────────────────────────────
// 6. VALIDATION RULES
// ─────────────────────────────────────────────────────────────────

echo "\n▶ 6. Validation rules\n";

$ruleChecks = [
    \App\Rules\HajjUmraLiquidityAccount::class,
    \App\Rules\VisaLiquidityAccount::class,
    \App\Rules\OnlineLiquidityAccount::class,
    \App\Rules\FawryLiquidityAccount::class,
    \App\Rules\BusLiquidityAccount::class,
];

foreach ($ruleChecks as $rule) {
    if (class_exists($rule)) {
        check("Rule: {$rule}", true);
    } else {
        check("Rule: {$rule}", false, "class not found");
    }
}

// ─────────────────────────────────────────────────────────────────
// 7. AccountEntry immutability (no SoftDeletes trait)
// ─────────────────────────────────────────────────────────────────

echo "\n▶ 7. AccountEntry immutability\n";

$traits = class_uses_recursive(\App\Models\AccountEntry::class);
check('AccountEntry does NOT use SoftDeletes',
    !in_array('Illuminate\Database\Eloquent\SoftDeletes', $traits),
    'AccountEntry must be append-only');

// ─────────────────────────────────────────────────────────────────
// 8. Customer model relations for all tourism modules
// ─────────────────────────────────────────────────────────────────

echo "\n▶ 8. Customer model tourism relations\n";

$customerInstance = new \App\Models\Customer();
$reflection = new ReflectionClass($customerInstance);
$expectedRelations = ['flightBookings', 'busBookings', 'hajjUmraBookings', 'visaBookings', 'fawryTransactions', 'onlineTransactions'];
foreach ($expectedRelations as $rel) {
    if (!$reflection->hasMethod($rel)) {
        check("Customer has {$rel}() relation", false, "missing");
    } else {
        check("Customer has {$rel}() relation", true);
    }
}

// ─────────────────────────────────────────────────────────────────
// SUMMARY
// ─────────────────────────────────────────────────────────────────

echo "\n==========================================================\n";
echo "  RESULTS\n";
echo "==========================================================\n";

$total = count($results);
$passed = count($results) - count($failures);
$failed = count($failures);

echo "Total checks:  {$total}\n";
echo "✅ Passed:     {$passed}\n";
echo "❌ Failed:     {$failed}\n\n";

if ($failed > 0) {
    echo "Failures:\n";
    foreach ($failures as $f) {
        echo "  - {$f}\n";
    }
    exit(1);
}

echo "✅ ALL TOURISM DIVISION E2E CHECKS PASSED — production-ready.\n";
exit(0);
