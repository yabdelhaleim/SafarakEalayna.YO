<?php
/**
 * Migration Test — Simulating buggy entries and validating the fix.
 *
 * Steps:
 *  1. Capture current state
 *  2. Inject BUGGY entries (simulating data from before the fix)
 *  3. Verify invariant VIOLATION detected
 *  4. Run the migration --dry-run
 *  5. Apply the migration
 *  6. Verify invariant HOLDS again
 */

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use App\Models\Account;
use App\Models\Transaction;
use App\Enums\AccountType;
use App\Enums\TransactionType;

$results = ['success' => 0, 'failed' => 0];

function log_test(string $key, bool $success, $payload = null): void
{
    global $results;
    if ($success) {
        $results['success']++;
        echo "  ✅ $key\n";
    } else {
        $results['failed']++;
        echo "  ❌ $key — " . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

echo "═══════════════════════════════════════════════════════════════\n";
echo "  Migration Test — Fix Reversal Direction\n";
echo "═══════════════════════════════════════════════════════════════\n\n";

// ── Setup: synthetic accounts
$fromBank = Account::create([
    'name' => 'حساب اختبار الترحيل - المصدر',
    'type' => AccountType::Bank,
    'currency' => 'EGP',
    'balance' => 10000.00,
    'is_active' => true,
    'owner_type' => 'office',
    'module_type' => 'office',
    'is_module_vault' => false,
    'created_by' => 1,
]);
DB::table('account_entries')->insert([
    'account_id' => $fromBank->id,
    'transaction_id' => null,
    'debit' => 0.00,
    'credit' => 10000.00,
    'balance_after' => 10000.00,
    'notes' => 'رصيد افتتاحي',
    'created_at' => now(),
    'updated_at' => now(),
]);

$toBank = Account::create([
    'name' => 'حساب اختبار الترحيل - الوجهة',
    'type' => AccountType::Bank,
    'currency' => 'EGP',
    'balance' => 5000.00,
    'is_active' => true,
    'owner_type' => 'office',
    'module_type' => 'office',
    'is_module_vault' => false,
    'created_by' => 1,
]);
DB::table('account_entries')->insert([
    'account_id' => $toBank->id,
    'transaction_id' => null,
    'debit' => 0.00,
    'credit' => 5000.00,
    'balance_after' => 5000.00,
    'notes' => 'رصيد افتتاحي',
    'created_at' => now(),
    'updated_at' => now(),
]);

// ── Inject buggy transfer ($tx) — what the old code WOULD have written:
$bugTx = Transaction::create([
    'type' => TransactionType::Transfer->value,
    'amount' => 2000,
    'currency' => 'EGP',
    'module' => 'office',
    'from_account_id' => $fromBank->id,
    'to_account_id' => $toBank->id,
    'notes' => 'تحويل تجريبي مع BUG السجل للاختبار',
    'created_by' => 1,
]);

DB::table('account_entries')->insert([
    'account_id' => $fromBank->id,
    'transaction_id' => $bugTx->id,
    'debit' => 0.00,        // ← BUG: should be debit=2000
    'credit' => 2000.00,    // ← BUG
    'balance_after' => 8000.00, // real balance went from 10000 to 8000
    'notes' => '',
    'created_at' => now(),
    'updated_at' => now(),
]);

DB::table('account_entries')->insert([
    'account_id' => $toBank->id,
    'transaction_id' => $bugTx->id,
    'debit' => 2000.00,     // ← BUG: should be debit=0
    'credit' => 0.00,      // ← BUG
    'balance_after' => 7000.00, // real balance went from 5000 to 7000
    'notes' => '',
    'created_at' => now(),
    'updated_at' => now(),
]);

// Force the balances to be consistent with the buggy entries (manually)
DB::table('accounts')->where('id', $fromBank->id)->update(['balance' => 8000.00]);
DB::table('accounts')->where('id', $toBank->id)->update(['balance' => 7000.00]);

// ── Step 1: Verify the invariant is VIOLATED
echo "[1] Invariant check (BEFORE fix):\n";
$fromBankNet = DB::table('account_entries')->where('account_id', $fromBank->id)->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) AS net')->value('net');
$toBankNet = DB::table('account_entries')->where('account_id', $toBank->id)->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) AS net')->value('net');
$fromBankBal = Account::find($fromBank->id)->balance;
$toBankBal = Account::find($toBank->id)->balance;

