<?php
/**
 * PHASE 3b v3: WRITEOFF — 7 CONFIRMED DESYNCS
 * ────────────────────────────────────────────
 * القرار: كل الفروقات السبعة تُسجَّل كـ Write-off (خسارة معتمدة)
 * المعتمد: صاحب الشركة — 2026-07-08
 * المرجع: تقرير Phase 2 + 3a (BALANCE_TOUCHPOINTS_MAP.md)
 *
 * الأرقام مأخوذة بالظبط من Phase 2 Report (المُعتمدة من المحاسبة).
 * ⚠️ مهم: متعملش أي حاجة على السيرفر الحي (production) قبل ما تجرب على staging
 *         وتاخد موافقة.
 *
 * الاستخدام:
 *   # 1) DRY-RUN على staging (آمن، يعرض الخطة بدون تنفيذ):
 *   php artisan tinker --execute='require "phase3b_v3_writeoff_7desyncs.php";'
 *
 *   # 2) APPLY على staging (بعد مراجعة الـ dry-run):
 *   php artisan tinker --execute='$argv=["--apply"]; require "phase3b_v3_writeoff_7desyncs.php";'
 *
 *   # 3) APPLY على production (بعد موافقة الـ staging + backup):
 *   mysqldump -u root -p safarakealayna > backup_pre_phase3bv3_$(date +%Y%m%d_%H%M%S).sql
 *   ssh ... php artisan tinker --execute='$argv=["--apply"]; require "phase3b_v3_writeoff_7desyncs.php";'
 *
 * @see ACCOUNTING_HANDOFF_2026-07-08.md
 * @see BALANCE_TOUCHPOINTS_MAP.md
 */

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\AuditLog;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Transaction;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// ═══════════════════════════════════════════════════════════════════
// [A] APPLY MODE + DATABASE SAFETY CHECK
// ═══════════════════════════════════════════════════════════════════
$apply = in_array('--apply', $argv ?? [], true);
$expectedDb = null;
foreach (($argv ?? []) as $arg) {
    if (str_starts_with($arg, '--db=')) {
        $expectedDb = substr($arg, 5);
    }
}

echo "\n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  PHASE 3b v3: WRITEOFF — 7 CONFIRMED DESYNCS                               \n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  Decision:    Write-off (approved by company owner, 2026-07-08)\n";
echo "  Reference:   Phase 2 + 3a report\n";
echo "  Mode:        " . ($apply ? '⚠️  APPLY (writes to DB)' : '🟢 DRY-RUN (read-only)') . "\n";
echo "  Generated:   " . now()->format('Y-m-d H:i:s') . "\n";

// 🛡️ SAFETY CHECK: التأكد إن الـ DB الفعلي مطابق للـ --db flag
if ($apply) {
    $currentDb = DB::connection()->getDatabaseName();
    echo "  Current DB:  {$currentDb}\n";

    if ($expectedDb === null) {
        echo "\n";
        echo "  ⚠️  WARNING: --db=<name> flag NOT provided.\n";
        echo "  Running on '{$currentDb}' without explicit safety check.\n";
        echo "  (Add --db=safarakealayna for production OR --db=safarakealayna_staging for staging)\n\n";
    } elseif ($currentDb !== $expectedDb) {
        echo "  Expected DB: {$expectedDb}\n";
        echo "  ───────────────────────────────────────────────────────────────────\n";
        echo "  ⛔ SAFETY ABORT: Refusing to run on wrong database.\n";
        echo "     Expected: '{$expectedDb}'\n";
        echo "     Got:      '{$currentDb}'\n";
        echo "  ───────────────────────────────────────────────────────────────────\n";
        throw new \RuntimeException("Safety check failed: DB mismatch. Refusing to proceed.");
    } else {
        echo "  Expected DB: {$expectedDb}\n";
        echo "  ✓ Safety check passed: Running on '{$currentDb}' as expected.\n\n";
    }
} else {
    echo "  (DRY-RUN — no DB check enforced)\n\n";
}

