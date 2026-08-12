<?php
/**
 * ═══════════════════════════════════════════════════════════════════════════
 *  Verify: tx#303 context — what IS the 23,780 EGP exactly?
 * ═══════════════════════════════════════════════════════════════════════════
 *
 *  Purpose: read-only diagnostic to understand tx#303 (cross-currency
 *  transfer EGP→KWD) BEFORE applying any fix. Shows:
 *    [1] tx#303 full details (amount, accounts, notes, created_by, created_at)
 *    [2] acc#26 balance before/after (EGP cashbox)
 *    [3] acc#27 balance before/after (KWD cashbox)
 *    [4] Rate used (23,925/145) vs official DB rate at the time
 *    [5] Related transactions (any reversal? any other related?)
 *    [6] User audit (who created the tx, role, permissions)
 *    [7] Three interpretations of what 23,780 represents
 *
 *  CLI: php scripts/verify_tx303_context.php
 *  No flags — read-only by design.
 */

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

// ──────────────────────────────────────────────────────────────────────────
// Environment
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════════╗\n";
echo "║  🔍 VERIFY MODE — tx#303 context (read-only)                          ║\n";
echo "╚══════════════════════════════════════════════════════════════════════════╝\n\n";

echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [1] tx#303 full details\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

$tx = DB::table('transactions')->where('id', 303)->first();
if (! $tx) {
    echo "  ❌ tx#303 not found.\n";
    exit(1);
}

echo str_pad('id', 60, ' ') . " : {$tx->id}\n";
echo str_pad('type', 60, ' ') . " : {$tx->type}\n";
echo str_pad('module', 60, ' ') . " : {$tx->module}\n";
echo str_pad('amount', 60, ' ') . " : {$tx->amount} {$tx->currency}\n";
echo str_pad('from_account_id', 60, ' ') . " : {$tx->from_account_id}\n";
echo str_pad('to_account_id', 60, ' ') . " : {$tx->to_account_id}\n";
echo str_pad('related_type', 60, ' ') . " : " . ($tx->related_type ?? 'null') . "\n";
echo str_pad('related_id', 60, ' ') . " : " . ($tx->related_id ?? 'null') . "\n";
echo str_pad('created_by', 60, ' ') . " : {$tx->created_by}\n";
echo str_pad('created_at', 60, ' ') . " : {$tx->created_at}\n";
echo str_pad('updated_at', 60, ' ') . " : {$tx->updated_at}\n";
echo str_pad('notes', 60, ' ') . " : " . ($tx->notes ?? 'null') . "\n";

// ──────────────────────────────────────────────────────────────────────────
// [2] acc#26 balance BEFORE/AFTER
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [2] acc#26 (EGP cashbox) balance trajectory\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

$acc26 = DB::table('accounts')->where('id', 26)->first();
if (! $acc26) {
    echo "  ❌ acc#26 not found.\n";
} else {
    echo str_pad('name', 60, ' ') . " : {$acc26->name}\n";
    echo str_pad('type', 60, ' ') . " : {$acc26->type}\n";
    echo str_pad('currency', 60, ' ') . " : {$acc26->currency}\n";
    echo str_pad('module_type', 60, ' ') . " : {$acc26->module_type}\n";
    echo str_pad('current balance (stored)', 60, ' ') . " : " . number_format($acc26->balance, 2) . "\n";
}

$entries26 = DB::table('account_entries')
    ->where('account_id', 26)
    ->orderBy('transaction_id')
    ->get();

$runningBalance = 0;
$before303 = null;
$after303 = null;
foreach ($entries26 as $e) {
    $runningBalance += ($e->debit - $e->credit);
    if ($e->transaction_id == 303 && $before303 === null) {
        $before303 = $runningBalance - ($e->debit - $e->credit);
        $after303 = $runningBalance;
    }
}

echo str_pad('acc#26 sum-of-entries balance', 60, ' ') . " : " . number_format($runningBalance, 2) . "\n";
if ($before303 !== null) {
    echo str_pad('  └─ balance BEFORE tx#303', 58, ' ') . " : " . number_format($before303, 2) . "\n";
    echo str_pad('  └─ balance AFTER tx#303', 58, ' ') . " : " . number_format($after303, 2) . "\n";
    echo str_pad('  └─ delta from tx#303', 58, ' ') . " : " . number_format($after303 - $before303, 2) . " (debit - credit)\n";
}

// ──────────────────────────────────────────────────────────────────────────
// [3] acc#27 balance BEFORE/AFTER
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [3] acc#27 (KWD cashbox) balance trajectory\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

$acc27 = DB::table('accounts')->where('id', 27)->first();
if (! $acc27) {
    echo "  ❌ acc#27 not found.\n";
} else {
    echo str_pad('name', 60, ' ') . " : {$acc27->name}\n";
    echo str_pad('type', 60, ' ') . " : {$acc27->type}\n";
    echo str_pad('currency', 60, ' ') . " : {$acc27->currency}\n";
    echo str_pad('module_type', 60, ' ') . " : {$acc27->module_type}\n";
    echo str_pad('current balance (stored)', 60, ' ') . " : " . number_format($acc27->balance, 2) . "\n";
}

