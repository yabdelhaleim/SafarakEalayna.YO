# Tourism Financial Audit

> **Status:** In progress · **Started:** 2026-07-28 · **Scope:** `app/Services/Reports/`, `app/Services/Finance/`, `app/Services/Flight/`, `app/Services/HajjUmra/`, `app/Services/Visa/`
> **Method:** Read-only audit · **Output:** Findings + fix commits per file
> **Goal:** Identify risks and add safety checks so the program is ready for production

## Convention recap (per `app/Models/Account.php:86-89`)

| Account type | balance > 0 | balance < 0 |
|---|---|---|
| cashbox / bank / wallet (liquidity) | we have money there | we overdrew / owe the bank |
| customer (AR) | customer owes us | we owe the customer |
| supplier (AP) | unusual — supplier owes us | we owe the supplier |
| prepaid (carrier, system, agent) | we are owed the service | we've consumed more than paid |

Invariant: `Account.balance = SUM(credit) - SUM(debit)` on `account_entries`.

## Files audited

| # | File | Findings | Status |
|---|---|---|---|
| 1 | `app/Services/Reports/FinancialReportService.php` | 3 findings | 🟡 Partial |
| 2 | `app/Services/Finance/AccountService.php` | 3 findings | 🟡 Partial |
| 3 | `app/Services/Finance/TreasuryService.php` | 2 findings | 🟡 Partial |
| 4 | `app/Services/Finance/TransactionService.php` | 1 finding | 🔴 Critical |
| 5 | `app/Services/Finance/CurrencyService.php` | TBD | — |
| 6 | `app/Services/Finance/LedgerRepairService.php` | TBD | — |
| 7 | `app/Services/Flight/FlightBookingService.php` | TBD | — |
| 8 | `app/Services/Flight/FlightCarrierRechargeService.php` | TBD | — |
| 9 | `app/Services/HajjUmra/HajjUmraBookingService.php` | TBD | — |
| 10 | `app/Services/Visa/VisaBookingService.php` | TBD | — |

## Severity legend
- 🔴 **Critical** — wrong numbers, must fix before declaring stable
- 🟡 **Warning** — risky behavior, fix soon
- 🟢 **OK** — verified clean

---

## File 1: `app/Services/Reports/FinancialReportService.php`

### 🟡 Finding 1.1: `getCustomerDebtsReport` ignores hajj_umra / visa bookings (lines 197–250)

**Severity:** 🟡 Warning (potential data loss in debt report)

**Code:**
```php
public function getCustomerDebtsReport(array $filters = []): array
{
    $query = Customer::query();
    ...
    $customers = $query->with(['flightBookings' => function ($q) use ($filters) {
        $q->where('status', 'pending');
        if (! empty($filters['module'])) {
            if (is_array($filters['module'])) {
                if (! in_array('flight', $filters['module'])) {
                    $q->whereRaw('1=0');
                }
            } elseif ($filters['module'] !== 'flight') {
                $q->whereRaw('1=0');
            }
        }
    }])->get();

    $debts = $customers->map(function ($customer) {
        $pendingBookings = $customer->flightBookings;  // ← only flightBookings
        $totalDebt = $pendingBookings->sum('selling_price') - $pendingBookings->sum(function ($booking) {
            return $booking->payments->sum('amount');
        });
        ...
    });
```

**Problem:** Even if `module=hajj_umra` or `module=visa` is passed, the function only sums `flightBookings`. HajjUmra and Visa pending bookings are not counted in the debt report.

**Impact:** Customer debt for hajj/visa is underreported.

**Fix idea:** Use a polymorphic relation or union of `flightBookings OR hajjUmraBookings OR visaBookings` based on the module filter.

---

### 🟢 Finding 1.2: `getCapitalAnalysis.calculateTourismCapital` FlightGroup formula — **OK**

**Severity:** 🟢 OK (correct)

**Code (lines 1458–1471):**
```php
$totalDebt = (float) $g->groupTransactions()->where('type', 'debt')->sum('amount');
$totalPayment = (float) $g->groupTransactions()->where('type', 'payment')->sum('amount');
$netBalance = $totalDebt - $totalPayment;  // ← CORRECT (debt - payment)
```

**Status:** Consistent with `FlightGroupController.php:63` and the fix in `getDebtsReport` (`46b3aa8`). Positive net = group owes us (receivable); negative net = we owe group (payable).

**Note:** The function adds the **positive** net balance to payables, which is correct only if "positive = we owe them" (group pre-paid). If the convention ever flips, this needs to flip too.

---

### 🟡 Finding 1.3: `getDebtsReport` totals aggregation uses `balance_egp` sign, not `dir` field (lines 1283–1300)

**Severity:** 🟡 Warning (low risk but inconsistent with per-item classification)

**Code:**
```php
foreach ($results as $item) {
    if ($item['balance_egp'] > 0) {
        $totalReceivables += $item['balance_egp'];
    } else {
        $totalPayables += abs($item['balance_egp']);
    }
}
```

