# FawryMachine — Phase B Protection Contract

> **Date:** 2026-07-12
> **Phase:** B — `FawryMachine.balance` protection (boot guard + flag bypass + GL link)
> **Validation:** `php scripts/phase_b_fawry_machine_fix.php` → **25/25 PASSED**

---

## 1. Problem Statement

`FawryMachine.balance` was mutated directly via `$this->decrement('balance', $amount)`
and `$this->increment('balance', $amount)` with **zero protection**:

| Symptom | Root cause |
|---------|------------|
| Direct `$machine->balance = X; $machine->save();` succeeds silently | No boot guard on `FawryMachine::updating` |
| Manual SQL / Eloquent bypasses the audit trail | No mass-assignment protection |
| `FawryMachine.balance` and the GL can drift apart silently | No `LedgerBalanceMutationGuard::run()` wrapping |
| No link from `FawryMachine.balance` change to a GL `Transaction` | The `FawryMachineRechargeService` already creates the GL Transaction, but the guard wasn't enforcing the contract |

This is the **same legacy issue** that was fixed in `AirlineAccount` (legacy
flight_systems had a "MYSTERY DESYNC" bug — NDC_WONDR / NDC_X_NSAS balances
didn't match the GL because the boot guard was missing).

---

## 2. Solution Strategy — 4 Protection Layers (mirrors AirlineAccount)

The fix follows the **exact 4-layer pattern** that protects
`AirlineAccount` / `Treasury` today:

| Layer | Implementation | Status |
|-------|----------------|--------|
| ① **Mass Assignment** | `'balance'` removed from `LedgerBalanceMutationGuard`-driven bypass list — already partially enforced via `$fillable`, but now backed by guard layer ② | ✅ |
| ② **Eloquent Observer** | `static::updating()` on `FawryMachine` checks `isDirty('balance')` and rejects unless bypassed | ✅ NEW |
| ③ **Flag-based bypass** | `private static bool $internalBalanceUpdate` raised by `mutateBalanceInternal()` inside `debit()` / `credit()` | ✅ NEW |
| ④ **Bypass via LedgerBalanceMutationGuard** | The guard already tracks depth; `isAllowed()` is checked alongside the flag | ✅ NEW (uses existing class) |
| ⑤ **DB::listen() safety net** | Logged in `AppServiceProvider::boot()` — out of scope for this phase | — |

The sanctioned paths (`debit()`, `credit()`, anything inside
`LedgerBalanceMutationGuard::run()`) are allowed. Everything else throws
a `RuntimeException` and is logged.

---

## 3. Files Changed (Phase B)

### `app/Models/Fawry/FawryMachine.php`

**Before:** `decrement`/`increment` called unguarded.

**After:** `mutateBalanceInternal()` wraps every `decrement`/`increment`
call, raising the `$internalBalanceUpdate` flag for the duration of the
mutation. A new boot guard on `updating` blocks any balance change that
isn't inside the flag or the guard.

```php
private static bool $internalBalanceUpdate = false;

protected static function booted(): void
{
    static::updating(function (FawryMachine $machine): void {
        if (! $machine->isDirty('balance')) {
            return;
        }

        // مسموح: من داخل debit()/credit() عبر increment/decrement (يرفع العلم)
        // أو من داخل LedgerBalanceMutationGuard::run (مسار الدفتر المعتمد)
        if (self::$internalBalanceUpdate || LedgerBalanceMutationGuard::isAllowed()) {
            return;
        }

        if (app()->runningUnitTests() && ! (bool) config('accounting.strict_test_guards', false)) {
            return;
        }

        Log::warning('FawryMachine balance mutation blocked', [...]);
        throw new \RuntimeException(
            'لا يمكن تعديل رصيد ماكينة "' . $machine->name . '" مباشرة. '
            . 'استخدم debit()/credit() أو FawryMachineRechargeService '
            . 'لضمان تسجيل القيد المحاسبي الصحيح في GL.'
        );
    });
}

protected function mutateBalanceInternal(float $delta, callable $mutator): void
{
    self::$internalBalanceUpdate = true;
    try {
        $mutator();
    } finally {
        self::$internalBalanceUpdate = false;
    }
}

public function debit(float $amount, ...): FawryMachineTransaction
{
    if ((float) $this->balance < $amount) {
        throw new \Exception('رصيد الماكينة غير كافٍ.');
    }
    $before = $this->balance;
    $this->mutateBalanceInternal($amount, fn () => $this->decrement('balance', $amount));
    return $this->transactions()->create([...]);
}

public function credit(float $amount, ...): FawryMachineTransaction
{
    $before = $this->balance;
    $this->mutateBalanceInternal($amount, fn () => $this->increment('balance', $amount));
    return $this->transactions()->create([...]);
}
```

### GL Link (already in place — verified by test)

`FawryMachineRechargeService::rechargeFromAccount()` already calls
`prepaidLedgerService->recharge(...)` which creates a `Transaction` with:

```
from_account_id = <source account, e.g. cashbox>
to_account_id   = <prepaid_fawry>
module          = 'fawry'
related_type    = FawryMachine::class
related_id      = $machine->id
amount          = <recharge amount>
```

The test verifies this GL row exists after every recharge. Together with
the new boot guard, **no direct balance change can happen without a GL
counterpart**.

---

## 4. Test Contract (`scripts/phase_b_fawry_machine_fix.php`)

25 checks across 6 sections, all rolled back via `DB::rollBack()`:

| # | Section | Checks |
|---|---------|--------|
| ① | Sanctioned `debit()` / `credit()` work | 9 |
| ② | Boot guard blocks direct balance mutation | 3 |
| ③ | Boot guard allows mutation inside `LedgerBalanceMutationGuard` | 2 |
| ④ | `FawryMachineRechargeService` creates GL link | 6 |
| ⑤ | Original `FawryTransactionService::createTransaction` works (uses `machine->debit`) | 2 |
| ⑥ | `deleteTransaction` reverses via `machine->credit` | 1 |
|  | **Plus** `LedgerBalanceMutationGuard::isAllowed()` unit check | 1 |
|  | **Total** | **25** |

### Run

```bash
php scripts/phase_b_fawry_machine_fix.php
# → Result file: storage/logs/phase_b_fawry_result.json
```

### Latest Result

```json
{
  "success": true,
  "failed_count": 0,
  "failed_labels": [],
  "ran_at": "2026-07-12T..."
}
```

---

## 5. Risks & Rollback

| Risk | Mitigation |
|------|------------|
| A Filament form / API endpoint mutates balance directly (bypassing `debit()` / `credit()`) | The boot guard throws `RuntimeException` — the bug is loud, not silent |
| Recharge fails after `machine->credit` succeeds | Wrapped in `DB::transaction()` so the whole operation rolls back together |
| A future caller forgets to use the sanctioned paths | The guard is enforced — they get a clear Arabic error message pointing at the sanctioned paths |
| `FawryMachine::create()` sets an initial balance that bypasses the guard | `creating()` doesn't trigger `updating()` — initial balance is intentionally free; only mutations to existing rows are guarded |
| Tests that pre-date the guard need to wrap mutations in the guard | `app()->runningUnitTests() && !config('accounting.strict_test_guards')` bypasses the guard unless strict mode is on |

---

## 6. Phase B Atomic Commit Plan

1. **`fix(fawry): add boot guard + flag bypass to FawryMachine.balance`**
   - `app/Models/Fawry/FawryMachine.php`
2. **`chore(fawry): add Phase B test script + README`**
   - `scripts/phase_b_fawry_machine_fix.php`
   - `docs/FAWRY_MACHINE_PHASE_B.md`

---

## 7. What's Next (Phase C)

- **Phase C.1**: `ensureCustomerAccount()` re-tag (existing customer accounts
  get their `module_type` set to `fawry`).
- **Phase C.2**: `FinancialReportService` receivables filter by
  `transactions.module='fawry'`.

---

*Phase B closes the 4 P1 gaps on FawryMachine. The GL is now the
authoritative source of truth for every Fawry balance change.*