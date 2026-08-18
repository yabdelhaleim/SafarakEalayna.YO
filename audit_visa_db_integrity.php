<?php
/**
 * ════════════════════════════════════════════════════════════════════════════
 *  VISA MODULE — DATABASE INTEGRITY AUDIT
 * ════════════════════════════════════════════════════════════════════════════
 *
 *  Independent audit of Visa Module database integrity. Mirrors
 *  audit_bus_transaction_types.php. Three checks:
 *
 *  A) Transaction-type classification:
 *     Re-derive the expected type for each visa transaction from the
 *     account-level direction (no notes-based heuristics) and compare
 *     to the stored `type` column.
 *
 *     Expected-type rules:
 *       - from=customer → office liquidity → income (sale payment)
 *       - from=customer → income_clearing → income (clearing)
 *       - from=office liquidity → customer → refund (cash out)
 *       - from=office liquidity → supplier (visa agent) → expense
 *       - from=office liquidity → office liquidity → transfer
 *       - from=office liquidity → expense_clearing → expense
 *       - from=income_clearing → office liquidity → income (sale recognition)
 *       - from=expense_clearing → supplier → expense reversal
 *       - any other → manual review
 *
 *  B) Foreign-key integrity:
 *     - visa_bookings.customer_id must exist in customers
 *     - visa_bookings.visa_detail_id must exist in visa_details
 *     - visa_payments.visa_booking_id must exist in visa_bookings
 *     - visa_payments.account_id must exist in accounts
 *     - visa_details.visa_agent_id (if not null) must exist in visa_agents
 *
 *  C) Balance reconciliation:
 *     For every account whose entries include at least one Visa transaction,
 *     verify that `balance == SUM(credit) - SUM(debit)`.
 *
 *  Safety:
 *    - Refuses to run on APP_ENV=production.
 *    - Read-only: no writes.
 *
 *  Usage:
 *    cd C:\travile\SafarakEalayna
 *    php audit_visa_db_integrity.php
 *
 *  Output:
 *    - Console report (✓/✗/⚠ per row + summary)
 *    - JSON results in storage/logs/visa_db_integrity_20260814.json
 */

declare(strict_types=1);

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\VisaBooking;
use App\Models\VisaPayment;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Safety gate
if (app()->environment('production')) {
    echo "❌ ABORT: This script is for LOCAL/TESTING only. APP_ENV=production detected.\n";
    exit(1);
}

$runMarker = 'Visa DB Integrity 2026-08-14 ' . substr(md5((string) microtime(true)), 0, 6);
echo "\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  VISA MODULE — DATABASE INTEGRITY AUDIT\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  Run marker: {$runMarker}\n";
echo "  APP_ENV: " . app()->environment() . "\n";
echo "  DB: " . config('database.connections.' . config('database.default') . '.database') . "\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

$results = [
    'started_at' => date('Y-m-d H:i:s'),
    'finished_at' => null,
    'type_check' => ['ok' => 0, 'mismatch' => 0, 'review' => 0, 'total' => 0, 'by_actual' => [], 'by_expected' => []],
    'fk_check' => ['orphans' => [], 'checked' => 0],
    'balance_check' => ['imbalanced' => [], 'verified' => 0],
];

/**
 * Derive the expected transaction type from the account type direction.
 *
 * Direction matrix:
 * ┌─────────────────────────┬────────────────────────┬────────────────────────┐
 * │ from \ to               │ office liquidity       │ customer               │
 * ├─────────────────────────┼────────────────────────┼────────────────────────┤
 * │ office liquidity        │ transfer               │ refund                 │
 * │ customer                │ income                 │ (illegal)              │
 * │ supplier (visa agent)   │ (review)               │ (illegal)              │
 * │ income_clearing         │ income                 │ (illegal)              │
 * │ expense_clearing        │ (review)               │ (illegal)              │
 * └─────────────────────────┴────────────────────────┴────────────────────────┘
 */
