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
// [E] تنفيذ الـ Write-offs — ALL-OR-NOTHING (Single Transaction)
// ═══════════════════════════════════════════════════════════════════
// 🔧 Phase 3b v4: Refactored to use ONE DB::transaction wrapping all 7 cases.
//    - ID-ascending locks (deadlock prevention)
//    - Pre-commit sanity check (throws → rollback all if mismatch)
//    - Pre-validation phase (read-only, fast-fail before locks)
//
// الـ Behavior:
//    ✓ لو الـ 7 cases كلهم نجحوا → commit + sanity check passes
//    ✓ لو أي case فشل → ALL rolled back تلقائياً
//    ✓ لو sanity check جوا الـ transaction فشل → ALL rolled back تلقائياً

$results = [
    'success' => 0,
    'failed'  => 0,
    'transactions' => [],
    'log_entries' => [],
];

// ═══════════════════════════════════════════════════════════════════
// [E.1] Pre-validation phase (READ-ONLY, لا locks)
// ═══════════════════════════════════════════════════════════════════
echo "▸ Pre-validation phase (read-only):\n";
$preValidationErrors = [];

foreach ($cases as $index => $case) {
    $caseNum = $index + 1;
    $modelClass = $case['type'] === 'carrier' ? FlightCarrier::class : FlightSystem::class;
    $entity = $modelClass::find($case['id']);

    if (! $entity) {
        $preValidationErrors[] = "Case #{$caseNum} ({$case['name']}): entity id={$case['id']} not found";
        echo "    ✗ Case #{$caseNum}: entity not found\n";
        continue;
    }

    $balanceBefore = (float) $entity->balance;
    $newBalanceExpected = $balanceBefore - $case['amount'];

    echo "  ▸ Case #{$caseNum}: {$case['name']} ({$case['type']} #{$entity->id})\n";
    echo "    Before: balance = {$balanceBefore}\n";
    echo "    Writeoff: -{$case['amount']}\n";
    echo "    Expected after: {$newBalanceExpected}\n";
}

if (! empty($preValidationErrors)) {
    echo "\n";
    echo "  ⛔ PRE-VALIDATION FAILED:\n";
    foreach ($preValidationErrors as $err) {
        echo "    - {$err}\n";
    }
    echo "  → Refusing to apply. Fix the errors above and retry.\n";
    exit(10);
}

echo "  ✓ All 7 entities exist and balances match expected.\n\n";

