<?php

/**
 * Fawry Lifecycle Test #3 — Walk-in Pay-Debt Matrix (T4.x)
 * =========================================================
 *
 * Purpose: Verify every walk-in pay-debt scenario:
 *   - Single tx, full debt
 *   - Multiple txs, FIFO allocation
 *   - Overpayment rejection
 *   - Partial repayment
 *   - Soft-deleted txs excluded from FIFO
 *   - Non-EGP rejection
 *   - No debt rejection
 *
 * PHASE 4c — WALK-IN PAY-DEBT
 */

require __DIR__.'/../../vendor/autoload.php';

$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = ':memory:';
$_ENV['CACHE_DRIVER'] = 'array';
$_ENV['SESSION_DRIVER'] = 'array';
$_ENV['QUEUE_CONNECTION'] = 'sync';
$_ENV['MAIL_MAILER'] = 'array';
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=:memory:');
putenv('CACHE_DRIVER=array');
putenv('SESSION_DRIVER=array');
putenv('QUEUE_CONNECTION=sync');
putenv('MAIL_MAILER=array');

$app = require __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

Artisan::call('migrate', ['--force' => true]);

use App\Enums\AccountType;
use App\Http\Controllers\Api\V1\Fawry\FawryWalkInPaymentController;
use App\Models\Account;
use App\Models\Fawry\FawryMachine;
use App\Models\Fawry\FawryTransaction;
use App\Models\User;
use App\Services\Fawry\FawryTransactionService;
use App\Services\Finance\LedgerClearingAccounts;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

$pass = 0;
$fail = 0;
function record(string $scenario, string $check, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ✅ $check\n";
    } else {
        $fail++;
        echo "  ❌ $check — $detail\n";
    }
}
function printSection(string $title): void
{
    echo "\n".str_repeat('=', 72)."\n  $title\n".str_repeat('=', 72)."\n";
}
function getBalance(int $accountId): float
{
    return (float) Account::find($accountId)?->balance ?? 0.0;
}

$admin = User::firstOrCreate(['email' => 'admin@paydebt.local'], ['name' => 'PayDebt Admin', 'password' => bcrypt('test'), 'email_verified_at' => now()]);
Auth::login($admin);
$svc = app(FawryTransactionService::class);
$ctrl = app(FawryWalkInPaymentController::class);
$clearing = app(LedgerClearingAccounts::class);

$cashbox = Account::create([
    'name' => 'PayDebt Cashbox', 'type' => AccountType::Cashbox, 'balance' => 10000.00,
    'currency' => 'EGP', 'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OWNER,
    'module_type' => 'office', 'created_by' => $admin->id,
]);
$usdCashbox = Account::create([
    'name' => 'PayDebt USD Cashbox', 'type' => AccountType::Cashbox, 'balance' => 100.00,
    'currency' => 'USD', 'is_active' => true, 'owner_type' => Account::OWNER_TYPE_OWNER,
    'module_type' => 'office', 'created_by' => $admin->id,
]);
$machine = FawryMachine::firstOrCreate(['name' => 'PayDebt Machine'], ['type' => 'fawry', 'balance' => 50000.00, 'is_active' => true]);
$walkInArId = $clearing->fawryWalkInArAccountId();

// ── T4.1: Single tx, full walk-in debt ─────────────────────────────────
printSection('T4.1 — Single tx, full walk-in debt');
$tx1 = $svc->createTransaction([
    'client_id' => null, 'client_name' => 'Walkin T4.1',
    'operation_type' => 'bill_payment', 'client_amount' => 1000.00,
    'fawry_price' => 800.00, 'selling_price' => 1000.00, 'amount' => 0.00,
    'employee_id' => $admin->id, 'account_id' => $cashbox->id,
    'fawry_machine_id' => $machine->id, 'payment_method' => 'cash',
]);
record('T4.1', 'pre: tx1.amount = 0 (debt = 1000)', (float) FawryTransaction::find($tx1->id)->amount === 0.0);
record('T4.1', 'pre: walkInAR.balance = 1000 (debt)', getBalance($walkInArId) === 1000.0);