function expectedType(string $fromType, string $toType): ?string
{
    // Office liquidity (cashbox/bank/wallet) → customer → refund
    if (in_array($fromType, ['cashbox', 'bank', 'wallet'], true) && $toType === 'customer') {
        return 'refund';
    }
    // Office liquidity → office liquidity → transfer
    if (in_array($fromType, ['cashbox', 'bank', 'wallet'], true) && in_array($toType, ['cashbox', 'bank', 'wallet'], true)) {
        return 'transfer';
    }
    // Office liquidity → supplier (visa agent) → expense
    if (in_array($fromType, ['cashbox', 'bank', 'wallet'], true) && $toType === 'supplier') {
        return 'expense';
    }
    // Office liquidity → expense_clearing → expense
    if (in_array($fromType, ['cashbox', 'bank', 'wallet'], true) && $toType === 'expense_clearing') {
        return 'expense';
    }
    // Customer → office liquidity → income
    if ($fromType === 'customer' && in_array($toType, ['cashbox', 'bank', 'wallet'], true)) {
        return 'income';
    }
    // Customer → income_clearing → income
    if ($fromType === 'customer' && $toType === 'income_clearing') {
        return 'income';
    }
    // Income_clearing → office liquidity → income (sale recognition)
    if ($fromType === 'income_clearing' && in_array($toType, ['cashbox', 'bank', 'wallet'], true)) {
        return 'income';
    }
    // Income_clearing → customer → income (sale recognition)
    if ($fromType === 'income_clearing' && $toType === 'customer') {
        return 'income';
    }
    // Expense_clearing → supplier → expense reversal
    if ($fromType === 'expense_clearing' && $toType === 'supplier') {
        return 'expense';
    }
    // Expense_clearing → office liquidity → expense reversal
    if ($fromType === 'expense_clearing' && in_array($toType, ['cashbox', 'bank', 'wallet'], true)) {
        return 'expense';
    }
    return null; // Manual review
}

// ═══════════════════════════════════════════════════════════════════════════
// A) TRANSACTION-TYPE CLASSIFICATION
// ═══════════════════════════════════════════════════════════════════════════
echo "▸ A) Transaction-type classification (Visa module transactions):\n\n";

$rows = Transaction::query()
    ->where('module', TransactionModule::Visa->value)
    ->with(['fromAccount:id,name,type', 'toAccount:id,name,type'])
    ->orderBy('id')
    ->get();

printf("%-8s %-15s %-13s %-15s %-13s %-12s %-10s %s\n",
    'tx#', 'from_type', 'from_name', 'to_name', 'to_type', 'actual', 'expected', 'verdict');
echo str_repeat('─', 120), "\n";

foreach ($rows as $tx) {
    $fromType = $tx->fromAccount?->type instanceof AccountType
        ? $tx->fromAccount->type->value
        : (string) ($tx->fromAccount?->type ?? 'null');
    $toType = $tx->toAccount?->type instanceof AccountType
        ? $tx->toAccount->type->value
        : (string) ($tx->toAccount?->type ?? 'null');

    $fromName = mb_substr((string) ($tx->fromAccount?->name ?? '—'), 0, 13);
    $toName = mb_substr((string) ($tx->toAccount?->name ?? '—'), 0, 13);

    $expected = expectedType($fromType, $toType);
    $actual = $tx->type instanceof TransactionType ? $tx->type->value : (string) $tx->type;

    $verdict = '⚠ REVIEW';
    if ($expected === null) {
        $verdict = '⚠ REVIEW';
        $results['type_check']['review']++;
    } elseif ($expected === $actual) {
        $verdict = '✓ OK';
        $results['type_check']['ok']++;
    } else {
        $verdict = '✗ MISMATCH';
        $results['type_check']['mismatch']++;
    }

    $results['type_check']['total']++;
    $results['type_check']['by_actual'][$actual] = ($results['type_check']['by_actual'][$actual] ?? 0) + 1;
    $results['type_check']['by_expected'][$expected ?? 'review'] = ($results['type_check']['by_expected'][$expected ?? 'review'] ?? 0) + 1;

    printf("%-8d %-15s %-13s %-15s %-13s %-12s %-12s %s\n",
        $tx->id,
        $fromType,
        $fromName,
        $toName,
        $toType,
        $actual,
        $expected ?? 'review',
        $verdict,
    );
}

echo "\n";
echo "  ── Type-check summary:\n";
echo "  Total:    {$results['type_check']['total']}\n";
echo "  ✓ OK:      {$results['type_check']['ok']}\n";
echo "  ✗ MISMATCH:{$results['type_check']['mismatch']}\n";
echo "  ⚠ REVIEW:  {$results['type_check']['review']}\n\n";

