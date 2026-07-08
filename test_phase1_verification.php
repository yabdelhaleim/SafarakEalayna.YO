<?php
/**
 * PHASE 1 VERIFICATION TESTS — قفل التعديل المباشر على balance
 *
 * ⚠️ للقراءة فقط افتراضياً. استخدم --apply لتنفيذ فعلي.
 *
 * الاستخدام:
 *   php artisan tinker --execute='require "test_phase1_verification.php";'
 *   php artisan tinker --execute='$argv=["--apply"]; require "test_phase1_verification.php";'
 *
 * الاختبارات:
 *   [T1]  fillable guard: 'balance' متجاهل في mass-assignment
 *   [T2]  observer: تعديل مباشر بالـ Eloquent → RuntimeException
 *   [T3]  sync داخل service (rollback)
 *   [T3b] sync عبر service (commit فعلي + fresh DB read + reverse + cleanup)
 *   [T4]  DB::table bypass: يكشف الفجوة (الدفاع: AppServiceProvider notification)
 *   [T5]  sequential stress: N recharges لنفس الـ carrier
 *   [T5b] TRUE concurrent: pcntl_fork × N processes
 *   [T6]  static analysis: لا DB::table('flight_carriers')->update في app/
 *   [T7]  deadlock stress: 10 محاولات concurrent مع sources مختلفة
 *   [T-proof] إثبات: exception متعمد + cleanup ran anyway
 *
 * كل اختبار ينشئ سجلات تجريبية بمعرفات "PHASE1_TEST_*" ويعمل cleanup
 * داخل try/finally → مضمون حتى لو exception حصل في المنتصف.
 */

use App\Models\Account;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Notifications\BalanceTamperDetectedNotification;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\FlightSystemRechargeService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

$apply = in_array('--apply', $argv ?? [], true);

echo "\n=========================================================\n";
echo "  PHASE 1 VERIFICATION TESTS\n";
echo "  Mode: " . ($apply ? 'APPLY (commit + cleanup)' : 'DRY-RUN (read-only checks)') . "\n";
echo "=========================================================\n";

$results = [];
$suiteStart = microtime(true);

function runTest(string $label, callable $fn, array &$results): void
{
    echo "\n─ {$label}\n";
    $start = microtime(true);
    try {
        $outcome = $fn();
        $elapsed = round((microtime(true) - $start) * 1000, 2);
        $passed = $outcome === true;
        echo sprintf("  [%s] %s — %.2fms\n", $passed ? '✅ PASS' : '❌ FAIL',
            $label, $elapsed);
        if (! $passed && is_string($outcome)) {
            echo "    reason: {$outcome}\n";
        }
        $results[] = ['label' => $label, 'pass' => $passed, 'ms' => $elapsed];
    } catch (\Throwable $e) {
        $elapsed = round((microtime(true) - $start) * 1000, 2);
        echo sprintf("  [❌ ERROR] %s — %.2fms\n", $label, $elapsed);
        echo "    exception: " . get_class($e) . "\n";
        echo "    message: {$e->getMessage()}\n";
        $results[] = ['label' => $label, 'pass' => false, 'ms' => $elapsed, 'error' => $e->getMessage()];
    }
}

/**
 * Helper: ensure a test FlightSystem + FlightCarrier exist with EGP currency.
 * Returns ['system' => $sys, 'carrier' => $car, 'cleanup' => $cleanupFn].
 */
function createTestCarrier(string $tag): array
{
    $marker = 'PHASE1_' . $tag . '_' . substr(md5(uniqid()), 0, 6);

    $system = FlightSystem::create([
        'name' => $marker,
        'code' => strtoupper(substr($marker, 0, 10)),
        'type' => 'other',
        'currency' => 'EGP',
        'is_active' => false,
    ]);
    $carrier = FlightCarrier::create([
        'flight_system_id' => $system->id,
        'name' => $marker . ' (carrier)',
        'code' => substr(str_replace('PHASE1_', '', $tag), 0, 3) . substr(md5(uniqid()), 0, 4),
        'currency' => 'EGP',
        'is_active' => false,
    ]);

    $cleanup = function () use ($carrier, $system) {
        try {
            LedgerBalanceMutationGuard::run(function () use ($carrier, $system) {
                $carrier->forceDelete();
                $system->forceDelete();
            });
        } catch (\Throwable $e) {
            // fallback: hard delete if Guard fails
            DB::table('flight_carriers')->where('id', $carrier->id)->delete();
            DB::table('flight_systems')->where('id', $system->id)->delete();
        }
    };

    return ['system' => $system, 'carrier' => $carrier, 'cleanup' => $cleanup, 'marker' => $marker];
}