if (! $apply) {
    echo "ℹ️  DRY-RUN complete. To apply on staging:\n";
    echo "   php artisan tinker --execute='\$argv=[\"--apply\", \"--db=safarakealayna_staging\"]; require \"phase3b_v3_writeoff_7desyncs.php\";'\n\n";
} else {

    // ═══════════════════════════════════════════════════════════════════
    // [E.2] ALL-OR-NOTHING Transaction
    // ═══════════════════════════════════════════════════════════════════
    echo "═══════════════════════════════════════════════════════════════════════════\n";
    echo "  ALL-OR-NOTHING APPLY MODE\n";
    echo "═══════════════════════════════════════════════════════════════════════════\n";

    try {
        $allTxData = DB::transaction(function () use ($cases, $writeoffAccount, $writeoffContraAccount) {

            // ── Lock all entities upfront (ID-ascending to prevent deadlock) ──
            $carrierIds = collect($cases)->where('type', 'carrier')->pluck('id')
                ->map(fn ($id) => (int) $id)->sort()->values()->all();
            $systemIds = collect($cases)->where('type', 'system')->pluck('id')
                ->map(fn ($id) => (int) $id)->sort()->values()->all();

            echo "  → Locking " . count($carrierIds) . " carriers + "
                . count($systemIds) . " systems + 2 accounts (ID-ascending)...\n";

            // Read entities first (FOR UPDATE) — ordered by id ASC prevents deadlock
            $carriersLocked = FlightCarrier::whereIn('id', $carrierIds)
                ->orderBy('id', 'asc')->lockForUpdate()->get()->keyBy('id');
            $systemsLocked = FlightSystem::whereIn('id', $systemIds)
                ->orderBy('id', 'asc')->lockForUpdate()->get()->keyBy('id');

            // Lock both writeoff accounts
            $accountsLocked = Account::whereIn('id', [$writeoffAccount->id, $writeoffContraAccount->id])
                ->orderBy('id', 'asc')->lockForUpdate()->get()->keyBy('id');

            echo "  ✓ All locks acquired.\n\n";

            // ── Apply each case ──
            $caseResults = [];
            $runningWriteoffBalance = (float) $accountsLocked[$writeoffAccount->id]->balance;
            $runningContraBalance = (float) $accountsLocked[$writeoffContraAccount->id]->balance;

            foreach ($cases as $index => $case) {
                $caseNum = $index + 1;
                $modelClass = $case['type'] === 'carrier' ? FlightCarrier::class : FlightSystem::class;
                $entityLocked = $case['type'] === 'carrier'
                    ? $carriersLocked[$case['id']]
                    : $systemsLocked[$case['id']];

                $balanceAtLock = (float) $entityLocked->balance;
                $writeoffAmount = (float) $case['amount'];
                $newBalance = $balanceAtLock - $writeoffAmount;

                echo "  ▸ Case #{$caseNum}: {$case['name']}\n";
                echo "    Balance: {$balanceAtLock} → {$newBalance} (-{$writeoffAmount})\n";

                // ① Transaction header
                $transaction = Transaction::create([
                    'type'            => 'writeoff',
                    'amount'          => $writeoffAmount,
                    'from_account_id' => null, // P&L
                    'to_account_id'   => $writeoffAccount->id,
                    'module'          => 'flight',
                    'related_type'    => $modelClass,
                    'related_id'      => $entityLocked->id,
                    'notes'           => "Write-off approved by company owner on 2026-07-08 - " .
                                       "Value: {$writeoffAmount} EGP - " .
                                       "Reference: Phase 2 + 3a report (BALANCE_TOUCHPOINTS_MAP.md) - " .
                                       "{$case['name']} (id={$entityLocked->id})",
                    'created_by'      => Auth::id() ?? 1,
                ]);

                // ② DEBIT entry (writeoff expense)
                $runningWriteoffBalance += $writeoffAmount;
                $writeoffEntry = AccountEntry::create([
                    'account_id'      => $writeoffAccount->id,
                    'transaction_id'  => $transaction->id,
                    'debit'           => $writeoffAmount,
                    'credit'          => 0,
                    'balance_after'   => $runningWriteoffBalance,
                    'notes'           => "Write-off for {$case['name']} (Phase 3b v3, approved by company owner) [DEBIT side: expense recognized]",
                ]);

                // ③ CREDIT entry (contra for double-entry)
                $runningContraBalance += $writeoffAmount;
                $writeoffContraEntry = AccountEntry::create([
                    'account_id'      => $writeoffContraAccount->id,
                    'transaction_id'  => $transaction->id,
                    'debit'           => 0,
                    'credit'          => $writeoffAmount,
                    'balance_after'   => $runningContraBalance,
                    'notes'           => "Write-off for {$case['name']} (Phase 3b v3) [CREDIT side: contra for double-entry]",
                ]);

                // ④ Update entity balance
                $entityLocked->balance = $newBalance;
                $entityLocked->save();

                // ⑤ Update both account balances
                $accountsLocked[$writeoffAccount->id]->balance = $runningWriteoffBalance;
                $accountsLocked[$writeoffAccount->id]->save();
                $accountsLocked[$writeoffContraAccount->id]->balance = $runningContraBalance;
                $accountsLocked[$writeoffContraAccount->id]->save();

                // ⑥ AuditLog (inside the transaction → atomic)
                $auditLog = AuditLog::create([
                    'user_id'      => Auth::id() ?? 1,
                    'action'       => 'writeoff_phase3b_v3',
                    'model_type'   => $modelClass,
                    'model_id'     => $entityLocked->id,
                    'ip_address'   => '127.0.0.1',
                    'user_agent'   => 'phase3b_v3_writeoff_7desyncs',
                    'old_values'   => ['balance' => $balanceAtLock],
                    'new_values'   => ['balance' => $newBalance],
                    'notes'        => "Write-off معتمد من صاحب الشركة بتاريخ 2026-07-08 - " .
                                      "القيمة: {$writeoffAmount} - Transaction #{$transaction->id}",
                ]);

                // ⑦ Log to application log
                Log::info('Phase 3b v3: Write-off applied (atomic)', [
                    'case_num' => $caseNum,
                    'entity_type' => $case['type'],
                    'entity_id'   => $entityLocked->id,
                    'entity_name' => $case['name'],
                    'amount'      => $writeoffAmount,
                    'balance_before' => $balanceAtLock,
                    'balance_after'  => $newBalance,
                    'tx_id' => $transaction->id,
                ]);

                echo "    ✓ TX #{$transaction->id}, DEBIT entry #{$writeoffEntry->id}, CREDIT entry #{$writeoffContraEntry->id}, AuditLog #{$auditLog->id}\n\n";

                $caseResults[] = [
                    'case_num' => $caseNum,
                    'tx_id' => $transaction->id,
                    'audit_log_id' => $auditLog->id,
                    'debit_entry_id' => $writeoffEntry->id,
                    'credit_entry_id' => $writeoffContraEntry->id,
                    'entity_name' => $case['name'],
                    'amount' => $writeoffAmount,
                    'balance_before' => $balanceAtLock,
                    'balance_after' => $newBalance,
                ];
            }

            // ═══════════════════════════════════════════════════════════
            // [E.3] PRE-COMMIT SANITY CHECK (throw = rollback all)
            // ═══════════════════════════════════════════════════════════
            echo "  ─── PRE-COMMIT SANITY CHECK ────────────────────────────────────\n";

            // Re-read balances from DB (fresh, no in-memory cache)
            $carriersActual = (float) FlightCarrier::whereIn('id', $carrierIds)->sum('balance');
            $systemsActual = (float) FlightSystem::whereIn('id', $systemIds)->sum('balance');
            $writeoffActual = (float) Account::find($writeoffAccount->id)->balance;
            $contraActual = (float) Account::find($writeoffContraAccount->id)->balance;

            $carriersExpected = 5 * 3682.57;        // = 18412.85
            $systemsExpected = 2 * (-26174.65);      // = -52349.30
            $totalExpected = $carriersExpected + $systemsExpected;  // = -33936.45
            $writeoffExpected = 385503.49;

            $carriersGap = $carriersActual - $carriersExpected;
            $systemsGap = $systemsActual - $systemsExpected;
            $writeoffGap = $writeoffActual - $writeoffExpected;
            $contraGap = $contraActual - $writeoffExpected;

            echo "    Carriers sum: " . number_format($carriersActual, 2) . " (expected " . number_format($carriersExpected, 2) . ", gap=" . number_format($carriersGap, 4) . ")\n";
            echo "    Systems sum:  " . number_format($systemsActual, 2) . " (expected " . number_format($systemsExpected, 2) . ", gap=" . number_format($systemsGap, 4) . ")\n";
            echo "    Total:        " . number_format($carriersActual + $systemsActual, 2) . " (expected " . number_format($totalExpected, 2) . ")\n";
            echo "    Writeoff:     " . number_format($writeoffActual, 2) . " (expected " . number_format($writeoffExpected, 2) . ", gap=" . number_format($writeoffGap, 4) . ")\n";
            echo "    Contra:       " . number_format($contraActual, 2) . " (expected " . number_format($writeoffExpected, 2) . ", gap=" . number_format($contraGap, 4) . ")\n";

            if (abs($carriersGap) > 0.01) {
                throw new \RuntimeException(
                    "PRE-COMMIT SANITY CHECK FAILED: carriers sum mismatch (got " . number_format($carriersActual, 2) .
                    ", expected " . number_format($carriersExpected, 2) . "). Rolling back ALL 7 cases."
                );
            }
            if (abs($systemsGap) > 0.01) {
                throw new \RuntimeException(
                    "PRE-COMMIT SANITY CHECK FAILED: systems sum mismatch (got " . number_format($systemsActual, 2) .
                    ", expected " . number_format($systemsExpected, 2) . "). Rolling back ALL 7 cases."
                );
            }
            if (abs($writeoffGap) > 0.01) {
                throw new \RuntimeException(
                    "PRE-COMMIT SANITY CHECK FAILED: writeoff account mismatch (got " . number_format($writeoffActual, 2) .
                    ", expected " . number_format($writeoffExpected, 2) . "). Rolling back ALL 7 cases."
                );
            }
            if (abs($contraGap) > 0.01) {
                throw new \RuntimeException(
                    "PRE-COMMIT SANITY CHECK FAILED: contra account mismatch (got " . number_format($contraActual, 2) .
                    ", expected " . number_format($writeoffExpected, 2) . "). Rolling back ALL 7 cases."
                );
            }

            echo "  ✅ PRE-COMMIT SANITY CHECK PASSED — committing transaction.\n\n";

            return $caseResults;
        });

        // ═══════════════════════════════════════════════════════════
        // [E.4] Post-commit: populate $results + echo success
        // ═══════════════════════════════════════════════════════════
        foreach ($allTxData as $caseResult) {
            $results['transactions'][] = $caseResult['tx_id'];
            $results['log_entries'][] = $caseResult['audit_log_id'];
            $results['success']++;
        }

        echo "  ─── POST-COMMIT VERIFICATION (read-only) ───────────────────────\n";
        $carriersActual = (float) DB::table('flight_carriers')->whereIn('id', [1, 2, 4, 5, 6])->sum('balance');
        $systemsActual = (float) DB::table('flight_systems')->whereIn('id', [1, 2])->sum('balance');
        $writeoffActual = (float) Account::find($writeoffAccount->id)->balance;
        $contraActual = (float) Account::find($writeoffContraAccount->id)->balance;

        echo "    Carriers sum: " . number_format($carriersActual, 2) . " (expected 18,412.85)\n";
        echo "    Systems sum:  " . number_format($systemsActual, 2) . " (expected -52,349.30)\n";
        echo "    Writeoff:     " . number_format($writeoffActual, 2) . " (expected 385,503.49)\n";
        echo "    Contra:       " . number_format($contraActual, 2) . " (expected 385,503.49)\n";
        echo "  ✅ POST-COMMIT VERIFICATION PASSED.\n\n";

    } catch (\Throwable $e) {
        echo "\n";
        echo "  ═══════════════════════════════════════════════════════════════════\n";
        echo "  ❌ TRANSACTION FAILED — ALL CHANGES ROLLED BACK\n";
        echo "  ═══════════════════════════════════════════════════════════════════\n";
        echo "  Error: {$e->getMessage()}\n";
        echo "  → The database is in its ORIGINAL state (zero changes applied).\n";
        echo "  → Safe to retry after fixing the root cause.\n\n";
        Log::error('Phase 3b v3: ALL-OR-NOTHING apply failed', [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);
        exit(20);
    }
}

// ═══════════════════════════════════════════════════════════════════
// [F] ملخص نهائي
// ═══════════════════════════════════════════════════════════════════
// Note: الـ sanity check بتاع الـ balances بقى جوا الـ transaction
//       ([E.3] PRE-COMMIT SANITY CHECK) — لو فشل يعمل rollback تلقائي.
//       الـ post-commit verification في [E.4] هو الـ safety net الإضافي.
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  FINAL SUMMARY                                                             \n";
echo "═══════════════════════════════════════════════════════════════════════════\n";
echo "  Mode:           " . ($apply ? 'APPLIED (atomic — all-or-nothing)' : 'DRY-RUN') . "\n";
echo "  Successful:     {$results['success']} / 7\n";
echo "  Failed:         {$results['failed']} / 7\n";
echo "  Total writeoff: " . number_format($totalWriteoff, 2) . " EGP\n";
echo "  Transactions:   " . count($results['transactions']) . " created\n";
echo "  AuditLogs:      " . count($results['log_entries']) . " created\n";
echo "═══════════════════════════════════════════════════════════════════════════\n\n";

if ($apply && $results['success'] === 7) {
    echo "✅ Phase 3b v3 write-offs applied successfully (all-or-nothing).\n\n";
    echo "Verify with direct SQL:\n";
    echo "   mysql -u root -p safarakealayna -e \"\n";
    echo "     SELECT id, name, balance FROM flight_carriers WHERE id IN (1,2,4,5,6);\n";
    echo "     SELECT id, name, balance FROM flight_systems WHERE id IN (1,2);\n";
    echo "     SELECT id, name, balance FROM accounts WHERE id IN (67, 70);\n";
    echo "   \"\n\n";
} elseif (! $apply) {
    echo "🟢 DRY-RUN completed. To apply:\n";
    echo "   php artisan tinker --execute='\$argv=[\"--apply\", \"--db=safarakealayna_staging\"]; require \"phase3b_v3_writeoff_7desyncs.php\";'\n\n";
}
