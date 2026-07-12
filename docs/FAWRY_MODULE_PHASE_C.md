# Fawry Module — Phase C Cleanup Contract

> **Date:** 2026-07-12
> **Phase:** C — `ensureCustomerAccount` re-tag + `FinancialReportService` fawry share isolation
> **Validation:** `php scripts/phase_c_fawry_cleanup.php` → **13/13 PASSED**

---

## 1. Problem Statement

Two P1/P2 cleanup gaps remained after Phase A + B:

### 1.1 `ensureCustomerAccount()` didn't re-tag pre-existing accounts

`CustomerLedgerObserver` (fires on `Customer::created`) creates a generic
account tagged `module_type='office'` for every new customer. When that
customer was later used by a Fawry transaction, `ensureCustomerAccount()`
returned the existing account as-is — it was **still tagged `'office'`**.
The account therefore:

- Did NOT appear in `TransferDashboardController` fawry aggregations
  (which filter `module_type='fawry'`).
- Did NOT match the `AccountModuleDivision::moduleLabel('fawry')` filter
  in the office receivables report.

Same pattern was fixed in Wallet Phase 8 — this is the Fawry equivalent.

### 1.2 `FinancialReportService` receivables mixed modules on the same customer

`calculateOfficeCapital()` summed `Account.balance` directly on customer
accounts tagged `'office'`. If a customer had transactions from multiple
office modules (e.g., bus + fawry), the balance represented the **total
across all modules** — not just fawry.

For a customer with `busBookings > 0` AND `fawryTransactions > 0`, the
fawry receivables number was inflated by the bus share. This was the
**last remaining source of truth collision** for the "fawry debtors not
showing correctly" complaint.

---

## 2. Solution Strategy

### 2.1 Re-tag pre-existing customer accounts

Same pattern as Wallet Phase 8 — when `ensureCustomerAccount()` finds a
pre-existing `customer.account_id`, check if it's tagged correctly. If
not, wrap a `module_type='fawry'` update in `LedgerBalanceMutationGuard`
(to satisfy the `Account::updating` boot guard) and save.

### 2.2 Isolate Fawry share in FinancialReportService

Replace the unconditional `$c->ledgerAccount->balance` sum with a
**branching strategy**:

- **Customer has ONLY Fawry transactions** (no bus / online / wallet):
  sum the full balance — it's safe because no other module contributed.
- **Customer has multiple modules** on the same account:
  sum only the GL entries where `transactions.module='fawry'`, computed
  as `SUM(account_entries.credit) - SUM(account_entries.debit)`. This
  excludes the bus / online / wallet contributions from the fawry
  receivables total.

The branching uses the existing `withCount` aliases
(`fawry_transactions_count`, `bus_bookings_count`, etc.) which are
already populated by the existing query at line 403.

---

## 3. Files Changed (Phase C)

### `app/Services/Fawry/FawryTransactionService.php`

```php
protected function ensureCustomerAccount(int $customerId): Account
{
    $customer = Customer::findOrFail($customerId);

    if ($customer->account_id) {
        $account = Account::find($customer->account_id);
        if ($account) {
            // Phase C.1 fix: re-tag pre-existing customer accounts.
            if ($account->module_type !== 'fawry') {
                LedgerBalanceMutationGuard::run(function () use ($account) {
                    $account->module_type = 'fawry';
                    $account->save();
                });
            }
            return $account;
        }
    }

    // ... unchanged (create new account with module_type='fawry')
}
```

### `app/Services/Reports/FinancialReportService.php`