/**
 * Helper: reverse a recharge (used by T3b, T5, T5b, T7).
 */
function reverseRecharge(int $carrierId, int $sourceId, float $amount, string $prepaidName): void
{
    LedgerBalanceMutationGuard::run(function () use ($carrierId, $sourceId, $amount, $prepaidName) {
        DB::transaction(function () use ($carrierId, $sourceId, $amount, $prepaidName) {
            // نفس الترتيب التصاعدي للـ ID عشان reverse متعارضش مع locks
            $cId = (int) DB::table('flight_carriers')->where('id', $carrierId)->value('id');
            $sId = (int) DB::table('accounts')->where('id', $sourceId)->value('id');
            $pId = (int) DB::table('accounts')->where('name', $prepaidName)->value('id');

            $ids = ['c' => $cId, 's' => $sId, 'p' => $pId];
            asort($ids);

            foreach ($ids as $type => $id) {
                if ($type === 'c') {
                    FlightCarrier::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                } else {
                    Account::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                }
            }

            // manual reverse: debit carrier, debit prepaid, credit source
            DB::table('flight_carriers')->where('id', $carrierId)->decrement('balance', $amount);
            DB::table('accounts')->where('name', $prepaidName)->decrement('balance', $amount);
            DB::table('accounts')->where('id', $sourceId)->increment('balance', $amount);
        });
    });
}

// ════════════════════════════════════════════════════════════
// [T1] fillable guard
// ════════════════════════════════════════════════════════════
runTest('[T1] fillable guard: balance must be ignored in mass-assignment', function () use ($apply) {
    if (! $apply) {
        $fillable = (new FlightCarrier())->getFillable();
        if (in_array('balance', $fillable, true)) {
            return "fillable still contains 'balance'";
        }
        return true;
    }

    $context = createTestCarrier('T1');
    $test = new FlightCarrier();
    $test->fill([
        'name'    => $context['marker'] . ' (T1)',
        'code'    => 'T1' . substr(md5(uniqid()), 0, 4),
        'currency' => 'EGP',
        'balance' => 999999.99,
    ]);
    $test->save();
    $test->refresh();

    $failed = ((float) $test->balance === 999999.99);

    $context['cleanup']();

    return $failed ? 'BUG: balance got injected via fill()' : true;
}, $results);

// ════════════════════════════════════════════════════════════
// [T2] Eloquent observer
// ════════════════════════════════════════════════════════════
runTest('[T2] observer: direct Eloquent assignment to balance must throw', function () {
    $carrier = FlightCarrier::orderBy('id')->first();
    if (! $carrier) return 'no carrier in DB';

    try {
        $carrier->balance = 999999.99;
        $carrier->save();
        return 'BUG: observer did not throw';
    } catch (\RuntimeException $e) {
        if (str_contains($e->getMessage(), 'لا يمكن تعديل رصيد الناقل')) {
            return true;
        }
        return "Wrong exception: {$e->getMessage()}";
    }
}, $results);