// ═══════════════════════════════════════════════════════════════════════════
// B) FOREIGN-KEY INTEGRITY
// ═══════════════════════════════════════════════════════════════════════════
echo "▸ B) Foreign-key integrity check:\n\n";

// visa_bookings.customer_id
$orphanBookingCustomers = DB::table('visa_bookings')
    ->leftJoin('customers', 'visa_bookings.customer_id', '=', 'customers.id')
    ->whereNull('customers.id')
    ->whereNull('visa_bookings.deleted_at')
    ->select('visa_bookings.id', 'visa_bookings.customer_id')
    ->get();
$results['fk_check']['checked']++;

// visa_bookings.visa_detail_id
$orphanBookingDetails = DB::table('visa_bookings')
    ->leftJoin('visa_details', 'visa_bookings.visa_detail_id', '=', 'visa_details.id')
    ->whereNull('visa_details.id')
    ->whereNull('visa_bookings.deleted_at')
    ->select('visa_bookings.id', 'visa_bookings.visa_detail_id')
    ->get();
$results['fk_check']['checked']++;

// visa_payments.visa_booking_id
$orphanPayments = DB::table('visa_payments')
    ->leftJoin('visa_bookings', 'visa_payments.visa_booking_id', '=', 'visa_bookings.id')
    ->whereNull('visa_bookings.id')
    ->select('visa_payments.id', 'visa_payments.visa_booking_id')
    ->get();
$results['fk_check']['checked']++;

// visa_payments.account_id
$orphanPaymentAccounts = DB::table('visa_payments')
    ->leftJoin('accounts', 'visa_payments.account_id', '=', 'accounts.id')
    ->whereNull('accounts.id')
    ->select('visa_payments.id', 'visa_payments.account_id')
    ->get();
$results['fk_check']['checked']++;

// visa_details.visa_agent_id
$orphanDetailAgents = DB::table('visa_details')
    ->leftJoin('visa_agents', 'visa_details.visa_agent_id', '=', 'visa_agents.id')
    ->whereNotNull('visa_details.visa_agent_id')
    ->whereNull('visa_agents.id')
    ->select('visa_details.id', 'visa_details.visa_agent_id')
    ->get();
$results['fk_check']['checked']++;

if ($orphanBookingCustomers->count() > 0) {
    $results['fk_check']['orphans'][] = ['type' => 'booking→customer', 'count' => $orphanBookingCustomers->count(), 'ids' => $orphanBookingCustomers->pluck('id')->take(10)->all()];
    echo "  ✗ visa_bookings.customer_id: {$orphanBookingCustomers->count()} orphans\n";
} else {
    echo "  ✓ visa_bookings.customer_id: no orphans\n";
}
if ($orphanBookingDetails->count() > 0) {
    $results['fk_check']['orphans'][] = ['type' => 'booking→detail', 'count' => $orphanBookingDetails->count(), 'ids' => $orphanBookingDetails->pluck('id')->take(10)->all()];
    echo "  ✗ visa_bookings.visa_detail_id: {$orphanBookingDetails->count()} orphans\n";
} else {
    echo "  ✓ visa_bookings.visa_detail_id: no orphans\n";
}
if ($orphanPayments->count() > 0) {
    $results['fk_check']['orphans'][] = ['type' => 'payment→booking', 'count' => $orphanPayments->count(), 'ids' => $orphanPayments->pluck('id')->take(10)->all()];
    echo "  ✗ visa_payments.visa_booking_id: {$orphanPayments->count()} orphans\n";
} else {
    echo "  ✓ visa_payments.visa_booking_id: no orphans\n";
}
if ($orphanPaymentAccounts->count() > 0) {
    $results['fk_check']['orphans'][] = ['type' => 'payment→account', 'count' => $orphanPaymentAccounts->count(), 'ids' => $orphanPaymentAccounts->pluck('id')->take(10)->all()];
    echo "  ✗ visa_payments.account_id: {$orphanPaymentAccounts->count()} orphans\n";
} else {
    echo "  ✓ visa_payments.account_id: no orphans\n";
}
if ($orphanDetailAgents->count() > 0) {
    $results['fk_check']['orphans'][] = ['type' => 'detail→agent', 'count' => $orphanDetailAgents->count(), 'ids' => $orphanDetailAgents->pluck('id')->take(10)->all()];
    echo "  ✗ visa_details.visa_agent_id: {$orphanDetailAgents->count()} orphans\n";
} else {
    echo "  ✓ visa_details.visa_agent_id: no orphans\n";
}