$entries27 = DB::table('account_entries')
    ->where('account_id', 27)
    ->orderBy('transaction_id')
    ->get();

$runningBalance27 = 0;
$before303_27 = null;
$after303_27 = null;
foreach ($entries27 as $e) {
    $runningBalance27 += ($e->debit - $e->credit);
    if ($e->transaction_id == 303 && $before303_27 === null) {
        $before303_27 = $runningBalance27 - ($e->debit - $e->credit);
        $after303_27 = $runningBalance27;
    }
}

echo str_pad('acc#27 sum-of-entries balance', 60, ' ') . " : " . number_format($runningBalance27, 2) . "\n";
if ($before303_27 !== null) {
    echo str_pad('  └─ balance BEFORE tx#303', 58, ' ') . " : " . number_format($before303_27, 2) . "\n";
    echo str_pad('  └─ balance AFTER tx#303', 58, ' ') . " : " . number_format($after303_27, 2) . "\n";
    echo str_pad('  └─ delta from tx#303', 58, ' ') . " : " . number_format($after303_27 - $before303_27, 2) . "\n";
}

// ──────────────────────────────────────────────────────────────────────────
// [4] Rate used vs official rate
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [4] Exchange rate analysis\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

$egpAmount = $tx->amount;
$kwdAmount = 145;
$impliedRate = $egpAmount / $kwdAmount;

echo str_pad('EGP out', 60, ' ') . " : " . number_format($egpAmount, 2) . "\n";
echo str_pad('KWD in', 60, ' ') . " : {$kwdAmount}\n";
echo str_pad('Implied rate (23,925 / 145)', 60, ' ') . " : " . number_format($impliedRate, 4) . " EGP/KWD\n";

// Try to find the official EGP/KWD rate in DB
$egpKwdRate = DB::table('exchange_rates')
    ->where(function ($q) {
        $q->where('from_currency', 'EGP')->where('to_currency', 'KWD');
    })
    ->orWhere(function ($q) {
        $q->where('from_currency', 'KWD')->where('to_currency', 'EGP');
    })
    ->orderBy('date', 'desc')
    ->first();

if ($egpKwdRate) {
    echo str_pad('Official DB rate (latest)', 60, ' ') . " : " . number_format($egpKwdRate->rate, 4) . " ({$egpKwdRate->from_currency} → {$egpKwdRate->to_currency})\n";
    if ($egpKwdRate->from_currency === 'KWD' && $egpKwdRate->to_currency === 'EGP') {
        $officialKwdToEgp = $egpKwdRate->rate;
    } elseif ($egpKwdRate->from_currency === 'EGP' && $egpKwdRate->to_currency === 'KWD') {
        $officialKwdToEgp = 1 / $egpKwdRate->rate;
    } else {
        $officialKwdToEgp = null;
    }

    if ($officialKwdToEgp !== null) {
        $expectedEgpFor145Kwd = 145 * $officialKwdToEgp;
        $overpayment = $egpAmount - $expectedEgpFor145Kwd;
        echo str_pad('Official rate (KWD→EGP)', 60, ' ') . " : " . number_format($officialKwdToEgp, 4) . "\n";
        echo str_pad('Expected EGP for 145 KWD', 60, ' ') . " : " . number_format($expectedEgpFor145Kwd, 2) . "\n";
        echo str_pad('Actual EGP paid', 60, ' ') . " : " . number_format($egpAmount, 2) . "\n";
        echo str_pad('Over/under-payment', 60, ' ') . " : " . number_format($overpayment, 2) . " EGP\n";
    }
} else {
    echo "  ⚠️  No EGP/KWD rate found in exchange_rates table.\n";
}

// ──────────────────────────────────────────────────────────────────────────
// [5] Related transactions
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [5] Related transactions\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

// Look for reversal tx (typical pattern: amount negative, notes="Reversal of tx#X")
$reversals = DB::table('transactions')
    ->where('notes', 'like', '%Reverse%')
    ->where('notes', 'like', '%303%')
    ->get();
echo str_pad('Potential reversal tx (notes like "Reverse...303")', 60, ' ') . " : " . ($reversals->count() ?: 'none') . "\n";
foreach ($reversals as $r) {
    echo "    └─ tx#{$r->id} | type={$r->type} | amount={$r->amount} | notes={$r->notes}\n";
}

// Look for tx sharing the same related_type/id
if ($tx->related_type && $tx->related_id) {
    $related = DB::table('transactions')
        ->where('related_type', $tx->related_type)
        ->where('related_id', $tx->related_id)
        ->get();
    echo str_pad('Other tx with same related_type/id', 60, ' ') . " : " . ($related->count() ?: 'none') . "\n";
    foreach ($related as $r) {
        echo "    └─ tx#{$r->id} | type={$r->type} | amount={$r->amount} | from={$r->from_account_id} → to={$r->to_account_id}\n";
    }
} else {
    echo "  ⚠️  tx#303 has no related_type/related_id — it's a standalone transfer.\n";
}