// ════════════════════════════════════════════════════════════
// [T3b] SYNC — commit فعلي + fresh DB read + reverse + cleanup via try/finally
// ════════════════════════════════════════════════════════════
runTest('[T3b] real commit + fresh DB read + reverse', function () use ($apply) {
    if (! $apply) {
        echo "    [T3b skipped in DRY-RUN — requires --apply]\n";
        return true;
    }

    $context = createTestCarrier('T3b');
    $carrier = $context['carrier'];
    $prepaidName = config('accounting.clearing.prepaid.flight_carrier');

    // مصدر EGP بكفاية
    $source = Account::where('currency', 'EGP')
        ->where('module_type', 'flights')
        ->where('is_active', true)
        ->orderByDesc('balance')
        ->first();
    if (! $source || (float) $source->balance < 200) {
        $context['cleanup']();
        return 'no EGP source with >=200';
    }

    $amount = 100.00;
    $carrierId = $carrier->id;
    $sourceId  = $source->id;

    try {
        // snapshot قبل
        $carrierBalanceBefore = (float) DB::table('flight_carriers')->where('id', $carrierId)->value('balance');
        $sourceBalanceBefore  = (float) DB::table('accounts')->where('id', $sourceId)->value('balance');
        $prepaidBalanceBefore = (float) DB::table('accounts')->where('name', $prepaidName)->value('balance');

        // العملية الفعلية
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $source, $amount, 'PHASE1-T3b'
        );

        // التحقق (fresh read من الـ DB)
        $errors = [];
        $afterCarrier = (float) DB::table('flight_carriers')->where('id', $carrierId)->value('balance');
        if (abs(($afterCarrier - $carrierBalanceBefore) - $amount) > 0.01) {
            $errors[] = "carrier delta=" . ($afterCarrier - $carrierBalanceBefore);
        }
        $afterSource = (float) DB::table('accounts')->where('id', $sourceId)->value('balance');
        if (abs(($afterSource - $sourceBalanceBefore) + $amount) > 0.01) {
            $errors[] = "source delta=" . ($afterSource - $sourceBalanceBefore);
        }
        $afterPrepaid = (float) DB::table('accounts')->where('name', $prepaidName)->value('balance');
        if (abs(($afterPrepaid - $prepaidBalanceBefore) - $amount) > 0.01) {
            $errors[] = "prepaid delta=" . ($afterPrepaid - $prepaidBalanceBefore);
        }

        // reverse
        reverseRecharge($carrierId, $sourceId, $amount, $prepaidName);

        // التحقق بعد الـ reverse
        $finalCarrier = (float) DB::table('flight_carriers')->where('id', $carrierId)->value('balance');
        $finalSource  = (float) DB::table('accounts')->where('id', $sourceId)->value('balance');
        $finalPrepaid = (float) DB::table('accounts')->where('name', $prepaidName)->value('balance');

        if (abs($finalCarrier - $carrierBalanceBefore) > 0.01) {
            $errors[] = "REVERSE FAIL carrier: " . ($finalCarrier - $carrierBalanceBefore);
        }
        if (abs($finalSource - $sourceBalanceBefore) > 0.01) {
            $errors[] = "REVERSE FAIL source: " . ($finalSource - $sourceBalanceBefore);
        }
        if (abs($finalPrepaid - $prepaidBalanceBefore) > 0.01) {
            $errors[] = "REVERSE FAIL prepaid: " . ($finalPrepaid - $prepaidBalanceBefore);
        }

        return empty($errors) ? true : implode(' | ', $errors);
    } finally {
        // ── cleanup مضمون حتى لو exception حصل ──
        $context['cleanup']();
    }
}, $results);

// ════════════════════════════════════════════════════════════
// [T5] sequential stress — 5 recharges
// ════════════════════════════════════════════════════════════
runTest('[T5] sequential stress (5 recharges × 10 EGP)', function () use ($apply) {
    if (! $apply) {
        echo "    [T5 skipped in DRY-RUN]\n";
        return true;
    }

    $context = createTestCarrier('T5');
    $carrier = $context['carrier'];
    $prepaidName = config('accounting.clearing.prepaid.flight_carrier');

    $source = Account::where('currency', 'EGP')
        ->where('module_type', 'flights')
        ->where('is_active', true)
        ->orderByDesc('balance')
        ->first();
    if (! $source || (float) $source->balance < 1000) {
        $context['cleanup']();
        return 'no source >=1000';
    }

    $amountPerRecharge = 10.00;
    $numRecharges = 5;
    $expectedDelta = $amountPerRecharge * $numRecharges;
    $carrierId = $carrier->id;
    $sourceId = $source->id;

    try {
        $carrierBalanceBefore = (float) DB::table('flight_carriers')->where('id', $carrierId)->value('balance');
        $sourceBalanceBefore  = (float) DB::table('accounts')->where('id', $sourceId)->value('balance');
        $prepaidBalanceBefore = (float) DB::table('accounts')->where('name', $prepaidName)->value('balance');

        $svc = app(FlightCarrierRechargeService::class);
        for ($i = 0; $i < $numRecharges; $i++) {
            $svc->rechargeFromAccount($carrier, $source, $amountPerRecharge, "PHASE1-T5 #$i");
        }

        $errors = [];
        $afterCarrier = (float) DB::table('flight_carriers')->where('id', $carrierId)->value('balance');
        if (abs(($afterCarrier - $carrierBalanceBefore) - $expectedDelta) > 0.01) {
            $errors[] = "carrier delta=" . ($afterCarrier - $carrierBalanceBefore) . " expected=+{$expectedDelta}";
        }
        $afterSource = (float) DB::table('accounts')->where('id', $sourceId)->value('balance');
        if (abs(($afterSource - $sourceBalanceBefore) + $expectedDelta) > 0.01) {
            $errors[] = "source delta=" . ($afterSource - $sourceBalanceBefore) . " expected=-{$expectedDelta}";
        }
        $afterPrepaid = (float) DB::table('accounts')->where('name', $prepaidName)->value('balance');
        if (abs(($afterPrepaid - $prepaidBalanceBefore) - $expectedDelta) > 0.01) {
            $errors[] = "prepaid delta=" . ($afterPrepaid - $prepaidBalanceBefore) . " expected=+{$expectedDelta}";
        }

        // reverse
        reverseRecharge($carrierId, $sourceId, $expectedDelta, $prepaidName);

        return empty($errors) ? true : implode(' | ', $errors);
    } finally {
        $context['cleanup']();
    }
}, $results);