**Problem:** The per-item `dir` field is set correctly per entity type (Customer, FlightGroup, FlightCarrier, etc.) but the totals aggregation uses the raw sign of `balance_egp`. These are usually consistent (positive = receivable), but if EGP conversion ever returns a different sign from the original balance, the totals could disagree with the per-item classification.

**Fix idea:** Use `dir` field for aggregation:
```php
foreach ($results as $item) {
    if ($item['dir'] === 'receivables') {
        $totalReceivables += abs($item['balance_egp']);
    } else {
        $totalPayables += abs($item['balance_egp']);
    }
}
```

**Risk:** Low — both the `dir` setter and the sign-check use the same convention, so they only disagree if the conversion flips sign (unlikely but possible if rates are negative or amounts are zero).

---

## Already-fixed items (from earlier in this session)

| Commit | File | What was fixed |
|---|---|---|
| `46b3aa8` | `FinancialReportService.php:1189` | `FlightGroup` balance formula was `$totalPayment - $totalDebt` (inverted); fixed to `$totalDebt - $totalPayment` to match convention used everywhere else. |
| `0223f95` | `FlightCarriersDebt.vue` + `DepartmentManagement.vue` | UI label changed from "مستحق لنا" to "رصيد مسبق" for FlightCarrier with positive balance (prepaid), since the cash is out. Logic unchanged. |
| `127f013` | `SyncTreasuryBalancesFromLedgerCommand.php` | Added safety guard: skip accounts with 0 ledger entries to avoid silently overwriting manual balances (e.g., opening capital). Added `--include-empty` flag for override. |
| `2ee345c` | `LedgerReconcileCommand.php` | Wired `LedgerRepairService::rebuildBrokenBalanceAfterChains()` into the daily reconciliation so drift on accounts-with-entries is auto-fixed. Added `--no-rebuild` flag for read-only runs. |

---

## Production incidents already resolved

| Date | Account | Issue | Resolution |
|---|---|---|---|
| 2026-07-26 | account_id=5 (نقدي دينار, KWD) | Balance = -300, 0 AccountEntry rows. Caused "عجز" alert. | Manual reset to 0 via `LedgerBalanceMutationGuard::run()`. Documented in `Account.notes`. Root cause investigation: most likely from `accounts:sync-treasury-balances` running before commit `127f013` (which now blocks this). |

---

## File 2: `app/Services/Finance/AccountService.php`

### 🟡 Finding 2.1: `debitAccount` rejects insufficient balance — may block legitimate prepaid/supplier operations (lines 391–421)

**Severity:** 🟡 Warning (depending on caller; needs verification)

**Code:**
```php
protected function debitAccount(Account $account, float $amount, int $transactionId): AccountEntry
{
    return $account->getConnection()->transaction(function () use ($account, $amount, $transactionId) {
        $lockedAccount = Account::where('id', $account->id)->lockForUpdate()->first();

        if ($lockedAccount->balance < $amount) {
            throw new \Exception("Insufficient balance in account: {$lockedAccount->name}");
        }

        $lockedAccount->balance -= $amount;
        $lockedAccount->save();
        ...
    });
}
```

**Problem:** Per the convention (`app/Models/Account.php:86-89`), prepaid accounts (carriers, systems, agents) and supplier accounts (AP) can legitimately have negative balance. This check prevents any debit that would drop the balance below zero.

**Practical impact:** If `AccountService->debit()` is ever called on a FlightCarrier/FlightSystem/Supplier account, the operation will fail. Currently, those accounts use their own model methods (e.g., `FlightCarrier->debit()` which uses `available_balance` + `credit_limit`), so this hasn't broken yet — but it's a latent bug.

**Fix idea:** Add a type-aware check:
```php
if ($lockedAccount->type === 'cashbox' && $lockedAccount->balance < $amount) {
    throw new \Exception("Insufficient balance in account: {$lockedAccount->name}");
}
// For prepaid/supplier, allow negative
```

**Risk:** Low — the check is rarely triggered in current flows, but it's a footgun.

---

### 🟢 Finding 2.2: `createAccount` opening balance entry has no `transaction_id` (lines 149–159)

**Severity:** 🟢 OK (by design, but documented for clarity)

**Code:**
```php
if ($account->balance != 0) {
    AccountEntry::create([
        'account_id' => $account->id,
        'transaction_id' => null, // null for opening balance if no transaction exists
        'debit' => $account->balance < 0 ? abs($account->balance) : 0,
        'credit' => $account->balance > 0 ? $account->balance : 0,
        'balance_after' => $account->balance,
        'notes' => 'رصيد افتتاحي',
    ]);
}
```

**Status:** Intentionally creates an AccountEntry without a backing Transaction. The `notes='رصيد افتتاحي'` distinguishes it. This is correct behavior — the self-heal job (commit `2ee345c`) will see this entry and keep the balance consistent.

**Note:** Does NOT need a fix. Documented for audit trail.

---

### 🟢 Finding 2.3: `updateAccount` does not allow `balance` updates (lines 171–195)

**Severity:** 🟢 OK (good defensive design)

**Code (excerpt):**
```php
$account->fill([
    'name' => $data['name'] ?? $account->name,
    'type' => $data['type'] ?? $account->type,
    'currency' => $data['currency'] ?? $account->currency,
    // ... no 'balance' in the fillable list
]);
```