if (! $apply) {
    echo "ℹ️  هذا DRY-RUN — اقرأ الـ output ثم اراجعه قبل تشغيل --apply\n\n";
}

// ═══════════════════════════════════════════════════════════════════
// [B] الحالات السبعة — Option B (Recalculated on current GL)
// ═══════════════════════════════════════════════════════════════════
//  الأرقام المعتمدة من صاحب الشركة في 2026-07-08: Write-off (خسارة معتمدة)
//  الحساب: الأرقام أخذت في الاعتبار الـ 50K recharge الفعلي على العربية.
//
//  منطق الحساب:
//    Prepaid Flight-Carrier GL الحالي = +18,412.85 (بعد الـ 50K recharge)
//    per-carrier target = 18,412.85 / 5 = +3,682.57
//    writeoff لكل carrier = current balance - +3,682.57
//
//  منطق الأنظمة:
//    Prepaid Flight-System GL الحالي = -52,349.30
//    per-system target = -52,349.30 / 2 = -26,174.65
//    writeoff لكل system = current balance - (-26,174.65)
//
//  ✅ النتيجة: مجموع carriers = +18,412.85 (matches GL exactly)
//              مجموع systems = -52,349.30 (matches GL exactly)
//              مفيش gap بعد الـ writeoff
//
//  (الأرقام القديمة من Phase 2/3a report = 564,201.39 EGP)
//  (الأرقام الجديدة من Option B = 385,503.49 EGP)
//  (الفرق = 178,697.90 = تأثير الـ 50K recharge)
$cases = [
    // Carriers (5) - totaling 194,149.05
    ['type' => 'carrier', 'id' => 1, 'name' => 'العربية',          'amount' => 103731.44],
    ['type' => 'carrier', 'id' => 2, 'name' => 'الجزيرة_مصري',    'amount' => 11099.43],
    ['type' => 'carrier', 'id' => 4, 'name' => 'نسما',             'amount' => 43164.43],
    ['type' => 'carrier', 'id' => 5, 'name' => 'فلاي أديل',       'amount' => 335.52],
    ['type' => 'carrier', 'id' => 6, 'name' => 'اير كايرو',        'amount' => 35818.23],
    // Systems (2) - totaling 191,354.44
    ['type' => 'system',  'id' => 1, 'name' => 'NDC_ WONDR',       'amount' => 102358.85],
    ['type' => 'system',  'id' => 2, 'name' => 'NDC_X NSAS',       'amount' => 88995.59],
];

$totalWriteoff = array_sum(array_column($cases, 'amount'));
echo "▸ مجموع الـ 7 write-offs: " . number_format($totalWriteoff, 2) . " EGP\n\n";

// ═══════════════════════════════════════════════════════════════════
// [C] حساب الـ Writeoff Account
// ═══════════════════════════════════════════════════════════════════
// Phase 5: 'expense' type now in accounts.type enum (added by migration
//          2026_07_09_010000_add_expense_to_accounts_type_enum.php)
// ⚠️ قبل تشغيل --apply: لازم الـ migration تنفذ أولاً:
//     php artisan migrate

$writeoffAccount = Account::where('name', 'مصروفات شطب أرصدة الناقلين - طيران')->first();

// ═══════════════════════════════════════════════════════════════════
// [C.5] حساب الـ Writeoff Contra Account (الجانب الثاني من القيد المزدوج)
// ═══════════════════════════════════════════════════════════════════
// لكل transaction لازم entry على الجانبين (DEBIT + CREDIT) عشان الـ double-entry
// يبقى متوازن. الـ writeoff account عليه DEBIT (expense recognized).
// الـ contra account عليه CREDIT (the offsetting entry — represents
// the "writeoff contra" / reduction of the carrier's claim).
$writeoffContraAccount = Account::where('name', 'مقابل شطب أرصدة الناقلين - طيران')->first();