```php
$receivables = Customer::whereHas('ledgerAccount', function($q) use ($currency) {
    $q->where('currency', $currency)->where('balance', '>', 0);
})->where(function($q) {
    // ... unchanged (busBookings / fawryTransactions / etc.)
})->with('ledgerAccount')->get()->sum(function ($c) {
    // Phase C.2 fix: isolate Fawry share from other modules on the same
    // customer account.
    $hasOnlyFawry = $c->fawry_transactions_count > 0
        && $c->bus_bookings_count === 0
        && $c->online_transactions_count === 0
        && $c->wallet_transactions_count === 0;

    if ($hasOnlyFawry) {
        return (float) $c->ledgerAccount->balance;
    }

    $fawryDebt = DB::table('account_entries')
        ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
        ->where('account_entries.account_id', $c->ledgerAccount->id)
        ->where('transactions.module', TransactionModule::Fawry->value)
        ->selectRaw('SUM(account_entries.credit) - SUM(account_entries.debit) as debt')
        ->value('debt') ?? 0.0;

    return (float) $fawryDebt;
});
```

---

## 4. Test Contract (`scripts/phase_c_fawry_cleanup.php`)

13 checks across 4 sections, all rolled back via `DB::rollBack()`:

| # | Section | Checks |
|---|---------|--------|
| ① | `ensureCustomerAccount` re-tags pre-existing customer accounts | 4 |
| ② | `ensureCustomerAccount` re-tag is idempotent | 2 |
| ③ | `FinancialReportService` isolates Fawry share from other modules | 5 |
| ④ | Module-level invariants | 2 |

### Run

```bash
php scripts/phase_c_fawry_cleanup.php
# → Result file: storage/logs/phase_c_fawry_result.json
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

## 5. Fawry Module — Full Status After Phases A + B + C

| Metric | Before | After |
|--------|--------|-------|
| `updateTransaction()` repost | ❌ destructive | ✅ reverse+repost (Online Phase 9 pattern) |
| `customers_debt` source of truth | ❌ `fawry_transactions` columns | ✅ `account_entries` GL |
| `customerBalances` registered-client debt | ⚠️ fallback to columns | ✅ GL with explicit 0.0 |
| `FawryMachine.balance` protection | ❌ unguarded decrement/increment | ✅ 4-layer guard (AirlineAccount pattern) |
| `ensureCustomerAccount` re-tag | ❌ returns 'office' tagged | ✅ re-tags to 'fawry' |
| `FinancialReportService` fawry isolation | ❌ mixed modules | ✅ isolated via GL filter |
| **Tests** | 0 | **62 passing** (Phase A: 24, Phase B: 25, Phase C: 13) |

---

## 6. Risks & Rollback

| Risk | Mitigation |
|------|------------|
| Re-tagging a customer account overwrites an intentional non-Fawry tag | Only re-tags when `module_type !== 'fawry'`. If a customer is later used by another module (e.g., bus), that module's `ensureCustomerAccount` will re-tag again. This is the **correct** semantic — a customer account reflects the most recent module. |
| Phase C.2 isolation query is N+1-ish (one query per customer) | Uses the `withCount` aliases already populated by the existing query. The GL sum query is 1 extra query per customer in the `get()->sum(fn)` closure. Acceptable for office-report batch sizes; can be replaced with an aggregate join later if perf becomes an issue. |
| Walking through the query path causes customer to vanish from other reports | The `AccountModuleDivision::OFFICE` constant still lists `'fawry'`, so any report filtering by `office` division includes the customer. Only reports filtering by `module_type='bus'` etc. will exclude them — which is the correct behavior. |

---

## 7. Phase C Atomic Commit Plan

1. **`fix(fawry): re-tag pre-existing customer accounts to module_type=fawry`**
   - `FawryTransactionService::ensureCustomerAccount`
2. **`fix(reports): isolate Fawry share in FinancialReportService receivables`**
   - `FinancialReportService::calculateOfficeCapital` ($receivables sum closure)
3. **`chore(fawry): add Phase C test script + README`**
   - `scripts/phase_c_fawry_cleanup.php`
   - `docs/FAWRY_MODULE_PHASE_C.md`

---

*Phase C closes the final P1+P2 cleanup. The Fawry module is now fully
aligned with the Online / HajjUmra / Visa / Wallet / Bus protection
contract — the GL is the single source of truth, every model mutation
is paired with a ledger repost, and the boot guard prevents direct
balance changes.*