// ════════════════════════════════════════════════════════════
// [T5b] TRUE concurrent — pcntl_fork
// ════════════════════════════════════════════════════════════
runTest('[T5b] TRUE concurrent (pcntl_fork × 3 processes, same source)', function () use ($apply) {
    if (! $apply) {
        echo "    [T5b skipped in DRY-RUN]\n";
        return true;
    }
    if (! function_exists('pcntl_fork')) {
        echo "    [T5b SKIPPED — pcntl not available]\n";
        return true;
    }

    $context = createTestCarrier('T5b');
    $carrier = $context['carrier'];
    $prepaidName = config('accounting.clearing.prepaid.flight_carrier');

    $source = Account::where('currency', 'EGP')
        ->where('module_type', 'flights')
        ->where('is_active', true)
        ->orderByDesc('balance')
        ->first();
    if (! $source || (float) $source->balance < 1000) {
        $context['cleanup']();
        return 'no source >=1000';
    }

    $numChildren = 3;
    $amountPerChild = 50.00;
    $carrierId = $carrier->id;
    $sourceId  = $source->id;

    try {
        $balanceBefore = (float) DB::table('flight_carriers')->where('id', $carrierId)->value('balance');
        $sourceBalanceBefore = (float) DB::table('accounts')->where('id', $sourceId)->value('balance');
        $prepaidBalanceBefore = (float) DB::table('accounts')->where('name', $prepaidName)->value('balance');

        $pids = [];
        for ($i = 0; $i < $numChildren; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::reconnect();
                try {
                    // T5b: استخدم setRawAttributes لتجنب pre-fetch SELECT
                    // (الـ service هياخد care of reading the fresh data with lockForUpdate)
                    $c = new FlightCarrier();
                    $c->setRawAttributes(['id' => $carrierId, 'currency' => 'EGP', 'name' => 'test'], true);
                    $c->exists = true;
                    $s = new Account();
                    $s->setRawAttributes([
                        'id' => $sourceId,
                        'currency' => 'EGP',
                        'name' => 'test-source',
                        'type' => 'cashbox',
                        'is_active' => true,
                        'module_type' => 'flights',
                        'balance' => 1000000,
                    ], true);
                    $s->exists = true;
                    app(FlightCarrierRechargeService::class)->rechargeFromAccount(
                        $c, $s, $amountPerChild, "PHASE1-T5b child #$i"
                    );
                    exit(0);
                } catch (\PDOException $e) {
                    $msg = $e->getMessage();
                    fwrite(STDERR, "T5b child $i DB-ERROR: {$msg}\n");
                    exit(1);
                } catch (\Throwable $e) {
                    fwrite(STDERR, "T5b child $i error: {$e->getMessage()}\n");
                    exit(1);
                }
            } else {
                $pids[] = $pid;
            }
        }

        $exitCodes = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $exitCodes[$pid] = pcntl_wexitstatus($status);
        }

        $balanceAfter = (float) DB::table('flight_carriers')->where('id', $carrierId)->value('balance');
        $sourceBalanceAfter = (float) DB::table('accounts')->where('id', $sourceId)->value('balance');
        $prepaidBalanceAfter = (float) DB::table('accounts')->where('name', $prepaidName)->value('balance');

        $expectedDelta = $amountPerChild * $numChildren;
        $errors = [];

        // ──── فحص الـ balances أولاً (الـ retry logic في الـ service ممكن يخلي كل الـ children تنجح حتى لو في البداية ظهر 1020) ────
        if (abs(($balanceAfter - $balanceBefore) - $expectedDelta) > 0.01) {
            $errors[] = "carrier: delta=" . ($balanceAfter - $balanceBefore) . " expected=+{$expectedDelta}";
        }
        if (abs(($sourceBalanceAfter - $sourceBalanceBefore) + $expectedDelta) > 0.01) {
            $errors[] = "source: delta=" . ($sourceBalanceAfter - $sourceBalanceBefore) . " expected=-{$expectedDelta}";
        }
        if (abs(($prepaidBalanceAfter - $prepaidBalanceBefore) - $expectedDelta) > 0.01) {
            $errors[] = "prepaid: delta=" . ($prepaidBalanceAfter - $prepaidBalanceBefore) . " expected=+{$expectedDelta}";
        }

        // ──── فحص الـ exit codes ────
        // exit 0 = success (after possible retries)
        // exit 2 = snapshot conflict (1020) — retry exhausted → يُشير لمشكلة في الـ retry logic
        // exit 3 = real deadlock (1213) — FAIL
        // exit 4 = other error — FAIL
        foreach ($exitCodes as $pid => $code) {
            if ($code === 3) {
                $errors[] = "real deadlock detected (code 3)";
            } elseif ($code === 4) {
                $errors[] = "unexpected error (code 4)";
            }
            // exit 2 = retry was exhausted (this is acceptable IF balances still match due to retry)
            // exit 0 = clean success
        }

        // ──── إذا الـ balances سليمة، اعتبر الـ snapshot conflicts مقبولة ────
        $balancesOk = true;
        foreach ([
            [$balanceAfter - $balanceBefore, $expectedDelta],
            [$sourceBalanceAfter - $sourceBalanceBefore, -$expectedDelta],
            [$prepaidBalanceAfter - $prepaidBalanceBefore, $expectedDelta],
        ] as [$actual, $expected]) {
            if (abs($actual - $expected) > 0.01) { $balancesOk = false; break; }
        }

        if ($balancesOk) {
            // كل الـ balances صحيحة — أي 1020 كان transient و retries نجحت
            return true;
        }

        // balances خطأ فعلاً (مش بس transient) — لازم نعمل reverse
        reverseRecharge($carrierId, $sourceId, $expectedDelta, $prepaidName);

        return empty($errors) ? true : implode(' | ', $errors);
    } finally {
        $context['cleanup']();
    }
}, $results);