$resp = $ctrl->payDebt(new Request([
    'client_name' => 'Walkin T4.1', 'amount' => 1000.00, 'account_id' => $cashbox->id,
]));
$data = json_decode($resp->getContent(), true)['data'];
record('T4.1', 'pay-debt returns success', $resp->getStatusCode() === 200, 'status='.$resp->getStatusCode());
record('T4.1', 'remaining_debt = 0', (float) $data['remaining_debt'] === 0.0);
record('T4.1', 'fully_settled = true', $data['fully_settled'] === true);
record('T4.1', 'post: tx1.amount = 1000', (float) FawryTransaction::find($tx1->id)->amount === 1000.0);
record('T4.1', 'post: walkInAR.balance = 0', getBalance($walkInArId) === 0.0);

// ── T4.2: Multiple txs, FIFO allocation ─────────────────────────────────
printSection('T4.2 — Multiple txs, FIFO allocation');
$tx2a = $svc->createTransaction(['client_id' => null, 'client_name' => 'Walkin T4.2', 'operation_type' => 'bill_payment', 'client_amount' => 1000.00, 'fawry_price' => 800.00, 'selling_price' => 1000.00, 'amount' => 0.00, 'employee_id' => $admin->id, 'account_id' => $cashbox->id, 'fawry_machine_id' => $machine->id, 'payment_method' => 'cash']);
$tx2b = $svc->createTransaction(['client_id' => null, 'client_name' => 'Walkin T4.2', 'operation_type' => 'bill_payment', 'client_amount' => 500.00, 'fawry_price' => 400.00, 'selling_price' => 500.00, 'amount' => 0.00, 'employee_id' => $admin->id, 'account_id' => $cashbox->id, 'fawry_machine_id' => $machine->id, 'payment_method' => 'cash']);
$tx2c = $svc->createTransaction(['client_id' => null, 'client_name' => 'Walkin T4.2', 'operation_type' => 'bill_payment', 'client_amount' => 300.00, 'fawry_price' => 250.00, 'selling_price' => 300.00, 'amount' => 0.00, 'employee_id' => $admin->id, 'account_id' => $cashbox->id, 'fawry_machine_id' => $machine->id, 'payment_method' => 'cash']);
record('T4.2', 'pre: walkInAR.balance = 1800 (1000+500+300)', getBalance($walkInArId) === 1800.0, 'actual='.getBalance($walkInArId));

$resp = $ctrl->payDebt(new Request(['client_name' => 'Walkin T4.2', 'amount' => 1200.00, 'account_id' => $cashbox->id]));
$data = json_decode($resp->getContent(), true)['data'];
record('T4.2', 'pay 1200 returns success', $resp->getStatusCode() === 200);
record('T4.2', 'remaining_debt = 600', (float) $data['remaining_debt'] === 600.0, 'actual='.$data['remaining_debt']);
record('T4.2', 'fully_settled = false', $data['fully_settled'] === false);
record('T4.2', 'tx2a.amount = 1000 (FIFO fully paid)', (float) FawryTransaction::find($tx2a->id)->amount === 1000.0, 'actual='.FawryTransaction::find($tx2a->id)->amount);
record('T4.2', 'tx2b.amount = 200 (partial: 500 debt - 200 paid)', (float) FawryTransaction::find($tx2b->id)->amount === 200.0, 'actual='.FawryTransaction::find($tx2b->id)->amount);
record('T4.2', 'tx2c.amount = 0 (untouched: spent 1000+200 = 1200)', (float) FawryTransaction::find($tx2c->id)->amount === 0.0, 'actual='.FawryTransaction::find($tx2c->id)->amount);
record('T4.2', 'post: walkInAR.balance = 600', getBalance($walkInArId) === 600.0, 'actual='.getBalance($walkInArId));

// ── T4.3: Overpayment rejection ─────────────────────────────────────────
printSection('T4.3 — Overpayment rejection');
$resp = $ctrl->payDebt(new Request(['client_name' => 'Walkin T4.2', 'amount' => 9999.00, 'account_id' => $cashbox->id]));
$respData = json_decode($resp->getContent(), true);
record('T4.3', 'Overpayment returns 422', $resp->getStatusCode() === 422, 'status='.$resp->getStatusCode());
record('T4.3', 'Overpayment returns success=false', ($respData['success'] ?? null) === false, 'actual='.json_encode($respData));
record('T4.3', 'walkInAR.balance unchanged (600)', getBalance($walkInArId) === 600.0, 'actual='.getBalance($walkInArId));

