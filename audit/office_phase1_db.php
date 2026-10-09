<?php

// Run from the Laravel project root:
//   php audit/office_phase1_db.php

require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$errors = [];
$warnings = [];
$pass = [];

echo "\n=======================================================\n";
echo " OFFICE DIVISION - PHASE 1: DB & MASTER DATA AUDIT\n";
echo "=======================================================\n\n";

function check(string $label, bool $ok, string $detail, array &$pass, array &$errors): void
{
    if ($ok) {
        echo "  [PASS] {$label}".($detail ? " - {$detail}" : '')."\n";
        $pass[] = $label;
    } else {
        echo "  [FAIL] {$label}".($detail ? " - {$detail}" : '')."\n";
        $errors[] = $label.($detail ? ": {$detail}" : '');
    }
}

function warn(string $label, string $detail, array &$warnings): void
{
    echo "  [WARN] {$label}".($detail ? " - {$detail}" : '')."\n";
    $warnings[] = $label.($detail ? ": {$detail}" : '');
}

// ─── 1. TABLE EXISTENCE ────────────────────────────────────────────────────
echo "-- [1] TABLE EXISTENCE --\n";
$requiredTables = [
    'accounts', 'account_entries', 'transactions', 'transfers', 'treasuries',
    'bus_companies', 'bus_bookings', 'bus_company_payments', 'bus_payments',
    'bus_inventories', 'bus_refund_requests',
    'fawry_transactions', 'fawry_machines', 'fawry_machine_transactions',
    'fawry_currencies', 'fawry_operation_types', 'fawry_payment_methods',
    'online_transactions', 'online_service_providers', 'online_service_types',
    'wallet_transactions', 'wallet_types', 'wallets',
    'customers', 'suppliers',
];
foreach ($requiredTables as $table) {
    check("Table `{$table}` exists", Schema::hasTable($table), '', $pass, $errors);
}

// ─── 2. CRITICAL COLUMN PRESENCE (using real column names) ─────────────────
echo "\n-- [2] CRITICAL COLUMN PRESENCE --\n";
$columnChecks = [
    // accounts: no division/currency_id — uses module_type + currency (varchar)
    'accounts' => [
        'id', 'name', 'type', 'module_type', 'module', 'balance', 'currency',
        'is_active', 'owner_type', 'is_module_vault', 'deleted_at',
    ],
    'account_entries' => ['id', 'account_id', 'transaction_id', 'debit', 'credit', 'balance_after'],
    // transactions: uses currency (varchar), no currency_id
    'transactions' => ['id', 'type', 'amount', 'currency', 'created_by', 'related_type', 'related_id'],
    // bus_bookings: no bus_company_id — uses inventory_id + customer_id
    'bus_bookings' => ['id', 'inventory_id', 'customer_id', 'total_price', 'status', 'account_id'],
    // bus_company_payments: uses company_id not bus_company_id
    'bus_company_payments' => ['id', 'company_id', 'account_id', 'amount', 'status'],
    // bus_inventories: is the company-trip bridge
    'bus_inventories' => ['id', 'company_id', 'route', 'travel_date', 'total_tickets', 'available_tickets', 'total_cost'],
    // fawry_transactions: no `type` column — uses operation_type (varchar)
    'fawry_transactions' => ['id', 'fawry_machine_id', 'amount', 'operation_type', 'client_name', 'payment_method'],
    // online_transactions: no online_service_provider_id/amount — uses provider_id + purchase_price
    'online_transactions' => ['id', 'service_type_id', 'provider_id', 'purchase_price', 'selling_price', 'account_id', 'status'],
    // wallet_transactions: no wallet_id — uses wallet_account_id + cash_account_id + wallet_type_id
    'wallet_transactions' => ['id', 'wallet_type_id', 'amount', 'type', 'wallet_account_id', 'cash_account_id'],
    // bus_refund_requests
    'bus_refund_requests' => ['id', 'bus_booking_id', 'company_id', 'refund_amount', 'status'],
];
foreach ($columnChecks as $table => $cols) {
    if (! Schema::hasTable($table)) {
        warn("Skipping column check for `{$table}` - table missing", '', $warnings);

        continue;
    }
    foreach ($cols as $col) {
        check("`{$table}`.`{$col}` exists", Schema::hasColumn($table, $col), '', $pass, $errors);
    }
}