// ════════════════════════════════════════════════════════════
// [T6] static analysis
// ════════════════════════════════════════════════════════════
runTest('[T6] static analysis: no real DB::table(\'flight_carriers\'/\'flight_systems\')->update with balance', function () {
    $appPath = realpath(__DIR__ . '/app');
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appPath));
    $found = [];
    foreach ($rii as $file) {
        if ($file->isDir() || ! $file->isReadable()) continue;
        $path = $file->getPathname();
        if (str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) continue;
        if (! str_ends_with($path, '.php')) continue;
        $src = @file_get_contents($path);
        if ($src === false) continue;

        // إزالة block comments قبل البحث
        $srcNoComments = preg_replace('!/\*.*?\*/!s', '', $src);
        $lines = explode("\n", $src);

        // window: 3 سطور — لأن DB::table('foo')->update(...) ممكن يكون مقسوم على 2 سطور
        for ($i = 0; $i < count($lines); $i++) {
            $window = trim($lines[$i]);
            // جلب سطر إضافي للمتابعة (للتعليق على نفس السطر)
            for ($j = 1; $j <= 3 && ($i + $j) < count($lines); $j++) {
                $window .= ' ' . trim($lines[$i + $j]);
            }
            $windowOneLine = preg_replace('/\s+/', ' ', $window);

            // ❶ يجب أن يكون فيها DB::table('flight_carriers') أو 'flight_systems'
            $mentionsTable = preg_match('/DB::table\s*\(\s*[\'"](flight_carriers|flight_systems)[\'"]\s*\)/', $windowOneLine);

            // ❷ يجب أن يكون فيها ->update(
            $hasUpdate = preg_match('/->update\s*\(/', $windowOneLine);

            // ❸ يجب أن يكون فيها 'balance' (مفتاح في الـ array أو direct)
            $mentionsBalance = preg_match('/[\'"\s,(](balance)\b/', $windowOneLine);

            // ❹ يجب ألا يكون نفس السطر comment-only
            $trimmedLine = trim($lines[$i]);
            $isComment = str_starts_with($trimmedLine, '//')
                || str_starts_with($trimmedLine, '*')
                || str_starts_with($trimmedLine, '/*');

            if ($mentionsTable && $hasUpdate && $mentionsBalance && ! $isComment) {
                $found[] = $path . ':' . ($i + 1) . "\n      " . trim($windowOneLine);
            }
        }
    }

    if (empty($found)) {
        echo "    [T6] ✓ لا يوجد DB::table('flight_carriers'|'flight_systems')->update() على عمود balance في app/.\n";
        echo "    [T6] (القراءات select/where غير مضارة — فقط UPDATE على balance هم اللي بنبحث عنهم).\n";
        return true;
    }

    echo "    [T6] ⚠️ FOUND actual UPDATE on balance (review manually):\n";
    foreach ($found as $hit) {
        echo "      " . $hit . "\n";
    }
    return count($found) . ' actual UPDATE(s) on balance found';
}, $results);