// ── T4.4: Exact repayment ───────────────────────────────────────────────
printSection('T4.4 — Exact repayment (remaining = 0)');
$resp = $ctrl->payDebt(new Request(['client_name' => 'Walkin T4.2', 'amount' => 600.00, 'account_id' => $cashbox->id]));
$data = json_decode($resp->getContent(), true)['data'];
record('T4.4', 'pay 600 returns success', $resp->getStatusCode() === 200);
record('T4.4', 'remaining_debt = 0', (float) $data['remaining_debt'] === 0.0);
record('T4.4', 'fully_settled = true', $data['fully_settled'] === true);
record('T4.4', 'post: walkInAR.balance = 0', getBalance($walkInArId) === 0.0);

// ── T4.5: No debt rejection ─────────────────────────────────────────────
printSection('T4.5 — No debt rejection');
$resp = $ctrl->payDebt(new Request(['client_name' => 'Walkin T4.1', 'amount' => 100.00, 'account_id' => $cashbox->id]));
record('T4.5', 'No debt returns 422', $resp->getStatusCode() === 422, 'status='.$resp->getStatusCode());

// ── T4.6: Non-EGP rejection ─────────────────────────────────────────────
printSection('T4.6 — Non-EGP rejection');
$resp = $ctrl->payDebt(new Request(['client_name' => 'Walkin T4.1', 'amount' => 100.00, 'account_id' => $usdCashbox->id]));
record('T4.6', 'Non-EGP returns 422', $resp->getStatusCode() === 422, 'status='.$resp->getStatusCode());

// ── T4.7: Soft-deleted tx excluded from FIFO ────────────────────────────
printSection('T4.7 — Soft-deleted tx excluded from FIFO');
$tx7a = $svc->createTransaction(['client_id' => null, 'client_name' => 'Walkin T4.7', 'operation_type' => 'bill_payment', 'client_amount' => 500.00, 'fawry_price' => 400.00, 'selling_price' => 500.00, 'amount' => 0.00, 'employee_id' => $admin->id, 'account_id' => $cashbox->id, 'fawry_machine_id' => $machine->id, 'payment_method' => 'cash']);
$tx7b = $svc->createTransaction(['client_id' => null, 'client_name' => 'Walkin T4.7', 'operation_type' => 'bill_payment', 'client_amount' => 500.00, 'fawry_price' => 400.00, 'selling_price' => 500.00, 'amount' => 0.00, 'employee_id' => $admin->id, 'account_id' => $cashbox->id, 'fawry_machine_id' => $machine->id, 'payment_method' => 'cash']);

// Soft-delete tx7a
$svc->deleteTransaction($tx7a);
record('T4.7', 'pre: tx7a is soft-deleted', FawryTransaction::withTrashed()->find($tx7a->id)->deleted_at !== null);
record('T4.7', 'pre: tx7b is active', FawryTransaction::find($tx7b->id)->deleted_at === null);

// Pay 500 — should ONLY go to tx7b (since tx7a is soft-deleted)
$resp = $ctrl->payDebt(new Request(['client_name' => 'Walkin T4.7', 'amount' => 500.00, 'account_id' => $cashbox->id]));
$data = json_decode($resp->getContent(), true)['data'];
record('T4.7', 'pay 500 returns success', $resp->getStatusCode() === 200);

// [B-4 FIXED 2026-08-20] payDebt() now correctly filters soft-deleted
// transactions from the debt calc by adding ->whereNull('deleted_at').
// FawryWalkInPaymentController::payDebt (lines 65–69).
record('T4.7', '[B-4 FIXED] remaining_debt = 0 (soft-deleted tx7a no longer inflates debt)',
    (float) $data['remaining_debt'] === 0.0, 'actual='.$data['remaining_debt']);

$tx7bRefreshed = FawryTransaction::find($tx7b->id);
record('T4.7', 'tx7b.amount = 500 (received full payment — FIFO correctly excludes soft-deleted)',
    (float) $tx7bRefreshed->amount === 500.0, 'actual='.$tx7bRefreshed->amount);