// ─── 3. REFERENTIAL INTEGRITY (correct FK columns) ─────────────────────────
echo "\n-- [3] REFERENTIAL INTEGRITY --\n";
$fkChecks = [
    // account_entries -> accounts
    ['account_entries', 'account_id', 'accounts', 'id'],
    // account_entries -> transactions
    ['account_entries', 'transaction_id', 'transactions', 'id'],
    // bus_bookings -> bus_inventories (via inventory_id)
    ['bus_bookings', 'inventory_id', 'bus_inventories', 'id'],
    // bus_bookings -> customers
    ['bus_bookings', 'customer_id', 'customers', 'id'],
    // bus_bookings -> accounts (nullable)
    ['bus_bookings', 'account_id', 'accounts', 'id'],
    // bus_company_payments -> bus_companies (company_id)
    ['bus_company_payments', 'company_id', 'bus_companies', 'id'],
    // bus_company_payments -> accounts
    ['bus_company_payments', 'account_id', 'accounts', 'id'],
    // bus_inventories -> bus_companies
    ['bus_inventories', 'company_id', 'bus_companies', 'id'],
    // fawry_transactions -> fawry_machines (nullable)
    ['fawry_transactions', 'fawry_machine_id', 'fawry_machines', 'id'],
    // online_transactions -> accounts
    ['online_transactions', 'account_id', 'accounts', 'id'],
    // wallet_transactions -> accounts (wallet_account_id)
    ['wallet_transactions', 'wallet_account_id', 'accounts', 'id'],
    // wallet_transactions -> accounts (cash_account_id)
    ['wallet_transactions', 'cash_account_id', 'accounts', 'id'],
    // bus_refund_requests -> bus_bookings
    ['bus_refund_requests', 'bus_booking_id', 'bus_bookings', 'id'],
];
foreach ($fkChecks as [$childTable, $childCol, $parentTable, $parentCol]) {
    if (! Schema::hasTable($childTable) || ! Schema::hasTable($parentTable)) {
        warn("Skipping FK `{$childTable}.{$childCol}` - table missing", '', $warnings);

        continue;
    }
    if (! Schema::hasColumn($childTable, $childCol)) {
        warn("Skipping FK `{$childTable}.{$childCol}` - column missing", '', $warnings);

        continue;
    }
    $orphans = DB::table("{$childTable} as c")
        ->leftJoin("{$parentTable} as p", "c.{$childCol}", '=', "p.{$parentCol}")
        ->whereNotNull("c.{$childCol}")
        ->whereNull("p.{$parentCol}")
        ->count();
    check(
        "No orphans: `{$childTable}.{$childCol}` -> `{$parentTable}.{$parentCol}`",
        $orphans === 0,
        $orphans > 0 ? "{$orphans} orphaned rows" : '',
        $pass, $errors
    );
}

// ─── 4. SOFT DELETE COVERAGE ───────────────────────────────────────────────
echo "\n-- [4] SOFT DELETE COVERAGE --\n";
$softDeleteTables = [
    'accounts', 'bus_companies', 'bus_bookings', 'bus_inventories',
    'bus_refund_requests', 'fawry_transactions', 'fawry_machines',
    'online_transactions', 'online_service_providers',
    'wallet_transactions', 'customers', 'suppliers',
];
foreach ($softDeleteTables as $table) {
    if (! Schema::hasTable($table)) {
        continue;
    }
    check("`{$table}` has `deleted_at`", Schema::hasColumn($table, 'deleted_at'), '', $pass, $errors);
}