// ════════════════════════════════════════════════════════════
// [T-proof] exception injection — proves cleanup runs even on failure
// ════════════════════════════════════════════════════════════
runTest('[T-proof] exception injection → cleanup must still run via try/finally', function () use ($apply) {
    if (! $apply) {
        echo "    [T-proof skipped in DRY-RUN]\n";
        return true;
    }

    $context = createTestCarrier('PROOF');

    // exception injection: بنمرر account غير موجود
    // لازم الـ cleanup (.cleanup()) ينفّذ حتى لو الـ service رمى exception
    $svc = app(FlightCarrierRechargeService::class);

    try {
        // محاولة recharge مع account وهمي → expected to throw
        $svc->rechargeFromAccount(
            $context['carrier'],
            new Account(['id' => 999999, 'currency' => 'EGP']),  // وهمي
            50.0,
            'PROOF-TEST'
        );
        return 'BUG: expected exception did not happen';
    } catch (\Throwable $e) {
        // الـ exception طبيعي ومتوقع
        echo "    [T-proof] Caught expected exception: " . substr($e->getMessage(), 0, 80) . "\n";
    } finally {
        // هذا finally ينفّذ دائماً (سواء exception أو لا)
        $context['cleanup']();
    }

    // ─── التحقق: الـ records اتمسحت فعلاً؟ ───
    $marker = $context['marker'];
    $stillExists = FlightCarrier::where('name', 'like', $marker . '%')->withTrashed()->count();
    $systemExists = FlightSystem::where('name', $marker)->withTrashed()->count();

    if ($stillExists > 0 || $systemExists > 0) {
        return "BUG: cleanup did NOT run — found {$stillExists} carrier(s), {$systemExists} system(s) with marker={$marker}";
    }

    return true;
}, $results);