record('T4.7', 'tx7a.amount = 0 OR still 0 (was deleted, NO allocation from FIFO)',
    (float) FawryTransaction::withTrashed()->find($tx7a->id)?->amount === 0.0, 'actual='.(FawryTransaction::withTrashed()->find($tx7a->id)?->amount ?? 'NULL'));

// ── T4.8: Walk-in pay-debt cross-client isolation ───────────────────────
printSection('T4.8 — Cross-client isolation');
$tx8a = $svc->createTransaction(['client_id' => null, 'client_name' => 'Client A', 'operation_type' => 'bill_payment', 'client_amount' => 1000.00, 'fawry_price' => 800.00, 'selling_price' => 1000.00, 'amount' => 0.00, 'employee_id' => $admin->id, 'account_id' => $cashbox->id, 'fawry_machine_id' => $machine->id, 'payment_method' => 'cash']);
$tx8b = $svc->createTransaction(['client_id' => null, 'client_name' => 'Client B', 'operation_type' => 'bill_payment', 'client_amount' => 800.00, 'fawry_price' => 600.00, 'selling_price' => 800.00, 'amount' => 0.00, 'employee_id' => $admin->id, 'account_id' => $cashbox->id, 'fawry_machine_id' => $machine->id, 'payment_method' => 'cash']);

// Pay 1000 for Client A — should NOT touch Client B's tx
$resp = $ctrl->payDebt(new Request(['client_name' => 'Client A', 'amount' => 1000.00, 'account_id' => $cashbox->id]));
$data = json_decode($resp->getContent(), true)['data'];
record('T4.8', 'pay Client A returns success', $resp->getStatusCode() === 200);
record('T4.8', 'Client A remaining = 0', (float) $data['remaining_debt'] === 0.0);
record('T4.8', 'Client A tx.amount = 1000 (paid)', (float) FawryTransaction::find($tx8a->id)->amount === 1000.0, 'actual='.FawryTransaction::find($tx8a->id)->amount);
record('T4.8', 'Client B tx.amount = 0 (NOT touched)', (float) FawryTransaction::find($tx8b->id)->amount === 0.0, 'actual='.FawryTransaction::find($tx8b->id)->amount);
record('T4.8', 'Client B debt still 800', (float) FawryTransaction::find($tx8b->id)->selling_price - (float) FawryTransaction::find($tx8b->id)->amount === 800.0);

// ── T4.9: Allocation spans multiple txs (FIFO order) ────────────────────
printSection('T4.9 — Allocation spans multiple txs');
$tx9a = $svc->createTransaction(['client_id' => null, 'client_name' => 'Walkin T4.9', 'operation_type' => 'bill_payment', 'client_amount' => 100.00, 'fawry_price' => 80.00, 'selling_price' => 100.00, 'amount' => 0.00, 'employee_id' => $admin->id, 'account_id' => $cashbox->id, 'fawry_machine_id' => $machine->id, 'payment_method' => 'cash']);
sleep(1); // ensure created_at differs
$tx9b = $svc->createTransaction(['client_id' => null, 'client_name' => 'Walkin T4.9', 'operation_type' => 'bill_payment', 'client_amount' => 200.00, 'fawry_price' => 150.00, 'selling_price' => 200.00, 'amount' => 0.00, 'employee_id' => $admin->id, 'account_id' => $cashbox->id, 'fawry_machine_id' => $machine->id, 'payment_method' => 'cash']);
sleep(1);
$tx9c = $svc->createTransaction(['client_id' => null, 'client_name' => 'Walkin T4.9', 'operation_type' => 'bill_payment', 'client_amount' => 300.00, 'fawry_price' => 250.00, 'selling_price' => 300.00, 'amount' => 0.00, 'employee_id' => $admin->id, 'account_id' => $cashbox->id, 'fawry_machine_id' => $machine->id, 'payment_method' => 'cash']);

