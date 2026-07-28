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
| 2 | `app/Services/Finance/AccountService.php` | TBD | — |
| 3 | `app/Services/Finance/TreasuryService.php` | TBD | — |
| 4 | `app/Services/Finance/TransactionService.php` | TBD | — |
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