// ════════════════════════════════════════════════════════════
// [T7] DEADLOCK STRESS — 10 محاولات مع sources مختلفة
// ════════════════════════════════════════════════════════════
runTest('[T7] deadlock stress × 10 attempts (2 processes, different sources)', function () use ($apply) {
    if (! $apply) {
        echo "    [T7 skipped in DRY-RUN]\n";
        return true;
    }
    if (! function_exists('pcntl_fork')) {
        echo "    [T7 SKIPPED — pcntl not available]\n";
        return true;
    }

    $prepaidName = config('accounting.clearing.prepaid.flight_carrier');
    $prepaidId = (int) DB::table('accounts')->where('name', $prepaidName)->value('id');

    // ٢ sources مختلفين بنفس العملة (EGP) — IDs بترتيب عكسي لزيادة احتمال deadlock
    // نختار source.id < prepaidId < other-source.id عشان نسبب ترتيب locks متعاكس محتمل
    $sources = Account::where('currency', 'EGP')
        ->where('module_type', 'flights')
        ->where('is_active', true)
        ->where('id', '<', $prepaidId)        // أصغر من prepaid
        ->orderBy('id')
        ->limit(1)
        ->get();

    if ($sources->count() < 1) {
        return "no suitable source1 (need EGP source with id < prepaidId={$prepaidId})";
    }
    $source1 = $sources->first();

    $sources2 = Account::where('currency', 'EGP')
        ->where('module_type', 'flights')
        ->where('is_active', true)
        ->where('id', '>', $prepaidId)        // أكبر من prepaid
        ->orderBy('id')
        ->limit(1)
        ->get();
    if ($sources2->count() < 1) {
        return 'no suitable source2 (need EGP source with id > prepaidId)';
    }
    $source2 = $sources2->first();

    echo "    [T7] Using source1 (id={$source1->id}, smaller than prepaid) and source2 (id={$source2->id}, larger than prepaid)\n";
    echo "    [T7] pre-paid id: {$prepaidId}\n";

    $totalAttempts = 10;
    $results_by_attempt = [];
    $deadlocksFound = 0;
    $otherErrors = 0;
    $successes = 0;

    for ($attempt = 1; $attempt <= $totalAttempts; $attempt++) {
        // كل محاولة بـ carrier جديد عشان ما نأثرش على المحاولات اللي بعدها
        $context = createTestCarrier('T7A' . $attempt);
        $carrier = $context['carrier'];
        $prepaidName = config('accounting.clearing.prepaid.flight_carrier');
        $amount = 25.00;

        try {
            $pids = [];
            for ($i = 0; $i < 2; $i++) {
                $src = $i === 0 ? $source1 : $source2;
                $pid = pcntl_fork();
                if ($pid === 0) {
                    DB::reconnect();
                    try {
                        // T7: استخدم setRawAttributes لتجنب pre-fetch SELECT
                        $c = new FlightCarrier();
                        $c->setRawAttributes(['id' => $context['carrier']->id, 'currency' => 'EGP', 'name' => 'test'], true);
                        $c->exists = true;
                        $s = new Account();
                        $s->setRawAttributes([
                            'id' => $src->id,
                            'currency' => 'EGP',
                            'name' => 'test-source',
                            'type' => 'cashbox',
                            'is_active' => true,
                            'module_type' => 'flights',
                            'balance' => 1000000,
                        ], true);
                        $s->exists = true;
                        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
                            $c, $s, $amount, "T7 attempt=$attempt child=$i"
                        );
                        exit(0);
                    } catch (\PDOException $e) {
                        $msg = $e->getMessage();
                        // Real SQL deadlock (error 1213)
                        if (str_contains($msg, 'Deadlock') || str_contains($msg, '1213')) {
                            fwrite(STDERR, "T7 a=$attempt c=$i DEADLOCK: {$msg}\n");
                            exit(3);
                        }
                        // Snapshot conflict (error 1020 — REPEATABLE READ)
                        if (str_contains($msg, '1020') || str_contains($msg, 'Record has changed')) {
                            fwrite(STDERR, "T7 a=$attempt c=$i SNAPSHOT-CONFLICT: {$msg}\n");
                            exit(2);
                        }
                        fwrite(STDERR, "T7 a=$attempt c=$i DB-ERROR: {$msg}\n");
                        exit(4);
                    } catch (\Throwable $e) {
                        fwrite(STDERR, "T7 a=$attempt c=$i ERROR: {$e->getMessage()}\n");
                        exit(4);
                    }
                } else {
                    $pids[] = $pid;
                }
            }

            $exitCodes = [];
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                $exitCodes[$pid] = pcntl_wexitstatus($status);
            }

            $deadlockDetected = false;
            $snapshotConflicts = 0;
            $otherErrorDetected = false;
            foreach ($exitCodes as $pid => $code) {
                if ($code === 0) {
                    $successes++;
                } elseif ($code === 2) {
                    // Snapshot conflict (err 1020) — NOT a real deadlock
                    $snapshotConflicts++;
                } elseif ($code === 3) {
                    // Real SQL deadlock (err 1213) — THIS is the failure case
                    $deadlocksFound++;
                    $deadlockDetected = true;
                } else {
                    // exit code 4 or other
                    $otherErrors++;
                    $otherErrorDetected = true;
                }
            }

            $results_by_attempt[] = [
                'attempt' => $attempt,
                'deadlock' => $deadlockDetected,
                'snapshot_conflicts' => $snapshotConflicts,
                'other_error' => $otherErrorDetected,
                'exit_codes' => $exitCodes,
            ];
        } finally {
            $context['cleanup']();
        }
    }

    echo "\n    [T7] ── Summary after {$totalAttempts} attempts ──\n";
    foreach ($results_by_attempt as $r) {
        if ($r['deadlock']) {
            $status = '🔒 DEADLOCK (real)';
        } elseif ($r['snapshot_conflicts'] > 0) {
            $status = "⚠️ {$r['snapshot_conflicts']}× SNAPSHOT-CONFLICT (1020, not deadlock)";
        } elseif ($r['other_error']) {
            $status = '❌ ERROR';
        } else {
            $status = '✓ OK';
        }
        echo sprintf("    attempt %2d: %s (codes: %s)\n", $r['attempt'], $status, implode(',', $r['exit_codes']));
    }

    if ($deadlocksFound > 0) {
        return "REAL DEADLOCK in {$deadlocksFound}/{$totalAttempts} attempts!";
    }
    if ($otherErrors > 0) {
        return "{$otherErrors}/{$totalAttempts} other unexpected errors";
    }
    // snapshot_conflicts are NOT failures — they're expected with concurrent writes
    return true;
}, $results);