// ─── 5. ACCOUNT BALANCE SANITY ─────────────────────────────────────────────
echo "\n-- [5] ACCOUNT BALANCE SANITY --\n";
if (Schema::hasTable('accounts') && Schema::hasTable('account_entries')) {
    $mismatches = DB::select('
        SELECT a.id, a.name, a.balance,
               COALESCE(SUM(ae.debit),0) - COALESCE(SUM(ae.credit),0) AS computed
        FROM accounts a
        LEFT JOIN account_entries ae ON ae.account_id = a.id
        GROUP BY a.id, a.name, a.balance
        HAVING ABS(a.balance - computed) > 0.001
        LIMIT 30
    ');
    check('All account balances match double-entry sum', empty($mismatches),
        empty($mismatches) ? '' : count($mismatches).' mismatched', $pass, $errors);
    foreach ($mismatches as $row) {
        echo "       -> Account #{$row->id} ({$row->name}): stored={$row->balance}, computed={$row->computed}\n";
    }
} else {
    warn('Skipping balance sanity - tables missing', '', $warnings);
}

// ─── 6. DOUBLE-ENTRY SYMMETRY ──────────────────────────────────────────────
echo "\n-- [6] DOUBLE-ENTRY SYMMETRY --\n";
if (Schema::hasTable('transactions') && Schema::hasTable('account_entries')) {
    $unbalanced = DB::select('
        SELECT t.id, t.type,
               COALESCE(SUM(ae.debit),0)  AS total_debit,
               COALESCE(SUM(ae.credit),0) AS total_credit
        FROM transactions t
        JOIN account_entries ae ON ae.transaction_id = t.id
        GROUP BY t.id, t.type
        HAVING ABS(total_debit - total_credit) > 0.001
        LIMIT 30
    ');
    check('All transactions balanced (debit = credit)', empty($unbalanced),
        empty($unbalanced) ? '' : count($unbalanced).' unbalanced', $pass, $errors);
    foreach ($unbalanced as $row) {
        echo "       -> Txn #{$row->id} ({$row->type}): debit={$row->total_debit}, credit={$row->total_credit}\n";
    }
} else {
    warn('Skipping double-entry check - tables missing', '', $warnings);
}

// ─── 7. NEGATIVE BALANCE DETECTION ────────────────────────────────────────
echo "\n-- [7] NEGATIVE BALANCE DETECTION --\n";
if (Schema::hasTable('accounts')) {
    // Asset-like types: bank, cashbox, wallet
    $negAssets = DB::table('accounts')
        ->whereIn('type', ['bank', 'cashbox', 'wallet'])
        ->where('balance', '<', 0)
        ->count();
    check('No bank/cashbox/wallet accounts with negative balance', $negAssets === 0,
        $negAssets > 0 ? "{$negAssets} accounts" : '', $pass, $errors);
    if ($negAssets > 0) {
        $rows = DB::table('accounts')
            ->whereIn('type', ['bank', 'cashbox', 'wallet'])
            ->where('balance', '<', 0)
            ->select('id', 'name', 'type', 'balance', 'module_type')
            ->get();
        foreach ($rows as $r) {
            echo "       -> #{$r->id} {$r->name} ({$r->type}/{$r->module_type}): balance={$r->balance}\n";
        }
    }

    // Supplier/customer payables: negative balance means we owe them (OK), positive means they owe us
    $posSuppliers = DB::table('accounts')->where('type', 'supplier')->where('balance', '>', 0)->count();
    if ($posSuppliers > 0) {
        warn("Supplier accounts with positive balance: {$posSuppliers}", 'overpayment or data error', $warnings);
    } else {
        check('No supplier accounts with unexpected positive balance', true, '', $pass, $errors);
    }
}

// ─── 8. BUS INVENTORY INTEGRITY ────────────────────────────────────────────
echo "\n-- [8] BUS INVENTORY INTEGRITY --\n";
if (Schema::hasTable('bus_inventories') && Schema::hasTable('bus_bookings')) {
    // available_tickets must not exceed total_tickets
    $overCapacity = DB::table('bus_inventories')
        ->whereRaw('available_tickets > total_tickets')
        ->count();
    check('No bus inventory over-capacity (available <= total)', $overCapacity === 0,
        $overCapacity > 0 ? "{$overCapacity} rows" : '', $pass, $errors);

    // available_tickets must not be negative
    $negative = DB::table('bus_inventories')->where('available_tickets', '<', 0)->count();
    check('No bus inventory with negative available tickets', $negative === 0,
        $negative > 0 ? "{$negative} rows" : '', $pass, $errors);

    // Booked quantity per inventory must not exceed total_tickets
    $overBooked = DB::select("
        SELECT bi.id, bi.route, bi.total_tickets,
               COALESCE(SUM(bb.quantity),0) as booked
        FROM bus_inventories bi
        LEFT JOIN bus_bookings bb ON bb.inventory_id = bi.id
            AND bb.status NOT IN ('cancelled','refunded')
            AND bb.deleted_at IS NULL
        GROUP BY bi.id, bi.route, bi.total_tickets
        HAVING booked > bi.total_tickets
        LIMIT 10
    ");
    check('No bus inventory over-booked (booked <= total)', empty($overBooked),
        empty($overBooked) ? '' : count($overBooked).' over-booked', $pass, $errors);
    foreach ($overBooked as $r) {
        echo "       -> Inventory #{$r->id} ({$r->route}): total={$r->total_tickets}, booked={$r->booked}\n";
    }
}

// ─── 9. BUS BOOKING PAYMENT INTEGRITY ─────────────────────────────────────
echo "\n-- [9] BUS BOOKING PAYMENT INTEGRITY --\n";
if (Schema::hasTable('bus_bookings')) {
    // paid_amount must not exceed total_price
    $overpaid = DB::table('bus_bookings')
        ->whereRaw('paid_amount > total_price + 0.01')
        ->where('status', '!=', 'cancelled')
        ->count();
    check('No bus bookings over-paid (paid <= total_price)', $overpaid === 0,
        $overpaid > 0 ? "{$overpaid} bookings" : '', $pass, $errors);

    // Negative paid amounts
    $negPaid = DB::table('bus_bookings')->where('paid_amount', '<', 0)->count();
    check('No bus bookings with negative paid_amount', $negPaid === 0,
        $negPaid > 0 ? "{$negPaid} bookings" : '', $pass, $errors);
}

// ─── 10. FAWRY TRANSACTION INTEGRITY ──────────────────────────────────────
echo "\n-- [10] FAWRY TRANSACTION INTEGRITY --\n";
if (Schema::hasTable('fawry_transactions')) {
    // amount must be > 0
    $zeroOrNeg = DB::table('fawry_transactions')->where('amount', '<=', 0)->count();
    check('All Fawry transactions have positive amount', $zeroOrNeg === 0,
        $zeroOrNeg > 0 ? "{$zeroOrNeg} rows" : '', $pass, $errors);

    // profit = selling_price - fawry_price must match stored profit
    $profitMismatch = DB::select('
        SELECT id, selling_price, fawry_price, profit,
               (selling_price - fawry_price) AS computed_profit
        FROM fawry_transactions
        WHERE ABS(profit - (selling_price - fawry_price)) > 0.01
        LIMIT 10
    ');
    check('Fawry profit = selling_price - fawry_price', empty($profitMismatch),
        empty($profitMismatch) ? '' : count($profitMismatch).' mismatches', $pass, $errors);
    foreach ($profitMismatch as $r) {
        echo "       -> Fawry #{$r->id}: stored_profit={$r->profit}, computed={$r->computed_profit}\n";
    }
}

// ─── 11. ONLINE TRANSACTION INTEGRITY ─────────────────────────────────────
echo "\n-- [11] ONLINE TRANSACTION INTEGRITY --\n";
if (Schema::hasTable('online_transactions')) {
    // profit = selling_price - purchase_price
    $profitMismatch = DB::select('
        SELECT id, purchase_price, selling_price, profit,
               (selling_price - purchase_price) AS computed_profit
        FROM online_transactions
        WHERE ABS(profit - (selling_price - purchase_price)) > 0.01
          AND deleted_at IS NULL
        LIMIT 10
    ');
    check('Online profit = selling_price - purchase_price', empty($profitMismatch),
        empty($profitMismatch) ? '' : count($profitMismatch).' mismatches', $pass, $errors);
    foreach ($profitMismatch as $r) {
        echo "       -> Online #{$r->id}: stored_profit={$r->profit}, computed={$r->computed_profit}\n";
    }

    // amount_paid must not exceed selling_price
    $cols = Schema::hasColumn('online_transactions', 'amount_paid');
    if ($cols) {
        $overpaid = DB::table('online_transactions')
            ->whereRaw('amount_paid > selling_price + 0.01')
            ->whereNull('deleted_at')
            ->count();
        check('No online transactions over-paid', $overpaid === 0,
            $overpaid > 0 ? "{$overpaid} rows" : '', $pass, $errors);
    }
}

// ─── 12. WALLET TRANSACTION INTEGRITY ─────────────────────────────────────
echo "\n-- [12] WALLET TRANSACTION INTEGRITY --\n";
if (Schema::hasTable('wallet_transactions')) {
    // total_amount = amount + service_fee
    $totalMismatch = DB::select('
        SELECT id, amount, service_fee, total_amount,
               (amount + service_fee) AS computed_total
        FROM wallet_transactions
        WHERE ABS(total_amount - (amount + service_fee)) > 0.01
          AND deleted_at IS NULL
        LIMIT 10
    ');
    check('Wallet total_amount = amount + service_fee', empty($totalMismatch),
        empty($totalMismatch) ? '' : count($totalMismatch).' mismatches', $pass, $errors);
    foreach ($totalMismatch as $r) {
        echo "       -> Wallet Txn #{$r->id}: stored_total={$r->total_amount}, computed={$r->computed_total}\n";
    }

    // amount_paid must not exceed total_amount
    $overpaid = DB::table('wallet_transactions')
        ->whereRaw('amount_paid > total_amount + 0.01')
        ->whereNull('deleted_at')
        ->count();
    check('No wallet transactions over-paid', $overpaid === 0,
        $overpaid > 0 ? "{$overpaid} rows" : '', $pass, $errors);
}

// ─── 13. DUPLICATE TRANSACTION DETECTION ──────────────────────────────────
echo "\n-- [13] DUPLICATE TRANSACTION DETECTION --\n";
if (Schema::hasTable('transactions')) {
    // Check for duplicate (related_type, related_id, type) — should be unique per business event
    $dupes = DB::select('
        SELECT related_type, related_id, type, COUNT(*) as cnt
        FROM transactions
        WHERE related_type IS NOT NULL AND related_id IS NOT NULL
        GROUP BY related_type, related_id, type
        HAVING cnt > 1
        LIMIT 20
    ');
    check('No duplicate (related_type, related_id, type) in transactions', empty($dupes),
        empty($dupes) ? '' : count($dupes).' duplicate tuples', $pass, $errors);
    foreach ($dupes as $d) {
        echo "       -> {$d->related_type} #{$d->related_id} type={$d->type} count={$d->cnt}\n";
    }
}

// ─── 14. RECORD COUNTS (informational) ────────────────────────────────────
echo "\n-- [14] RECORD COUNTS (informational) --\n";
$countTables = [
    'accounts', 'account_entries', 'transactions', 'transfers',
    'bus_companies', 'bus_inventories', 'bus_bookings', 'bus_company_payments',
    'bus_refund_requests',
    'fawry_transactions', 'fawry_machines',
    'online_transactions', 'online_service_providers',
    'wallet_transactions', 'wallets',
    'customers', 'suppliers',
];
foreach ($countTables as $t) {
    if (Schema::hasTable($t)) {
        $total = DB::table($t)->count();
        $soft = Schema::hasColumn($t, 'deleted_at')
                    ? DB::table($t)->whereNotNull('deleted_at')->count()
                    : 'N/A';
        echo "      {$t}: {$total} total".(is_int($soft) ? ", {$soft} soft-deleted" : '')."\n";
    }
}

// ─── SCHEMA DRIFT NOTES ────────────────────────────────────────────────────
echo "\n-- [SCHEMA DRIFT AUDIT NOTES] --\n";
$schemaNotes = [
    'accounts.division' => 'Column NOT found — uses `module_type` (varchar) instead',
    'accounts.currency_id' => 'Column NOT found — uses `currency` (varchar(3)) directly',
    'transactions.currency_id' => 'Column NOT found — uses `currency` (varchar(3)) directly',
    'bus_bookings.bus_company_id' => 'Column NOT found — relationship is via `inventory_id` -> bus_inventories.company_id',
    'bus_bookings.total_cost' => 'Column NOT found — uses `total_price` + `unit_price` + `quantity`',
    'bus_company_payments.bus_company_id' => 'Column NOT found — uses `company_id`',
    'fawry_transactions.type' => 'Column NOT found — uses `operation_type` (varchar)',
    'online_transactions.online_service_provider_id' => 'Column NOT found — uses `provider_id`',
    'online_transactions.amount' => 'Column NOT found — uses `purchase_price` + `selling_price`',
    'wallet_transactions.wallet_id' => 'Column NOT found — uses `wallet_account_id` + `cash_account_id`',
];
foreach ($schemaNotes as $field => $note) {
    echo "  [NOTE] {$field}: {$note}\n";
}

// ─── SUMMARY ────────────────────────────────────────────────────────────────
echo "\n=======================================================\n";
echo " AUDIT SUMMARY\n";
echo "=======================================================\n";
echo '  PASS : '.count($pass)."\n";
echo '  FAIL : '.count($errors)."\n";
echo '  WARN : '.count($warnings)."\n";

if (! empty($errors)) {
    echo "\nFAILURES:\n";
    foreach ($errors as $i => $e) {
        echo '  '.($i + 1).". {$e}\n";
    }
}
if (! empty($warnings)) {
    echo "\nWARNINGS:\n";
    foreach ($warnings as $i => $w) {
        echo '  '.($i + 1).". {$w}\n";
    }
}
echo "\n";
