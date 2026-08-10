<?php
/**
 * Independent audit of bus transaction types.
 *
 * Re-derives the "expected" type for each bus transaction from the
 * account-level direction (no notes-based heuristics), then compares
 * it against the type currently stored in the DB.
 *
 * Expected-type rules (no notes involved):
 *   - from=Customer → office liquidity → income
 *   - from=Customer → income_clearing → refund (customer debt being cleared)
 *   - from=Customer → expense_clearing → manual review (unusual)
 *   - from=office liquidity → Customer → refund
 *   - from=office liquidity → Supplier → expense
 *   - from=office liquidity → office liquidity → transfer
 *   - from=Supplier → office liquidity → income (rare: supplier refund)
 *   - from=Supplier → expense_clearing → refund reversal / manual review
 *   - from=expense_clearing → Supplier → expense reversal
 *   - from=income_clearing → Customer → income recognition (sale)
 *   - from=income_clearing → office liquidity → manual review
 *   - any other → manual review
 *
 * Outputs: a per-row verdict (✓ OK / ✗ MISMATCH / ⚠ REVIEW) plus an
 * overall summary so the operator can spot-check the classification.
 */

declare(strict_types=1);

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$rows = Transaction::query()
    ->where('module', TransactionModule::Bus->value)
    ->with(['fromAccount:id,name,type', 'toAccount:id,name,type'])
    ->orderBy('id')
    ->get();

$stats = ['ok' => 0, 'mismatch' => 0, 'review' => 0, 'by_actual' => [], 'by_expected' => []];

printf("%-6s %-13s %-15s %-15s %-12s %-12s %-8s %s\n",
    'tx#', 'from_type', 'from_name', 'to_name', 'actual', 'expected', 'verdict', 'notes');
echo str_repeat('─', 140), "\n";

foreach ($rows as $tx) {
    $fromType = $tx->fromAccount?->type instanceof AccountType
        ? $tx->fromAccount->type->value
        : (string) ($tx->fromAccount?->type ?? 'null');
    $toType = $tx->toAccount?->type instanceof AccountType
        ? $tx->toAccount->type->value
        : (string) ($tx->toAccount?->type ?? 'null');

    $fromName = trim(mb_substr((string) ($tx->fromAccount?->name ?? '—'), 0, 15));
    $toName   = trim(mb_substr((string) ($tx->toAccount?->name ?? '—'), 0, 15));

    $expected = expectedType($fromType, $toType, (string) ($tx->toAccount?->name ?? ''), (string) ($tx->fromAccount?->name ?? ''));
    $actual = $tx->type instanceof TransactionType ? $tx->type->value : (string) $tx->type;

    $verdict = '⚠ REVIEW';
    if ($expected === null) {
        $verdict = '⚠ REVIEW';
        $stats['review']++;
    } elseif ($expected === $actual) {
        $verdict = '✓ OK';
        $stats['ok']++;
    } else {
        $verdict = '✗ MISMATCH';
        $stats['mismatch']++;
    }

    $stats['by_actual'][$actual]   = ($stats['by_actual'][$actual] ?? 0) + 1;
    $stats['by_expected'][$expected ?? 'review'] = ($stats['by_expected'][$expected ?? 'review'] ?? 0) + 1;

    $notes = trim(mb_substr((string) ($tx->notes ?? ''), 0, 40));
    printf("%-6d %-13s %-15s %-15s %-12s %-12s %-9s %s\n",
        $tx->id,
        $fromType,
        $fromName,
        $toName,
        $actual,
        $expected ?? 'review',
        $verdict,
        $notes,
    );
}

echo "\n=== Summary ===\n";
echo "Total: ".count($rows)."\n";
echo "✓ OK:        {$stats['ok']}\n";
echo "✗ MISMATCH:  {$stats['mismatch']}\n";
echo "⚠ REVIEW:    {$stats['review']}\n";

echo "\nBy actual type:\n";
foreach ($stats['by_actual'] as $t => $c) {
    echo "  - {$t}: {$c}\n";
}
echo "\nBy expected type:\n";
foreach ($stats['by_expected'] as $t => $c) {
    echo "  - {$t}: {$c}\n";
}

/**
 * Compute the expected transaction.type from account types only.
 * Returns null when the rule can't decide (caller should mark ⚠ REVIEW).
 */
function expectedType(?string $fromType, ?string $toType, string $toName, string $fromName): ?string
{
    $liq = [AccountType::Cashbox->value, AccountType::Bank->value, AccountType::Wallet->value];
    $customer = AccountType::Customer->value;
    $supplier = AccountType::Supplier->value;

    // Customer → office liquidity: customer payment = income
    if ($fromType === $customer && in_array($toType, $liq, true)) {
        return TransactionType::Income->value;
    }

    // Customer → income_clearing: cancelling customer debt = refund
    if ($fromType === $customer && str_contains($toName, 'إقفال إيرادات')) {
        return TransactionType::Refund->value;
    }

    // Customer → expense_clearing: unusual (could be a sale recognition)
    if ($fromType === $customer && str_contains($toName, 'إقفال تكاليف')) {
        return null; // REVIEW
    }

    // Office liquidity → Customer: cash refund to customer = refund
    if (in_array($fromType, $liq, true) && $toType === $customer) {
        return TransactionType::Refund->value;
    }

    // Office liquidity → Supplier: paying supplier = expense
    if (in_array($fromType, $liq, true) && $toType === $supplier) {
        return TransactionType::Expense->value;
    }

    // Office liquidity → income_clearing: depositing cash for sale recognition = income
    if (in_array($fromType, $liq, true) && str_contains($toName, 'إقفال إيرادات')) {
        return TransactionType::Income->value;
    }

    // Office liquidity → expense_clearing: clearing expense = expense
    if (in_array($fromType, $liq, true) && str_contains($toName, 'إقفال تكاليف')) {
        return TransactionType::Expense->value;
    }

    // Office liquidity → office liquidity = transfer
    if (in_array($fromType, $liq, true) && in_array($toType, $liq, true)) {
        return TransactionType::Transfer->value;
    }

    // Supplier → office liquidity: rare, supplier paying us = income
    if ($fromType === $supplier && in_array($toType, $liq, true)) {
        return TransactionType::Income->value;
    }

    // Supplier → income_clearing: REVIEW
    if ($fromType === $supplier && str_contains($toName, 'إقفال إيرادات')) {
        return null; // REVIEW
    }

    // expense_clearing → Supplier: reversing a cost = expense (reversal)
    if (str_contains($fromName, 'إقفال تكاليف') && $toType === $supplier) {
        return TransactionType::Expense->value;
    }

    // expense_clearing → office liquidity: REVIEW
    if (str_contains($fromName, 'إقفال تكاليف') && in_array($toType, $liq, true)) {
        return null; // REVIEW
    }

    // income_clearing → Customer: sale recognition = income
    if (str_contains($fromName, 'إقفال إيرادات') && $toType === $customer) {
        return TransactionType::Income->value;
    }

    // income_clearing → office liquidity: REVIEW
    if (str_contains($fromName, 'إقفال إيرادات') && in_array($toType, $liq, true)) {
        return null; // REVIEW
    }

    // income_clearing → supplier: REVIEW
    if (str_contains($fromName, 'إقفال إيرادات') && $toType === $supplier) {
        return null; // REVIEW
    }

    return null;
}