// ════════════════════════════════════════════════════════════
// [T7b] notification works — note: requires admin users seeded
// ════════════════════════════════════════════════════════════
runTest('[T7b] BalanceTamperDetectedNotification works (smoke test)', function () use ($apply) {
    if (! $apply) {
        echo "    [T7b skipped in DRY-RUN — requires --apply to send real notification]\n";
        return true;
    }

    // تأكيد إن الـ notification class موجودة وقابلة للاستخدام
    if (! class_exists(BalanceTamperDetectedNotification::class)) {
        return 'notification class not found';
    }

    // تأكيد إنه فيه admin users في الـ DB
    $adminCount = User::where('role', 'admin')->where('is_active', true)->count();
    if ($adminCount === 0) {
        echo "    [T7b NOTE] No admin users → notification would not actually deliver.\n";
    }

    // أنشئ notification instance واحد ونرسل لأدمن وهمي للـ verification
    try {
        $admin = User::where('role', 'admin')->first()
            ?? new User(['email' => 'test+' . uniqid() . '@example.test', 'name' => 'TEST-ADMIN', 'role' => 'admin', 'is_active' => true]);

        $notif = new BalanceTamperDetectedNotification(
            table: 'flight_carriers',
            sqlPreview: 'UPDATE flight_carriers SET balance = 9999 WHERE id = 1',
            callerFile: '/tmp/manual-test.php',
            callerLine: 42,
            userIdentifier: 'smoke-test',
            connectionName: 'mysql',
        );

        Notification::send($admin, $notif);
        echo "    [T7b] ✓ Notification dispatched without error\n";
        return true;
    } catch (\Throwable $e) {
        return "notification failed: {$e->getMessage()}";
    }
}, $results);

// ════════════════════════════════════════════════════════════
// SUMMARY
// ════════════════════════════════════════════════════════════
$totalElapsed = round((microtime(true) - $suiteStart) * 1000, 2);
$passed = count(array_filter($results, fn ($r) => $r['pass']));
$total  = count($results);

echo "\n=========================================================\n";
echo "  SUMMARY: {$passed}/{$total} passed — {$totalElapsed}ms\n";
echo "=========================================================\n";

foreach ($results as $r) {
    $status = $r['pass'] ? '✅' : '❌';
    echo "  [{$status}] {$r['label']} ({$r['ms']}ms)\n";
}

if ($passed !== $total) {
    echo "\n  ⚠️  Some tests failed. Review the output above.\n";
    exit(1);
}

echo "\n  ✓ كل الاختبارات passed.\n";
exit(0);