echo "\n";

// ═══════════════════════════════════════════════════════════════════════════
// C) BALANCE RECONCILIATION
// ═══════════════════════════════════════════════════════════════════════════
echo "▸ C) Balance reconciliation (every account with at least one Visa transaction):\n\n";

// Find account IDs touched by Visa transactions
$accountIdsFromTx = Transaction::query()
    ->where('module', TransactionModule::Visa->value)
    ->get(['from_account_id', 'to_account_id'])
    ->flatMap(fn ($tx) => [$tx->from_account_id, $tx->to_account_id])
    ->unique()
    ->filter()
    ->values();

// Plus accounts touched by VisaPayments (some payments may not have a transaction)
$accountIdsFromPayments = VisaPayment::query()->pluck('account_id')->unique()->filter()->values();
$bookingAccountIds = VisaBooking::query()->pluck('account_id')->unique()->filter()->values();

$allAccountIds = $accountIdsFromTx->merge($accountIdsFromPayments)->merge($bookingAccountIds)
    ->unique()
    ->filter()
    ->values();

$imbalanced = [];
foreach ($allAccountIds as $accountId) {
    $account = Account::find($accountId);
    if (! $account) {
        continue;
    }
    $entries = AccountEntry::where('account_id', $accountId)->get();
    if ($entries->isEmpty()) {
        continue;
    }
    $entriesSum = round($entries->sum(fn ($e) => (float) $e->credit - (float) $e->debit), 2);
    $actual = round((float) $account->balance, 2);
    if (abs($entriesSum - $actual) > 0.01) {
        $imbalanced[] = [
            'id' => $account->id,
            'name' => $account->name,
            'currency' => $account->currency,
            'expected' => $entriesSum,
            'actual' => $actual,
            'diff' => round($entriesSum - $actual, 2),
        ];
    }
    $results['balance_check']['verified']++;
}

if (empty($imbalanced)) {
    echo "  ✓ All {$results['balance_check']['verified']} accounts (touched by Visa) balance == SUM(credit)-SUM(debit)\n";
} else {
    echo "  ✗ Imbalanced accounts: " . count($imbalanced) . "\n";
    foreach ($imbalanced as $ia) {
        echo "    - #{$ia['id']} {$ia['name']} ({$ia['currency']}): expected {$ia['expected']} actual {$ia['actual']} diff {$ia['diff']}\n";
    }
    $results['balance_check']['imbalanced'] = $imbalanced;
}

echo "\n";

// ═══════════════════════════════════════════════════════════════════════════
// FINAL VERDICT
// ═══════════════════════════════════════════════════════════════════════════
$results['finished_at'] = date('Y-m-d H:i:s');

$typeVerdict = $results['type_check']['mismatch'] === 0 ? '✓ PASS' : '✗ FAIL';
$fkVerdict = empty($results['fk_check']['orphans']) ? '✓ PASS' : '✗ FAIL';
$balanceVerdict = empty($results['balance_check']['imbalanced']) ? '✓ PASS' : '✗ FAIL';

echo "═══════════════════════════════════════════════════════════════════\n";
echo "  FINAL VERDICT\n";
echo "═══════════════════════════════════════════════════════════════════\n";
echo "  A) Transaction-type classification: $typeVerdict\n";
echo "     ✓OK: {$results['type_check']['ok']} / ✗MISMATCH: {$results['type_check']['mismatch']} / ⚠REVIEW: {$results['type_check']['review']}\n";
echo "  B) Foreign-key integrity:           $fkVerdict\n";
echo "     Orphan types: " . count($results['fk_check']['orphans']) . "\n";
echo "  C) Balance reconciliation:          $balanceVerdict\n";
echo "     Verified: {$results['balance_check']['verified']} / Imbalanced: " . count($results['balance_check']['imbalanced']) . "\n";
echo "═══════════════════════════════════════════════════════════════════\n\n";

// Save JSON results
$logPath = storage_path('logs/visa_db_integrity_20260814.json');
file_put_contents($logPath, json_encode($results, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "Results saved to: $logPath\n";

if ($results['type_check']['mismatch'] > 0 || ! empty($results['fk_check']['orphans']) || ! empty($results['balance_check']['imbalanced'])) {
    exit(1);
}
exit(0);