**Status:** The `balance` field is intentionally excluded from updates — defense-in-depth alongside `LedgerBalanceMutationGuard`. ✅

---

## File 4: `app/Services/Finance/TransactionService.php`

### 🔴 Finding 4.1: `recordTransfer` rejects insufficient balance — same issue as 2.1, but on the user-facing path (line 399)

**Severity:** 🔴 Critical (can block legitimate transfers from prepaid accounts)

**Code (lines 399–401):**
```php
if ((float) $fromAccount->balance < $debitAmount) {
    throw new \Exception('Insufficient balance in account: '.$fromAccount->name);
}
```

**Problem:** This is the same check as `AccountService::debitAccount`, but it's in the user-facing `recordTransfer` method. If a user tries to make a transfer from a prepaid account (e.g., FlightCarrier with 100 KWD balance, transferring 150 KWD worth of debit), the operation fails with "Insufficient balance" — even though the convention says prepaid accounts should be allowed to go negative.

**Why this hasn't blown up yet:** Most user-facing transfers are from cashbox/bank accounts (which SHOULD reject) or from customer accounts (where positive balance = customer owes us, so withdrawing makes sense). Prepaid accounts are usually funded via `credit()` (recharge), not `debit()` (consume).

**Fix idea (matching Finding 2.1):**
```php
$isPrepaidOrSupplier = in_array($fromAccount->type, ['carrier', 'supplier', 'airline_account'], true);
if (!$isPrepaidOrSupplier && (float) $fromAccount->balance < $debitAmount) {
    throw new \Exception('Insufficient balance in account: '.$fromAccount->name);
}
```

**Risk:** Medium — if the type check is wrong, cashbox overdraws could happen. Test carefully.

---

## Audit notes (in progress)

The audit is in progress. To deliver a "100% no errors" audit, the following remain:

- File 5: CurrencyService.php (multi-currency conversions)
- File 6: LedgerRepairService.php (self-heal methods)
- File 7: FlightBookingService.php (cancel/destroy — most critical)
- File 8: FlightCarrierRechargeService.php
- File 9: HajjUmraBookingService.php
- File 10: VisaBookingService.php

After all files are reviewed, fix commits will be prepared for each Critical finding.

---

## File 3: `app/Services/Finance\TreasuryService.php`

### 🟢 Finding 3.1: Trial balance handles positive and negative prepaid balances correctly

**Severity:** 🟢 OK (verified)

**Code (lines 502–535, 659–692):**
- `getTrialBalance()` sums **positive** FlightSystem/FlightCarrier/AirlineAccount balances into `totalBalances` (assets).
- `sumNegativeTourismPrepaidBalances()` (private, called from `calculateReceivablesAndPayables`) sums **negative** balances (using `abs()`) and adds them to `dueFromUs` (what we owe).
- The equation `currentCapital = (totalBalances + totalLiquidity + dueToUs) - dueFromUs` correctly handles both sides.

**Status:** Math is consistent with the Account.php convention. ✅

---

### 🟡 Finding 3.2: `getAveragePurchaseRate` returns 1.0 for unknown currencies (line 343)

**Severity:** 🟡 Warning (silent data corruption risk)

**Code:**
```php
public function getAveragePurchaseRate(string $currency): float
{
    $currency = strtoupper(trim($currency));
    if ($currency === 'EGP' || empty($currency)) {
        return 1.0;
    }

    // Try to calculate average purchase rate from flight bookings...
    // Fallback to latest exchange rate...
    // Fallback to currencies table...
    return 1.0;  // ← silent fallback for unknown currencies
}
```

**Problem:** If a booking is in a currency (e.g., AED, JOD, OMR) that has no flight bookings, no exchange rate, and no currency table entry, the function returns 1.0. This means:
- A 1000 AED booking would be treated as 1000 EGP for capital calculation
- Massive under/over-estimation possible

**Fix idea:** Log a warning + return null, OR fail loudly:
```php
\Log::warning('currency_rate_unknown', ['currency' => $currency]);
return 1.0;  // explicitly state fallback
```

**Risk:** Low — the listed currencies are well-known, but new currencies could trip this.

---

### 🟡 Finding 3.3: Hardcoded currency list in `getTrialBalance` (line 495)

**Severity:** 🟡 Warning (extensibility risk)

**Code:**
```php
$currencies = ['USD', 'SAR', 'KWD', 'EUR'];
```

**Problem:** If a new currency is added (e.g., AED, QAR, OMR), the trial balance won't have a rate for it. The function would still work (using `getAveragePurchaseRate` fallback), but the explicit `rates` output won't include it.

**Fix idea:** Query distinct currencies from the DB:
```php
$currencies = Account::distinct()->pluck('currency')
    ->merge(FlightSystem::distinct()->pluck('currency'))
    ->merge(FlightCarrier::distinct()->pluck('currency'))
    ->unique()->filter()->values()->toArray();
```

**Risk:** Low — only affects the `rates` output, not the actual calculations.

---