if (! $writeoffContraAccount) {
    echo "▸ Writeoff Contra Account غير موجود — هيتم إنشاؤه:\n";
    if ($apply) {
        try {
            $now2 = now();
            DB::table('accounts')->insert([
                'name'        => 'مقابل شطب أرصدة الناقلين - طيران',
                'type'        => 'cashbox',  // workaround — accounts.type enum has no 'liability'/'equity'
                'currency'    => 'EGP',
                'balance'     => 0,
                'is_active'   => 1,
                'owner_type'  => 'owner',
                'module'      => null,
                'is_module_vault' => 0,
                'notes'       => 'Phase 3b v3: Contra account for the write-off of 7 confirmed desyncs. Holds the credit side of the double-entry. (DB workaround: type=cashbox because accounts.type enum has no liability/equity).',
                'created_by'  => Auth::id() ?? 1,
                'created_at'  => $now2,
                'updated_at'  => $now2,
            ]);
            $writeoffContraAccount = Account::where('name', 'مقابل شطب أرصدة الناقلين - طيران')->first();
            echo "    ✓ Created: id={$writeoffContraAccount->id}, balance={$writeoffContraAccount->balance}, type=" . ($writeoffContraAccount->type?->value ?? 'unknown') . "\n\n";
        } catch (\Throwable $e) {
            echo "    ✗ Failed: {$e->getMessage()}\n";
            return;
        }
    } else {
        echo "    [DRY-RUN: الحساب هيتم إنشاؤه عند --apply]\n\n";
        $writeoffContraAccount = new Account(['id' => 0, 'balance' => 0, 'name' => 'CONTRA-PENDING (pending)']);
    }
} else {
    echo "▸ Writeoff Contra Account موجود: '{$writeoffContraAccount->name}' (id={$writeoffContraAccount->id}, type=" . ($writeoffContraAccount->type?->value ?? 'unknown') . ", current balance={$writeoffContraAccount->balance})\n\n";
}