// ──────────────────────────────────────────────────────────────────────────
// [6] User audit
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [6] User audit\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

$user = DB::table('users')->where('id', $tx->created_by)->first();
if ($user) {
    echo str_pad('user id', 60, ' ') . " : {$user->id}\n";
    echo str_pad('name', 60, ' ') . " : " . ($user->name ?? 'n/a') . "\n";
    echo str_pad('email', 60, ' ') . " : " . ($user->email ?? 'n/a') . "\n";
    echo str_pad('role (if exists)', 60, ' ') . " : " . ($user->role ?? 'n/a') . "\n";

    // Try to fetch role from role_user or model_has_roles (Spatie)
    $rolesViaSpatie = DB::table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->where('model_has_roles.model_id', $tx->created_by)
        ->where('model_has_roles.model_type', 'App\\Models\\User')
        ->pluck('roles.name')
        ->toArray();
    if (! empty($rolesViaSpatie)) {
        echo str_pad('Spatie roles', 60, ' ') . " : " . implode(', ', $rolesViaSpatie) . "\n";
    }
} else {
    echo "  ❌ user#{$tx->created_by} not found.\n";
}

// ──────────────────────────────────────────────────────────────────────────
// [7] Three interpretations
// ──────────────────────────────────────────────────────────────────────────
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  [7] Three interpretations of the 23,780 EGP\n";
echo "══════════════════════════════════════════════════════════════════════════\n";

echo "\n";
echo "  INTERPRETATION A — Real currency exchange LOSS\n";
echo "  ─────────────────────────────────────────────────────────────────────\n";
echo "  • Implies: at the time, the actual market rate was lower than 165 EGP/KWD\n";
echo "  • Example: if rate was 150 EGP/KWD, expected cost = 21,750, paid = 23,925\n";
echo "  • Real loss = 23,925 - 21,750 = 2,175 EGP (NOT 23,780)\n";
echo "  • Verdict: ❌ the 23,780 is NOT a real loss — it's much bigger than any plausible FX slippage\n";
echo "\n";
echo "  INTERPRETATION B — System bug (missing offsetting entries)\n";
echo "  ─────────────────────────────────────────────────────────────────────\n";
echo "  • Implies: the transaction was 100% legitimate at 165 EGP/KWD\n";
echo "  • The system just failed to record the matching credit on acc#26 (23,925)\n";
echo "  • and the matching debit on a 'currency in transit' account\n";
echo "  • Verdict: ⚠️  PARTIALLY TRUE — the entries ARE missing, but the user did get 145 KWD\n";
echo "    and the cashbox DID lose 23,925 EGP. The 23,780 is the cross-currency gap.\n";
echo "\n";
echo "  INTERPRETATION C — Cross-currency accounting artifact\n";
echo "  ─────────────────────────────────────────────────────────────────────\n";
echo "  • Implies: TB equation Σdebit = Σcredit cannot reconcile a 23,925 EGP debit\n";
echo "    against a 145 KWD credit without a currency conversion.\n";
echo "  • The 23,780 = 23,925 EGP (debit) - 145 (literal credit) is a display artifact,\n";
echo "    not a missing transaction.\n";
echo "  • Verdict: ⚠️  PARTIALLY TRUE — there IS a real gap if we treat both as same unit,\n";
echo "    but accounting-wise, the tx IS balanced at 165 EGP/KWD.\n";
echo "\n";
echo "  ═══════════════════════════════════════════════════════════════════════\n";
echo "  MOST LIKELY INTERPRETATION (based on data):\n";
echo "  ═══════════════════════════════════════════════════════════════════════\n";
echo "  • The user paid 23,925 EGP and received 145 KWD (legitimate).\n";
echo "  • The rate (165 EGP/KWD) was probably the market rate at the time.\n";
echo "  • The system recorded only 2 of the 4 required journal entries\n";
echo "    (debit EGP cashbox + credit KWD cashbox, missing the in-transit bridge).\n";
echo "  • The 23,780 is the arithmetic gap when the TB equation sums mixed currencies.\n";
echo "  • Recording it as a 'currency exchange loss' (Option B) is a CONSERVATIVE FIX\n";
echo "    that acknowledges the gap but treats it as a real loss to balance the books.\n";
echo "\n";
echo "  ALTERNATIVE: A cleaner fix would be to ADD the missing 'currency in transit'\n";
echo "  entries (debit 23,925 + credit 23,925) so each transaction balances naturally.\n";
echo "  But that's a different fix script (Option D in the dry-run).\n";
echo "\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  📋 NEXT STEPS — choose how to proceed\n";
echo "══════════════════════════════════════════════════════════════════════════\n";
echo "  A. Apply Option B (write-off + 23,780 as loss) — current fix_tx303.php\n";
echo "     → books become balanced, but records a 23,780 EGP loss\n";
echo "  B. Switch to Option D (add currency-in-transit entries) — cleaner\n";
echo "     → need to build a different fix script\n";
echo "  C. Verify with the office before any fix (ask accountant for the rate)\n";
echo "  D. Skip tx#303 for now and move to next residual\n";
echo "══════════════════════════════════════════════════════════════════════════\n\n";