// Pay 350 — should allocate: tx9a (100) + tx9b (200) + tx9c (50)
$resp = $ctrl->payDebt(new Request(['client_name' => 'Walkin T4.9', 'amount' => 350.00, 'account_id' => $cashbox->id]));
$data = json_decode($resp->getContent(), true)['data'];
record('T4.9', 'remaining_debt = 250', (float) $data['remaining_debt'] === 250.0, 'actual='.$data['remaining_debt']);
record('T4.9', 'tx9a.amount = 100 (fully paid)', (float) FawryTransaction::find($tx9a->id)->amount === 100.0, 'actual='.FawryTransaction::find($tx9a->id)->amount);
record('T4.9', 'tx9b.amount = 200 (fully paid)', (float) FawryTransaction::find($tx9b->id)->amount === 200.0, 'actual='.FawryTransaction::find($tx9b->id)->amount);
record('T4.9', 'tx9c.amount = 50 (partial: 300 debt - 50 paid)', (float) FawryTransaction::find($tx9c->id)->amount === 50.0, 'actual='.FawryTransaction::find($tx9c->id)->amount);

// ── T4.10: DeletedAt-restored tx would re-enter FIFO ────────────────────
printSection('T4.10 — Soft-deleted FIFO exclusion');
// Implicit in T4.7 — verify no allocation happens to deleted txs

// ── RECONCILIATION ──────────────────────────────────────────────────────
printSection('RECONCILIATION');
$totalDebits = DB::table('account_entries')->sum('debit');
$totalCredits = DB::table('account_entries')->sum('credit');
record('RECON', 'Σdebits = Σcredits across all GL transactions', abs($totalDebits - $totalCredits) < 0.005, "Σdr={$totalDebits} Σcr={$totalCredits}");

$orphanGlTxs = DB::table('transactions')
    ->where('related_type', FawryTransaction::class)
    ->whereNotIn('related_id', function ($q) {
        $q->select('id')->from('fawry_transactions');
    })
    ->count();
record('RECON', 'No orphan GL transactions', $orphanGlTxs === 0);

// ── SUMMARY ─────────────────────────────────────────────────────────────
echo "\n".str_repeat('=', 72)."\n";
echo "  WALK-IN PAY-DEBT MATRIX: $pass PASS, $fail FAIL\n";
echo str_repeat('=', 72)."\n";

if ($fail > 0) {
    echo "\n🔴 B-4 BUG DETECTED:\n";
    echo "  FawryWalkInPaymentController::payDebt computes `debt` WITHOUT filtering soft-deleted txs.\n";
    echo "  The FIFO allocation correctly excludes soft-deleted txs, but the debt calculation\n";
    echo "  at the top of the method does NOT.\n\n";
    echo "  Location: app/Http/Controllers/Api/V1/Fawry/FawryWalkInPaymentController.php:65-69\n\n";
    echo "  Repro:\n";
    echo "  1. CREATE tx_a (debt=500), tx_b (debt=500) for same walk-in client_name\n";
    echo "  2. DELETE tx_a (soft-deleted, tx_b still active with debt=500)\n";
    echo "  3. POST /api/v1/fawry/walk-in/pay-debt with amount=500\n";
    echo "  4. Expected: remaining_debt = 0, fully_settled = true\n";
    echo "  5. Actual: remaining_debt = 500, fully_settled = false\n\n";
    echo "  Impact: 🟡 MEDIUM\n";
    echo "    - Customer sees wrong remaining_debt and fully_settled flag\n";
    echo "    - Customer cannot fully settle via pay-debt if any of their prior txs was soft-deleted\n";
    echo "    - Overpayment block could trigger if soft-deleted debt + active debt > amount\n";
    echo "    - No data corruption (FIFO allocation is correct; only the calc is wrong)\n\n";
    echo "  Severity: 🟡 MEDIUM (not CRITICAL because:\n";
    echo "    - No money is lost or duplicated\n";
    echo "    - GL stays balanced (allocation is correct)\n";
    echo "    - Customer UI shows wrong state but reality is correct in DB)\n";
    echo "    - Workaround: client can pay slightly more than the actual remaining debt)\n\n";
    echo "  Recommended fix (NOT applied yet — discovery phase):\n";
    echo "    Add ->whereNull('deleted_at') to the debt calculation query at line 65-69.\n";
}

exit($fail === 0 ? 0 : 1);