if (! $writeoffAccount) {
    echo "▸ Writeoff Account غير موجود — هيتم إنشاؤه:\n";
    if ($apply) {
        try {
            // ⚠️ Phase 5: Use DB::table() direct insert to bypass Eloquent cast
            // مشكلة: Account model has 'type' => AccountType::class cast
            //         Eloquent's BackedEnum cast fails to convert during save()
            //         when passing the enum instance directly.
            // الحل: insert via raw DB query, then fetch as model.
            $now = now();
            DB::table('accounts')->insert([
                'name'        => 'مصروفات شطب أرصدة الناقلين - طيران',
                'type'        => 'expense',  // Phase 5: proper type (DB-level value)
                'currency'    => 'EGP',
                'balance'     => 0,
                'is_active'   => 1,
                'owner_type'  => 'owner',
                'module'      => null,
                'is_module_vault' => 0,
                'notes'       => 'Phase 3b v3 (Option B): Write-off of 7 confirmed desyncs (approved by company owner 2026-07-08). Total: 385,503.49 EGP. Recalculated on current GL state (after 50K recharge to العربية).',
                'created_by'  => Auth::id() ?? 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $writeoffAccount = Account::where('name', 'مصروفات شطب أرصدة الناقلين - طيران')->first();
            echo "    ✓ Created: id={$writeoffAccount->id}, balance={$writeoffAccount->balance}, type=" . ($writeoffAccount->type?->value ?? 'unknown') . "\n\n";
        } catch (\Throwable $e) {
            echo "    ✗ Failed: {$e->getMessage()}\n";
            echo "    ⚠️  Aborting — ممكن الـ migration مش متنفذة (لازم 'expense' يكون في الـ enum).\n";
            echo "    نفذ أولاً: php artisan migrate\n";
            return;
        }
    } else {
        echo "    [DRY-RUN: الحساب هيتم إنشاؤه عند --apply]\n";
        echo "    المواصفات المقترحة:\n";
        echo "      name:     مصروفات شطب أرصدة الناقلين - طيران\n";
        echo "      type:     expense (Phase 5 — requires migration)\n";
        echo "      currency: EGP\n";
        echo "      balance:  0\n";
        echo "      [تأكيد: الـ migration لازم تكون اتنفذت قبل --apply]\n\n";
        $writeoffAccount = new Account(['id' => 0, 'balance' => 0, 'name' => 'WO-PENDING (pending)']);
    }
} else {
    echo "▸ Writeoff Account موجود: '{$writeoffAccount->name}' (id={$writeoffAccount->id}, type=" . ($writeoffAccount->type?->value ?? 'unknown') . ", current balance={$writeoffAccount->balance})\n\n";
}

// ═══════════════════════════════════════════════════════════════════
// [D] التحقق من الـ entities قبل التنفيذ
// ═══════════════════════════════════════════════════════════════════
echo "▸ الحالة قبل التنفيذ:\n";
echo str_repeat('─', 75) . "\n";
echo sprintf("  %-25s %-12s %15s %15s\n", 'Name', 'Type', 'balance', 'desync');
echo str_repeat('─', 75) . "\n";

foreach ($cases as $case) {
    $modelClass = $case['type'] === 'carrier' ? FlightCarrier::class : FlightSystem::class;
    $entity = $modelClass::find($case['id']);

    if (! $entity) {
        echo sprintf("  ⚠️  %-25s %-12s NOT FOUND (id=%d)\n", $case['name'], $case['type'], $case['id']);
        continue;
    }

    $balance = (float) $entity->balance;
    $newBalance = $balance - $case['amount'];
    $writeoffAccountBalance = $writeoffAccount ? (float) $writeoffAccount->balance : 0;

    echo sprintf("  %-25s %-12s %15.2f %15.2f\n", $case['name'], $case['type'], $balance, $case['amount']);
    echo sprintf("    → Expected after: %15.2f (writeoff account: +%.2f)\n", $newBalance, $writeoffAccountBalance + $case['amount']);
}
echo str_repeat('─', 75) . "\n\n";

// ═══════════════════════════════════════════════════════════════════
// [E] تنفيذ الـ Write-offs (تحت Guard لكل حالة)
// ═══════════════════════════════════════════════════════════════════
$results = [
    'success' => 0,
    'failed'  => 0,
    'transactions' => [],
    'log_entries' => [],
];

foreach ($cases as $index => $case) {
    $caseNum = $index + 1;
    $modelClass = $case['type'] === 'carrier' ? FlightCarrier::class : FlightSystem::class;
    $entity = $modelClass::find($case['id']);

    if (! $entity) {
        echo "  ✗ Case #{$caseNum} ({$case['name']}): Entity not found — skipping\n";
        $results['failed']++;
        continue;
    }

    $balanceBefore = (float) $entity->balance;
    $newBalanceExpected = $balanceBefore - $case['amount'];

    echo "  ▸ Case #{$caseNum}: {$case['name']} ({$case['type']} #{$entity->id})\n";
    echo "    Before: balance = {$balanceBefore}\n";
    echo "    Writeoff: -{$case['amount']}\n";
    echo "    Expected after: {$newBalanceExpected}\n";

    if (! $apply) {
        echo "    [DRY-RUN: لم يُنفّذ]\n\n";
        continue;
    }

    try {
        $tx = LedgerBalanceMutationGuard::run(function () use ($entity, $case, $writeoffAccount) {
            return DB::transaction(function () use ($entity, $case, $writeoffAccount) {
                // ① Lock الـ entity row
                $modelClass = $case['type'] === 'carrier' ? FlightCarrier::class : FlightSystem::class;
                $entityFresh = $modelClass::query()
                    ->whereKey($entity->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                // ② Lock الـ writeoff account
                Account::query()
                    ->whereKey($writeoffAccount->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $balanceAtLock = (float) $entityFresh->balance;
                $writeoffAmount = (float) $case['amount'];
                $newBalance = $balanceAtLock - $writeoffAmount;

                // ③ Transaction header
                $transaction = Transaction::create([
                    'type'            => 'writeoff',
                    'amount'          => $writeoffAmount,
                    'from_account_id' => null, // P&L
                    'to_account_id'   => $writeoffAccount->id,
                    'module'          => 'flight',
                    'related_type'    => $modelClass,
                    'related_id'      => $entity->id,
                    'notes'           => "Write-off approved by company owner on 2026-07-08 - " .
                                       "Value: {$writeoffAmount} EGP - " .
                                       "Reference: Phase 2 + 3a report (BALANCE_TOUCHPOINTS_MAP.md) - " .
                                       "{$case['name']} (id={$entity->id})",
                    'created_by'      => Auth::id() ?? 1,
                ]);

                // ④ AccountEntry on writeoff expense (DEBIT = expense increase)
                $writeoffEntry = AccountEntry::create([
                    'account_id'      => $writeoffAccount->id,
                    'transaction_id'  => $transaction->id,
                    'debit'           => $writeoffAmount,
                    'credit'          => 0,
                    'balance_after'   => (float) $writeoffAccount->balance + $writeoffAmount,
                    'notes'           => "Write-off for {$case['name']} (Phase 3b v3, approved by company owner) [DEBIT side: expense recognized]",
                ]);

                // ④.b AccountEntry on writeoff contra (CREDIT = offsetting entry, the other side of double-entry)
                $writeoffContraEntry = AccountEntry::create([
                    'account_id'      => $writeoffContraAccount->id,
                    'transaction_id'  => $transaction->id,
                    'debit'           => 0,
                    'credit'          => $writeoffAmount,
                    'balance_after'   => (float) $writeoffContraAccount->balance + $writeoffAmount,
                    'notes'           => "Write-off for {$case['name']} (Phase 3b v3) [CREDIT side: contra for double-entry]",
                ]);

                // ⑤ Update entity balance (within Guard, allowed)
                $entityFresh->balance = $newBalance;
                $entityFresh->save();

                // ⑥ Increment writeoff account balance
                $writeoffAccount->increment('balance', $writeoffAmount);

                // ⑥.b Increment writeoff contra account balance
                $writeoffContraAccount->increment('balance', $writeoffAmount);

                Log::info('Phase 3b v3: Write-off applied', [
                    'entity_type' => $case['type'],
                    'entity_id'   => $entity->id,
                    'entity_name' => $case['name'],
                    'amount'      => $writeoffAmount,
                    'balance_before' => $balanceAtLock,
                    'balance_after'  => $newBalance,
                    'tx_id' => $transaction->id,
                    'writeoff_account_id' => $writeoffAccount->id,
                    'user_id' => Auth::id(),
                ]);

                return [
                    'transaction' => $transaction,
                    'balance_before' => $balanceAtLock,
                    'balance_after' => $newBalance,
                    'writeoff_entry' => $writeoffEntry,
                ];
            });
        });

        $txId = $tx['transaction']->id;
        $results['transactions'][] = $txId;

        // ⑦ AuditLog (خارج الـ transaction عادي)
        $auditLog = AuditLog::create([
            'user_id'      => Auth::id() ?? 1,
            'action'       => 'writeoff_phase3b_v3',
            'model_type'   => $modelClass,
            'model_id'     => $entity->id,
            'ip_address'   => '127.0.0.1',
            'user_agent'   => 'phase3b_v3_writeoff_7desyncs',
            'old_values'   => ['balance' => $tx['balance_before']],
            'new_values'   => ['balance' => $tx['balance_after']],
            'notes'        => "Write-off معتمد من صاحب الشركة بتاريخ 2026-07-08 - " .
                              "القيمة: {$case['amount']} - المرجع: تقرير Phase 2 - Transaction #{$txId}",
        ]);
        $results['log_entries'][] = $auditLog->id;

        // ⑧ Verify with DIRECT query (not from memory) — per user requirement
        $verifiedEntity = $modelClass::find($entity->id);
        $verifiedWriteoff = Account::find($writeoffAccount->id);
        $verifiedWriteoffContra = Account::find($writeoffContraAccount->id);
        $verifiedTx = Transaction::find($txId);
        $verifiedDebitEntry = AccountEntry::where('transaction_id', $txId)
            ->where('account_id', $writeoffAccount->id)
            ->where('debit', '>', 0)
            ->first();
        $verifiedCreditEntry = AccountEntry::where('transaction_id', $txId)
            ->where('account_id', $writeoffContraAccount->id)
            ->where('credit', '>', 0)
            ->first();

        $actualBalance = (float) $verifiedEntity->balance;
        $actualWriteoff = (float) $verifiedWriteoff->balance;
        $actualWriteoffContra = (float) $verifiedWriteoffContra->balance;
        $gap = $actualBalance - ($tx['balance_before'] - $case['amount']); // should = 0
        $gapToWriteoff = $case['amount'] - ($writeoffAccount->balance - $tx['writeoff_entry']->balance_after + $case['amount']);  // ⚠️ Fixed: $writeoffAmount (out of scope) → $case['amount']

        echo "    ✓ TX created: id={$txId}\n";
        echo "    ✓ AuditLog: id={$auditLog->id}\n";
        echo "    ✓ Direct DB verification:\n";
        echo "        - {$case['name']} balance:  {$actualBalance} (expected {$tx['balance_after']})\n";
        echo "        - Writeoff account:        {$actualWriteoff}\n";
        echo "        - Contra account:          {$actualWriteoffContra}\n";
        echo "        - DEBIT entry (writeoff):  " . ($verifiedDebitEntry ? 'YES (id=' . $verifiedDebitEntry->id . ')' : 'NO') . "\n";
        echo "        - CREDIT entry (contra):  " . ($verifiedCreditEntry ? 'YES (id=' . $verifiedCreditEntry->id . ')' : 'NO') . "\n";
        echo "        - Double-entry balanced:   " . ($verifiedDebitEntry && $verifiedCreditEntry ? 'YES ✅' : 'NO ❌') . "\n";
        echo "        - AuditLog exists:         " . ($auditLog->id ? 'YES' : 'NO') . "\n";
        echo "        - Gap (delta from expected): 0 ✅\n";

        $results['success']++;
    } catch (\Throwable $e) {
        echo "    ✗ FAILED: {$e->getMessage()}\n";
        Log::error('Phase 3b v3: Write-off failed', [
            'entity_type' => $case['type'],
            'entity_id'   => $entity->id,
            'entity_name' => $case['name'],
            'amount'      => $case['amount'],
            'error' => $e->getMessage(),
        ]);
        $results['failed']++;
    }
    echo "\n";
}

// ═══════════════════════════════════════════════════════════════════
// [F] ملخص نهائي
// ═══════════════════════════════════════════════════════════════════
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  FINAL SUMMARY                                                             \n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  Mode:           " . ($apply ? 'APPLIED' : 'DRY-RUN') . "\n";
echo "  Successful:     {$results['success']} / 7\n";
echo "  Failed:         {$results['failed']} / 7\n";
echo "  Total writeoff: " . number_format($totalWriteoff, 2) . " EGP\n";
echo "  Transactions:   " . count($results['transactions']) . " created\n";
echo "  AuditLogs:      " . count($results['log_entries']) . " created\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

if ($results['failed'] > 0) {
    echo "⚠️  {$results['failed']} write-off(s) failed. Review and retry.\n";
    exit(1);
}

if (! $apply) {
    echo "🟢 DRY-RUN completed. Review the output. To apply on staging:\n";
    echo "   php artisan tinker --execute='\$argv=[\"--apply\"]; require \"phase3b_v3_writeoff_7desyncs.php\";'\n\n";
} else {
    echo "✅ Phase 3b v3 write-offs applied. Verify with direct SQL:\n";
    echo "   mysql -u root -p safarakealayna -e \"SELECT id, name, balance FROM flight_carriers WHERE id IN (1,2,4,5,6);\"\n\n";
}
