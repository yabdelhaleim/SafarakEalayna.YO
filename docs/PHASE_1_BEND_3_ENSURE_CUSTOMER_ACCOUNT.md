# Phase 1 — Bend 3: `ensureCustomerAccount` re-tag pattern

**Module scope:** 5 customer-facing booking / transaction services
(+ `BusCompanyService` for the supplier side).

**Status:** ✅ Complete — Real DB Validation 17/17 PASS.

**Commits (chronological, atomic):**

| # | Hash (short) | Subject |
|---|---|---|
| 2.1 | `fa82e00` | `OnlineTransactionService` re-tag customer account to `'online'` |
| 2.2 | `63469cb` | `BusBookingService` + `BusCompanyService` re-tag to `'bus'` |
| 2.3 | `cd03ef1` | `HajjUmraBookingService` re-tag customer account to `'hajj_umra'` |
| 2.4 | `b5cc59d` | `VisaBookingService` re-tag customer account to `'visas'` |
| 2.5 | `a748137` | `FlightBookingService` re-tag customer account to `'flights'` (bonus scope) |

---

## Problem

`app/Observers/CustomerLedgerObserver.php:33` unconditionally creates an
`Account` row with `module_type = 'office'` the moment a `Customer` row
is inserted (`Customer::created` event, registered in
`App\Providers\AppServiceProvider:77`).

The 5 customer-facing module services (Online, Bus [customer], HajjUmra,
Visa, Flight) — plus `BusCompanyService` for the BusCompany supplier
side — had the same gap:

```php
if ($customer->account_id) {
    $account = Account::find($customer->account_id);
    if ($account) {
        return $account;     // ← returned the 'office'-tagged account as-is
    }
}
```

Since `CustomerLedgerObserver` always pre-creates the account, the
"create if missing" branch in each service is dead code in practice —
every call hits the "existing account" branch and silently leaves the
wrong `module_type` in place.

### Concrete downstream impact (pre-fix)

- `app/Services/Finance/TreasuryService.php:521` queries `where('module_type', 'hajj_umra')` for HajjUmra receivables — `'office'`-tagged customer accounts were invisible.
- `app/Services/Finance/TreasuryService.php:529` queries `where('module_type', 'visas')` for Visa receivables — same.
- `app/Services/Finance/TreasuryService.php:539` lists `'bus'`, `'flights'`, `'hajj_umra'` in strict filter set — all affected.
- `app/Services/Online/README.md:152-166` confirms Online scopes dashboards strictly by `module_type='online'`.
- `app/Services/Reports/FinanceOperationsReportService.php:193-194` reads `account.module_type` to bucket revenue — flights receivables were misclassified.

## Reference Implementations (already correct)

- `WalletTransactionService::ensureCustomerAccount` — Phase 8 fix, re-tags to `'wallet_transfer'`.
- `FawryTransactionService::ensureCustomerAccount` — Phase C fix, re-tags to `'fawry'`.

Both follow the same 6-line pattern. Lifted that pattern verbatim for the
5 target sites (and BusCompany).

## The Pattern (applied to all 6 sites)

Insert before `return $account;` on the existing-account branch:

```php
// Phase 1.Bend3 fix: CustomerLedgerObserver creates a generic
// 'office'-tagged account the moment a Customer row is inserted.
// When that customer is later used in an <MODULE> flow we re-tag
// the account to '<MODULE_KEY>' so it surfaces in the strict
// module_type='<MODULE_KEY>' queries (e.g. TreasuryService line
// 521 / 529 / 539). Wrapped in LedgerBalanceMutationGuard because
// touching `balance` — even to confirm 0.00 — would otherwise trip
// the Account::updating boot guard.
if ($account->module_type !== '<MODULE_KEY>') {
    LedgerBalanceMutationGuard::run(function () use ($account) {
        $account->module_type = '<MODULE_KEY>';
        $account->save();
    });
}
```

### Why `LedgerBalanceMutationGuard::run()`

The `Account` model has a boot guard at `app/Models/Account.php:50-70`
that throws when `balance` is touched outside sanctioned paths.
Calling `->save()` on an `Account` model can re-flag `balance` as
dirty due to the `decimal:2` cast re-normalising the existing value
(even if no actual change). The wrapper at
`app/Support/Finance/LedgerBalanceMutationGuard.php:17-25` is a
depth-counter that flips `isAllowed()` to `true` for the duration of
the closure.

### Idempotency

The `if ($account->module_type !== '<MODULE_KEY>')` guard makes
repeated calls a no-op — a customer who already has the right tag
incurs zero writes. This is essential because every transaction in
each module calls `ensureCustomerAccount` on its customer.

## Sites Touched

| # | Service | Method | Target `module_type` | Notes |
|---|---------|--------|---------------------|-------|
| 1 | `OnlineTransactionService` | `ensureCustomerAccount` (protected) | `'online'` | Online-only dashboard scoping |
| 2 | `BusBookingService` | `ensureCustomerAccount` (protected) | `'bus'` | TreasuryService line 718 |
| 2b | `BusCompanyService` | `ensureCompanyAccount` (public) | `'bus'` | Supplier side; lower priority (no observer) but consistent |
| 3 | `HajjUmraBookingService` | `ensureCustomerAccount` (protected) | `'hajj_umra'` | TreasuryService line 521 |
| 4 | `VisaBookingService` | `ensureCustomerAccount` (public) | `'visas'` | TreasuryService line 529 |
| 5 | `FlightBookingService` | `ensureCustomerAccount` (protected) | `'flights'` | TreasuryService line 539 + FinanceOperationsReportService line 193-194 |

## Real DB Validation

`scripts_temp_validate_ensure_customer_account.php` runs against the
local MySQL DB and verifies 17 invariants:

For each of the 5 customer-facing modules:
- ✅ Fresh `Customer::save()` triggers `CustomerLedgerObserver` → pre-creates account with `module_type='office'`.
- ✅ First call to `ensureCustomerAccount` re-tags to the module-specific key.
- ✅ Second call is idempotent (no change, no error).

For `BusCompanyService`:
- ✅ First call hits the **create** branch (no observer pre-creates the account) and creates with `module_type='bus'`.
- ✅ Second call is idempotent.

Each scenario wrapped in `DB::beginTransaction()` / `DB::rollBack()`
so no data persists.

**Result: 17/17 PASS.**

Run:

```bash
php scripts_temp_validate_ensure_customer_account.php
```

## Files Touched

- **New**:
  - `scripts_temp_validate_ensure_customer_account.php` (validation script)
  - `docs/PHASE_1_BEND_3_ENSURE_CUSTOMER_ACCOUNT.md` (this file)

- **Modified** (one re-tag block added per file):
  - `app/Services/Online/OnlineTransactionService.php`
  - `app/Services/Bus/BusBookingService.php`
  - `app/Services/Bus/BusCompanyService.php`
  - `app/Services/HajjUmra/HajjUmraBookingService.php`
  - `app/Services/Visa/VisaBookingService.php`
  - `app/Services/Flight/FlightBookingService.php`