echo "    From: balance={$fromBankBal}, net_from_entries={$fromBankNet}\n";
echo "    To:   balance={$toBankBal}, net_from_entries={$toBankNet}\n";

log_test('From balance != entries net (BUG detected)', abs((float)$fromBankBal - (float)$fromBankNet) > 1000, "diff=" . ((float)$fromBankBal - (float)$fromBankNet));
log_test('To balance != entries net (BUG detected)', abs((float)$toBankBal - (float)$toBankNet) > 1000, "diff=" . ((float)$toBankBal - (float)$toBankNet));

// ── Step 2: Run the migration --dry-run
echo "\n[2] Run migration in DRY RUN mode:\n";
$dryExit = Artisan::call('ledger:fix-reversal-direction', ['--dry-run' => true]);
log_test('Dry-run completed', $dryExit === 0, "exit_code={$dryExit}");

// Verify entries NOT yet swapped
$afterDry = DB::table('account_entries')->where('transaction_id', $bugTx->id)->get();
$stillBuggy = $afterDry->contains(fn ($e) => $e->account_id === $fromBank->id && (float)$e->credit > 0);
log_test('Dry-run did NOT write (entries still buggy)', $stillBuggy);

// ── Step 3: Apply the migration
echo "\n[3] Apply the migration:\n";
$applyExit = Artisan::call('ledger:fix-reversal-direction', ['--force' => true]);
log_test('Migration applied', $applyExit === 0, "exit_code={$applyExit}");

// ── Step 4: Verify entries ARE now correct
echo "\n[4] Verify entries swapped:\n";
$afterApply = DB::table('account_entries')->where('transaction_id', $bugTx->id)->orderBy('id')->get();
foreach ($afterApply as $e) {
    $account = $e->account_id === $fromBank->id ? 'from' : 'to';
    echo "    {$account} account={$e->account_id}, debit={$e->debit}, credit={$e->credit}\n";
}
$fromAfter = $afterApply->firstWhere('account_id', $fromBank->id);
$toAfter = $afterApply->firstWhere('account_id', $toBank->id);
log_test('From account has DEBIT (correct)', (float)$fromAfter->debit === 2000.0);
log_test('To account has CREDIT (correct)', (float)$toAfter->credit === 2000.0);

// ── Step 5: Verify invariant holds now
echo "\n[5] Invariant check (AFTER fix):\n";
$fromNetAfter = DB::table('account_entries')->where('account_id', $fromBank->id)->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) AS net')->value('net');
$toNetAfter = DB::table('account_entries')->where('account_id', $toBank->id)->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) AS net')->value('net');
$fromBalAfter = Account::find($fromBank->id)->balance;
$toBalAfter = Account::find($toBank->id)->balance;

echo "    From: balance={$fromBalAfter}, net_from_entries={$fromNetAfter}\n";
echo "    To:   balance={$toBalAfter}, net_from_entries={$toNetAfter}\n";

log_test('From balance == entries net (CORRECTED)', abs((float)$fromBalAfter - (float)$fromNetAfter) < 0.01, "diff=" . abs((float)$fromBalAfter - (float)$fromNetAfter));
log_test('To balance == entries net (CORRECTED)', abs((float)$toBalAfter - (float)$toNetAfter) < 0.01, "diff=" . abs((float)$toBalAfter - (float)$toNetAfter));

// ── Step 6: Idempotency check — run again, should not double-swap
echo "\n[6] Idempotency check:\n";
$idempExit = Artisan::call('ledger:fix-reversal-direction', ['--force' => true]);
$fromAfter2 = DB::table('account_entries')->where('transaction_id', $bugTx->id)->where('account_id', $fromBank->id)->first();
log_test('Idempotent — debit column NOT zeroed', (float)$fromAfter2->debit === 2000.0);

// Cleanup
echo "\n[7] Cleanup test data\n";
DB::table('account_entries')->whereIn('transaction_id', [$bugTx->id])->delete();
DB::table('transactions')->where('id', $bugTx->id)->delete();
DB::table('account_entries')->whereIn('account_id', [$fromBank->id, $toBank->id])->delete();
DB::table('accounts')->whereIn('id', [$fromBank->id, $toBank->id])->delete();
log_test('Cleanup successful', true);

echo "\n═══════════════════════════════════════════════════════════════\n";
echo "  النتيجة: {$results['success']} نجح / {$results['failed']} فشل\n";
echo "═══════════════════════════════════════════════════════════════\n";
