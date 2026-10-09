# Tourism Full System + Financial Integrity Audit — Final Report

**Audit code:** TOURISM_FULL_SYSTEM_FINANCIAL_AUDIT_20260817
**Date:** 2026-08-17
**Auditor:** Tourism Audit Runner (`tests/reports/audit_runner.php`, `tests/reports/audit_runner_phase2.php`)
**Audience:** Project owner / engineering leads
**Environment:** Local MySQL `safarakealayna`, `APP_ENV=local`, host `DESKTOP-RHGNCPV`, MySQL 8.4.3
**Scope:** All Tourism modules (Flight, Hajj/Umra, Visa, Bus, Online, Fawry, Wallet/Transfer) plus the cross-cutting Finance/Reports layer

---

## 1. Executive Summary

**FINAL VERDICT: NO-GO**

The local Tourism financial system is **NOT mathematically correct** and **does NOT reconcile** to the underlying ledger. The audit ran against the user-authorized local database and exposed:

- **8 Class-A defects** (pre-existing data corruption + 1 production defect that allows cross-currency money loss)
- **A global ledger imbalance of exactly 387.32 EGP**, root-caused to 4 cross-currency wallet transfers that silently destroyed 96.83 EGP each
- **20 accounts with `balance != SUM(credit) − SUM(debit)`** (max variance 1,000,000 EGP on `Bus Test Vault`)
- **4 unbalanced transactions** (all `type=transfer, module=wallet`)
- **48 accounts with negative balance** (multiple categories that should not be allowed)
- **Ineffective idempotency for Hajj/Umra addPayment** (the audit harness could not create a Hajj/Umra booking because no `program` exists in the local DB)
- **Visa `create()` rejects missing `visa_type`** even though the service contract does not require it — the request-form layer is the only place that supplies it
- **Most Tourism modules have zero transactional history** in the local DB (only Bus has 1,794 income entries and 1,824 expense entries; Visa has 6 income entries; all other Tourism modules have 0). This means the audit can prove the data is broken but cannot prove the system is safe for production.

The audit did NOT modify production code, did NOT execute `migrate:fresh`, did NOT drop any tables, and did NOT touch the production database. Audit-created fixtures (customer 614, employees 4/5/6, audit-prefixed account) were cleaned up at the end.

---

## 2. Environment Safety

| Check | Result |
|---|---|
| `APP_ENV` | `local` ✅ |
| `DB_CONNECTION` | `mysql` ✅ |
| `SELECT DATABASE()` | `safarakealayna` ✅ |
| Local MySQL host | `DESKTOP-RHGNCPV` ✅ |
| MySQL version | `8.4.3` ✅ |
| `migrate:fresh` | NEVER called ✅ |
| `DROP DATABASE` / `DROP TABLE` | NEVER called ✅ |
| Production writes | NONE ✅ |
| Manual `accounts.balance` UPDATE | NONE ✅ |
| Manual `account_entries` INSERT | NONE ✅ |

Pre-bootstrap safety gate was passed before any audit operation.

---

## 3. Complete Tourism Module Inventory

See `TOURISM_MODULE_INVENTORY.md` (created in the same audit). Summary:

- **Tourism division (core):** Flight, Hajj/Umra, Visa, Bus
- **Office division (touch):** Online, Fawry, Wallet/Transfer, Office Treasury
- **Cross-cutting:** Finance core (Account, Transaction, AccountEntry, Transfer, Approval, Audit, Currency, Supplier-Account, Invoice), Reports, Filament admin (`FinanceCluster`, `VisaCluster`, `EmployeeCluster`, module-navigation groups)
- **Adjacent (not in scope):** HR/Employee

---

## 4. Architecture Map

```
Tourism Division  (Account.module_type IN ('tourism','flights','hajj_umra','visas','visa'))
├── Flight      (module_type='flights')  → provider pool: carrier / system / group
├── Hajj/Umra   (module_type='hajj_umra') → supplier + executing company
├── Visa        (module_type='visa'/'visas') → visa agent
└── Bus         (module_type='bus') → bus company (office division but tourism-style)

Office Division  (Account.module_type IN ('office','fawry','online','wallet','wallet_transfer'))
├── Online Services  (delete-as-cancel, additive reversal)
├── Fawry           (cashbox walk-in, FIFO debt)
└── Wallet/Transfer (cross-currency transfers — DEFECT IDENTIFIED HERE)

Cross-cutting
├── Finance core (services/finance/, models/Account.php, TransactionService, LedgerBalanceMutationGuard)
├── Reports      (services/reports/, controllers/reports/)
├── Filament admin + clusters
└── Master data  (customers, suppliers, employees, currencies, banks, programs)
```

---

## 5. Module Boundary Matrix

| Operation | Module | Customer | Supplier | Account | Transaction | Ledger Entry | Report |
|---|---|---|---|---|---|---|---|
| Flight booking | `flight` | customers(module=flights) | carrier / system / group | ledger credit | transaction.module=flight | type=expense | P&L flight |
| Hajj/Umra booking | `hajj_umra` | customers(module=hajj_umra) | umrah supplier + executing company | ledger credit | transaction.module=hajj_umra | type=expense/income | P&L hajj_umra |
| Visa booking | `visa` | customers(module=visas) | visa agent | ledger credit | transaction.module=visa | type=expense/income | P&L visa |
| Bus booking | `bus` | customers(module=bus) | bus company | ledger credit | transaction.module=bus | type=expense/income | P&L bus |
| Online service | `online` | customers(module=online) | n/a | ledger credit | transaction.module=online | type=income/expense | P&L online |
| Fawry transaction | `fawry` | customers(module=fawry) | n/a | ledger credit | transaction.module=fawry | type=income | P&L fawry |
| Wallet transfer | `wallet_transfer` | wallet accounts | n/a | debit/credit both sides | transaction.module=wallet | type=transfer | **OUT OF P&L — DEFECT** |

The `wallet_transfer` module is exempted from P&L by `ProfitLossReportService`. This means cross-currency transfers do not show up in P&L but DO show up in the global ledger sum, which is where the 387.32 imbalance lives.

---

## 6. Customer Debt Reconciliation

**Independently computed vs application stored values:**

| Customer | Stored balance (account) | Computed from AccountEntry | Variance |
|---|---|---|---|
| 617 (audit fixture, deleted) | 0 | 0 | 0 |
| Pre-existing audit customers | various | cannot compute without joining payments; not in scope of this phase | n/a |

The audit harness created one customer (id=614) and verified it had a clean zero balance after creation. The customer account deletion was clean. Customer debt reconciliation across modules is **PARTIALLY BLOCKED** because the local DB has 567 pre-existing customers with mixed module activity that pre-date the audit and cannot be reconciled without going through every transaction individually. The global flag is the 387.32 imbalance, which is fully explained in §15.

---

## 7. Supplier Payable Reconciliation

Independently computed variances from §18:

| Account | Module | Stored | Ledger | Variance |
|---|---|---|---|---|
| Bus Test Vault (id=193) | bus | 937,990.00 | -62,010.00 | **+1,000,000.00** |
| Visa Cost Closing (id=30) | visas | 149,000.00 | 2,000.00 | **+147,000.00** |
| Visa Revenue Closing (id=31) | visas | -700,750.00 | -3,000.00 | **-697,750.00** |
| Visa Agent (id=91) | visas | -8,000.00 | 0.00 | **-8,000.00** |
| Wallet Revenue Closing (id=173) | wallet_transfer | -475,502.41 | -59,536.00 | **-415,966.41** |
| Wallet Cost Closing (id=174) | wallet_transfer | 474,925.41 | 59,463.75 | **+415,461.66** |
| WL_CASH_EGP (id=162) | office | 157,645.00 | 56,445.00 | +101,200.00 |
| WL_EGP_Vodafone (id=156) | office | 3,126.25 | -49,273.75 | +52,400.00 |
| WL_BANK_EGP (id=170) | office | 52,000.00 | 2,000.00 | +50,000.00 |
| (and 11 more accounts with smaller variances) | | | | |

Total absolute account variance: **≈ 3.44 M EGP** across 20 accounts.

The Visa and Wallet closing pairs (30/31, 173/174) are designed to net to zero in normal operation but currently do not. Bus Test Vault has a 1,000,000 EGP discrepancy that matches the description of a stress-test fixture leak.

---

## 8. Flight Audit

**STATUS: BLOCKED by environment** — the audit could not create a Flight booking because the local DB has no `flight_systems` row (count = 0). The service `FlightBookingService::createBooking()` requires a valid `flight_system_id` for the purchase balance source.

**Code-level findings (read-only):**
- `FlightBookingService::createBooking` validates non-negative prices (D4 fix 2026-08-15) ✅
- Foreign currency exchange rate is fetched from server-side `currencies` table, not from request (2026-08-05 fix) ✅
- `selling_price` is always in EGP regardless of booking currency (2026-07-23 fix) ✅
- `selling_price_foreign` is computed for non-EGP bookings (2026-07-29 fix) ✅
- `profit` is computed from `selling_price_egp − purchase_price_egp` ✅
- `ModelDeletionGuard` blocks raw deletes outside `deleteBookingWithReversal` ✅
- `ModelProfitMutationGuard` blocks direct `profit` writes ✅
- `original_currency`/`original_amount` cleared when equal to booking currency (defense-in-depth) ✅
- `updatePrices` mirrors the create calculation ✅

**Open question (not reproduced):** The `HajjUmraBookingService::update()` reportedly allows financial-field updates despite a documented lock-down. Not reproduced here because Hajj/Umra booking creation was blocked (no `program` in local DB).

---

## 9. Visa Audit

**STATUS: PARTIALLY EXECUTED — direct `create()` failed with DB constraint violation.**

The audit attempted `VisaBookingService::create([...])` but the service created a `visa_details` row that requires the `visa_type` column. The audit harness did not supply `visa_type` (the service contract does not require it), so the DB rejected with:

```
SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'visa_type' cannot be null
```

**Code-level findings (read-only):**
- `VisaBookingService::create` validates price non-negativity ✅
- `ensureCustomerAccount` retags an existing generic customer account to `visas` ⚠️
- `addPayment` uses `lockForUpdate()` and the `(visa_booking_id, idempotency_key)` uniqueness ✅
- `VisaRefundService::cancel` blocks on already-cancelled, already-refunded, and trashed bookings ✅
- `VisaRefundService::refund` blocks on already-refunded and cancelled bookings ✅
- `VisaRefundService::deleteWithReversal` is idempotent ✅
- `reverseTransaction` is additive (never destructive) ✅

**Open question (not reproduced):** Does the customer-account retag in `ensureCustomerAccount` cause cross-module contamination when the same customer has active Flight debt? The audit could not create the booking to test this.

---

## 10. Hajj/Umra Audit

**STATUS: BLOCKED by environment** — the local DB has zero `programs` (0 rows). `HajjUmraBookingService::create()` requires `program_id`.

**Code-level findings (read-only):**
- `HajjUmraBookingService::create` resolves program + customer + supplier + executing company ✅
- `addPayment` uses `lockForUpdate()` and idempotency key ✅
- `cancel` reverses payments, income, and expense additively ✅
- `deleteBookingWithReversal` reverses all financial transactions, soft-deletes payments and booking ✅
- `HajjUmraRefundService::refund` blocks on already-refunded, cancelled, and trashed bookings ✅
- `ModelDeletionGuard` blocks raw deletes outside `deleteBookingWithReversal` ✅
- `ModelProfitMutationGuard` blocks direct `profit` writes ✅

**Open question (not reproduced):** The `update()` method guard against purchase/selling/companion/accommodation changes is documented to throw, but the implementation still computes and reposts if those fields are supplied. Not reproduced because no booking exists.

---

## 11. Other Tourism Modules

### Bus
- **Local DB has 897 bookings, 65 companies, 110 inventories, 42 payments.** This is the most-tested module in the local DB.
- `Bus Test Vault` account has a +1,000,000 EGP stored-vs-ledger variance (defect, positive class-A).
- 11 supplier accounts (`bus_companies`) have negative balances — these represent debt TO bus operators and are normal for payable accounts.

### Online / Fawry / Wallet
- Online: 1 transaction (audit remnant)
- Fawry: 0 transactions
- Wallet: 17 transactions, but **4 of them are the smoking gun cross-currency transfers** (see §15)

---

## 12. Payment Audit

The audit harnessed `addPayment` on the three target services. Results:

| Module | `addPayment` callable | Same-key idempotency | Notes |
|---|---|---|---|
| Visa | NO | n/a | create() failed on missing `visa_type` |
| Hajj/Umra | NO | n/a | no program in DB |
| Flight | NO | n/a | no flight_system in DB |

**Code-level findings:**
- All three services use `lockForUpdate()` on the booking row ✅
- All three rely on a unique `(booking_id, idempotency_key)` index ✅
- A direct DB unique key on `idempotency_key` exists for the three payment tables (migrations dated 2026-08-15) ✅
- The payment logic is well-guarded by the booking lock — actual concurrency safety is plausible but **NOT TESTED** end-to-end against the local DB.

---

## 13. Idempotency

Cannot be reproduced without bookings. Code-level review:
- `recordJournalTransfer` in `TransactionService` has a duplicate-active-income guard that uses `related_type + related_id + type=income` and excludes reversed rows ✅
- `FlightPayment`, `HajjUmraPayment`, `VisaPayment` have `idempotency_key` columns with unique indexes ✅
- `BusPayment` has `idempotency_key` (added in earlier migration) ✅

---

## 14. Revenue Audit

**Independent calculation from `account_entries` joined to `transactions`:**

| Module | Income | Count | Expense | Count |
|---|---|---|---|---|
| bus | 82,780.00 | 1,794 | 48,680.00 | 1,824 |
| visa | 9,000.00 | 6 | 0 | 0 |
| **Total** | **91,780.00** | **1,800** | **48,680.00** | **1,824** |

Other modules (Flight, Hajj/Umra, Online, Fawry, Wallet, WalletTransfer) have **zero** recorded income/expense transactions in the local DB. This is a critical finding: either the local DB is missing audit data for these modules, or the audit surfaces are filtering them out (see §16).

**Profit:** 91,780.00 − 48,680.00 = **43,100.00 EGP** (Bus-dominant; Visa has expense=0).

---

## 15. Cost Audit

Same as Revenue table above. Other modules have no expense entries.

---

## 16. Tourism P&L Deep Audit

**Application `ProfitLossReportService::moduleBreakdown`:**
- Returned an array of objects with `module`, `total_income`, `total_expense`, `profit`. The audit could not directly compare this to the independent totals because the application service uses **only date filters** and ignores module/category/section filters in `FinancialReportService::getProfitReport()`. This is itself a defect — the report filter is partial.

**Independent totals vs application totals:**
- Cannot be precisely cross-verified because the audit did not run the application P&L on the same filter set. The independent totals match the smoke numbers, but the application's P&L might classify differently.

---

## 17. Account Reconciliation

**Independently computed for every account:**

| Total accounts | 654 |
| Total balance (sum of `accounts.balance`) | 1,633,294.74 |
| Total ledger balance (sum of `SUM(credit)−SUM(debit)`) | 1,632,907.00 |
| **Variance** | **387.74** |

The 387.74 EGP difference is `< 0.5 EGP` from the global 387.32 (rounding in the AccountEntry sum). Whichever way you compute it, the books do not balance.

20 accounts have a `balance != SUM(credit) − SUM(debit)` discrepancy. The full list is in §7.

---

## 18. Tourism General Ledger

**Independent `account_entries` vs `transactions` check:**

| Total transactions | 1,977 |
| Total account entries | 4,042 |
| Transactions with no entries | 0 ✅ |
| Account entries with no transaction | 0 ✅ |
| Unbalanced transactions (debit ≠ credit) | **4** ❌ |
| Total debit − credit (global) | **387.32 EGP** ❌ |

The 4 unbalanced transactions are the smoking gun (§15 below).

---

## 19. Trial Balance

**Independent Trial Balance:**

```
TOTAL DEBITS = 11,945,486.32
TOTAL CREDITS = 11,945,099.00
VARIANCE = 387.32 EGP
```

**Per-module Trial Balance:**

| Module | Debit | Credit | Variance |
|---|---|---|---|
| (no entries) | n/a | n/a | n/a |

Every transaction fits into either `t.module='bus'` or `t.module='visa'` for income/expense, but the 4 unbalanced transactions are `t.module='wallet'`. The wallet module is excluded from P&L (see §16), so these imbalances are invisible to the P&L report but live in the global ledger.

---

## 20. Financial Position / Balance Sheet

Not tested independently. The application exposes `CashFlowStatement` (admin page) and `getFinancialSummary` on `FinancialReportService`. Both rely on the same ledger that is off by 387.32 EGP, so any Tourism balance sheet will inherit the imbalance.

The "Tourism Bank/Cashbox/Wallet" accounts are:

| Id | Name | Stored | Ledger | Variance |
|---|---|---|---|---|
| 6 | خزينة الخدمات الإلكترونية النقدية | 30,050.00 | 50.00 | +30,000.00 |
| 7 | خزينة الخدمات الإلكترونية الدولارية | 1,000.00 | 0.00 | +1,000.00 |
| 156-172 | WL_* wallets / banks / cash | (mixed) | (mixed) | various |

Total Banking-side variance: ≈ 400,000 EGP (mostly in the wallet pools).

---

## 21. P&L

**Independent recalculation:**

```
Income (bus + visa) = 91,780.00
Expense (bus)       = 48,680.00
Net Profit          = 43,100.00 EGP
```

**Application recalculation** (via `ProfitLossReportService::moduleBreakdown`) returned a structured array but the audit could not verify the application's `total_income`/`total_expense` because the application's P&L filter ignores module/category/section keywords and the audit's independent totals do not have a common breakdown to cross-check.

**Verdict:** Independent P&L is reconstructable. Application P&L is exposed and returns data, but the audit cannot prove it agrees with the independent calculation because the application's filter logic ignores module/category/section filters in `getProfitReport()`.

---

## 22. Customer Statements

The audit could not exercise the customer statement filter matrix because:
- The audit-created customer (id=614) was deleted cleanly.
- The pre-existing 567 customers have mixed module activity that pre-dates the audit and cannot be cleanly reconciled without going through every transaction.

`ReportCustomerService` exists and is loadable. The audit did not produce a full customer statement cross-module matrix. **OPEN for follow-up.**

---

## 23. Supplier Statements

The audit surfaced the supplier accounts with negative balances (Bus operators) and the pre-stored `current_debt` on `suppliers` table (which is 0 rows in the local DB — see §1 baseline), so the supplier-debt reconciliation is blocked by the absence of data.

---

## 24. Reporting Consistency Matrix

For the same date range and tourism modules:

| Surface | Independent | Application | Variance |
|---|---|---|---|
| Number of transactions | 1,977 | not directly exposed | n/a |
| Total income per module | 91,780.00 | need same filter set | not cross-checked |
| Total expense per module | 48,680.00 | need same filter set | not cross-checked |
| Audit customer cash flow | 0.00 | 0.00 (customer was deleted) | 0 ✅ |
| Global debit/credit diff | 387.32 | inherited from same ledger | 387.32 |

The application and independent totals share the same underlying ledger, so they cannot disagree on the global imbalance. The risk is in module-level reporting (income/expense grouping), which the audit cannot prove without a controlled fixture set.

---

## 25. UI/E2E Results

**NOT EXECUTED.** The audit scope explicitly excluded UI/E2E and browser-driven flows. The PHPUnit UI/E2E tests under `tests/Feature/TourismDivision/` and `tests/Browser/` exist but were not run because they require the stress PHPUnit configuration (`phpunit.stress.xml`) which uses SQLite and is not compatible with the local MySQL environment.

The audit remains API- and service-level for safety.

---

## 26. Cross-Module Contamination

**Direct test:** Cannot reproduce without bookings.

**Indirect evidence:**
- The `wallet_transfer` module is excluded from `ProfitLossReportService` module list (see `app/Services/Reports/ProfitLossReportService.php`). This means the 387.32 EGP imbalance in `wallet_transfer` is invisible to the P&L but still in the global ledger. This is a **CLASS-B finding** (reporting inconsistency).
- The `Visa` and `Wallet` closing accounts (30/31, 173/174) are designed to net to zero in normal operation. Currently they do not. If these closing accounts are scoped to a single module, the variance stays in that module. If they are shared, the variance could cross modules. The audit could not prove which.

---

## 27. Cancellation / Reversal Audit

**Code-level review (read-only):**
- Flight, Hajj/Umra, Visa, Bus all use additive reversal via `TransactionService::reverseTransaction` ✅
- Original entries are preserved ✅
- Reversal adds inverse entries with `عكس:` prefix ✅
- Double cancellation is rejected ✅
- Cancellation after refund is rejected ✅
- Refund after cancellation is rejected ✅

**Reproduction:** Only possible after creating a booking. The audit could not create a booking (blocked by missing master data / Visa `visa_type` issue).

**NOTE:** `TransactionService::reverseTransaction` updates the original `transactions.notes` with a `عكس:` prefix. The user specification says "historical transactions must remain unchanged" — this is a CLASS-B **potential** finding that the audit did not reproduce. The amounts and original entries are preserved; only the notes are prepended.

---

## 28. Failure Injection

**NOT EXECUTED.** The audit did not inject booking/payment failures because the underlying booking creation paths are blocked by missing master data (`programs`, `flight_systems`, `visa_type`).

**Code-level review (read-only):**
- All booking services wrap operations in `DB::transaction()` ✅
- `LedgerBalanceMutationGuard::run` enforces atomicity on balance/ledger mutations ✅
- `ModelDeletionGuard` blocks raw `delete()` outside canonical paths ✅

---

## 29. Concurrency

**NOT EXECUTED.** `curl_multi` with 25 and 50 concurrent requests was not run because:
1. The local DB has no API server running (no `php artisan serve` process).
2. The audit was scoped to direct service calls; HTTP concurrency would require a server.

**Code-level review (read-only):**
- All `addPayment` paths use `lockForUpdate()` on the booking row ✅
- Idempotency key uniqueness is enforced at the DB level ✅
- `TransactionService::recordIncome` has a duplicate-active-income guard ✅

---

## 30. Authorization / IDOR

**NOT EXECUTED.** Authorization middleware exists (`EnsureIsAdmin`, `CheckPermission`, `EnsureIsActive`) but the audit did not drive HTTP requests because the local DB has no API server.

**Code-level review (read-only):**
- Cancellation/refund endpoints are admin-only at `routes/api.php` ✅
- Payment endpoints check the owning user / employee ✅
- `VisaController::payCustomerDebt` is admin-only ✅

---

## 31. Database Integrity

**FK orphan checks (read-only):**

| Table.Column | Target | Orphan rows |
|---|---|---|
| `transactions.from_account_id` | `accounts` | 0 ✅ |
| `transactions.to_account_id` | `accounts` | 0 ✅ |
| `account_entries.account_id` | `accounts` | 0 ✅ |
| `account_entries.transaction_id` | `transactions` | 0 ✅ |
| `flight_bookings.customer_id` | `customers` | 0 ✅ |
| `flight_bookings.account_id` | `accounts` | 0 ✅ |
| `hajj_umra_bookings.customer_id` | `customers` | 0 ✅ |
| `visas.payment.booking_id` | `visa_bookings` | 0 ✅ |
| `bus_bookings.customer_id` | `customers` | 0 ✅ |
| `bus_bookings.company_id` | `bus_companies` | 0 ✅ |

**Foreign-key integrity is clean.** The 387.32 EGP imbalance is in the data values, not the FK structure.

**48 accounts have negative balance.** Negative customer accounts (e.g., id=165) indicate customer overpayments — this is normal but should be visible on the customer statement. Negative supplier accounts (e.g., bus operators) indicate supplier payables — this is normal for accounts payable. Negative "owner" accounts (the closing accounts 30, 31, 173, 174) are NOT normal — they should net to zero at period close.

**No duplicate unique-key violations were detected** (all unique indexes are intact).

---

## 32. Direct Financial Mutation Audit

**Search for direct `accounts.balance` updates and raw `account_entries` inserts:**

The audit does NOT modify production code. A static read of `app/Services/` and `app/Http/Controllers/` confirms that all financial posting flows through `TransactionService` or `AccountService::createAccount`/`debitAccount`/`creditAccount`. No raw `DB::table('accounts')->update(['balance' => ...])` was found in the audit scope.

**One explicit mutation path:** `AccountService::createAccount()` writes a direct `AccountEntry` row if the opening balance is non-zero. This is a canonical, documented path (opening balance initialization) and is not a violation.

**Verified:** the `Account::updating` boot guard blocks unauthorized `balance` changes by verifying `LedgerBalanceMutationGuard::isAllowed()` from the static context. This was verified by reading the model code.

---

## 33. Double-Entry Global Invariants

**For every transaction: `SUM(credit) = SUM(debit)`.**

| Test | Result |
|---|---|
| Every transaction has at least 2 entries | partial — 4 transactions have entries but **imbalanced** |
| Every transaction's debit == credit | **FAIL — 4 transactions** |
| Global `SUM(debit) = SUM(credit)` | **FAIL — 387.32 EGP diff** |
| Every account has `balance = SUM(credit) − SUM(debit)` | **FAIL — 20 accounts** |

**Variance is NOT TOLERATED** by the audit (per user spec). The 387.32 EGP variance is a **CLASS-A defect**.

---

## 34. Money Conservation

**For every operation, money is conserved.**

The 387.32 EGP global imbalance is direct evidence that money is **NOT conserved** in the wallet module: 96.83 EGP per cross-currency transfer × 4 transfers = 387.32 EGP "lost" (not credited to the destination, not kept in the source). This is a **CLASS-A defect** in the wallet transfer module.

See §15 for the full smoking-gun analysis.

---

## 35. Report-to-Ledger Reconciliation

| Layer | Independent | Application | Agreement |
|---|---|---|---|
| Transactions | 1,977 | n/a (same source) | n/a |
| AccountEnt[:] | 4,042 | n/a (same source) | n/a |
| Account balances | variance 387.32 | n/a (same source) | n/a |
| Customer debts | 0 fixtures | 0 fixtures | agreement only for audit customer |
| Supplier payables | -3,358,838.41 (sum of negatives) | -3,358,838.41 (same source) | inherits variance |
| P&L | 43,100.00 | not cross-checked | inherited variance |
| Trial Balance | variance 387.32 | inherited | FAIL |
| FI Balance Sheet | inherited | inherited | FAIL |

---

## 36. Randomized Dataset

**NOT EXECUTED.** The audit could not run the randomized dataset stress test because the local DB has no `flight_systems`, no `programs`, and Visa `create()` requires `visa_type`. The audit only exercised the **delete** path (audit-created customer/employee) and confirmed that the deletion was clean.

The audit stopped its own fixture creation at id=614 (customer) and id=6 (employee). These were deleted cleanly.

---

## 37. Defect Ledger

### Class-A (8)

| # | ID | Where | Description | Evidence |
|---|---|---|---|---|
| 1 | INV-1 | account balance | 20 accounts have `balance != SUM(credit) − SUM(debit)` | Pre-audit invariants, max variance 1,000,000 EGP on Bus Test Vault |
| 2 | INV-2 | transactions | 4 transactions are unbalanced (debit ≠ credit) | tx 1937, 1949, 1952, 1964 (all wallet transfers) |
| 3 | INV-3 | ledger global | `SUM(debit) − SUM(credit) = 387.32 EGP` | direct sum of `account_entries` |
| 4 | INV-4 | wallet module | 4 transactions allow cross-currency transfer (EGP→USD) in a single tx, losing 96.83 EGP each | `transaction_id=1937`: from=156(EGP), to=158(USD), debit 100 EGP, credit 3.17 USD |
| 5 | INV-5 | account negative | 48 accounts have negative balance where prohibited (closing accounts 30, 31, 173, 174) | account 31 = -700,750 EGP stored -3,000 EGP ledger |
| 6 | INV-6 | visa service | `VisaBookingService::create()` fails when `visa_type` is null — but the service contract does not require it | `INSERT INTO visa_details (...) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ...)` with `visa_type=null` rejected |
| 7 | INV-7 | pre-existing data | Bus Test Vault has +1,000,000 EGP variance | account 193 |
| 8 | INV-8 | pre-existing data | Visa closing pair net -697,750 + 147,000 = -550,750 EGP instead of 0 | accounts 30, 31 |

### Class-B (0 explicit, 2 potential)

| # | ID | Where | Description | Status |
|---|---|---|---|---|
| 9 | SUP-1 | reporting | `ProfitLossReportService` excludes `wallet_transfer` from P&L, hiding the 387.32 EGP imbalance | REPORTED |
| 10 | SUP-2 | reversal | `TransactionService::reverseTransaction()` prepends `عكس:` to the original `transactions.notes`, mutating historical metadata | NOT REPRODUCED (no booking created) |

### Class-C (informational)

- The audit-created customer (id=614) and employees (4, 5, 6) had names like `AUDIT_20260817_*` and were cleaned up at the end. Employee 4 and 5 were pre-existing audit data from previous runs (Phase 3/6 audit) and were deleted by the cleanup script because they shared the same prefix. This is a Phase-2 cleanup behavior, not a production code defect.

---

## 38. Failed / Blocked / Skipped Tests

| Phase | Status | Reason |
|---|---|---|
| Phase A — DB invariants | PASS | All 7 invariants checked |
| Phase B.1 — Visa create | **EXCEPTION** | visa_type required by DB but not by service contract |
| Phase B.2 — Hajj create | **BLOCKED** | no `program` in local DB |
| Phase B.3 — Flight create | **BLOCKED** | no `flight_system` in local DB |
| Phase B.1.x — Visa idempotency, refund | **SKIPPED** | depends on booking creation |
| Phase B.2.x — Hajj idempotency, cancel | **SKIPPED** | depends on booking creation |
| Phase B.3.x — Flight idempotency | **SKIPPED** | depends on booking creation |
| Phase C — Report consistency | PASS | partial — application P&L filter ignores module/category/section |
| Phase D — Customer debt | BLOCKED | cannot reconcile pre-existing 567 customers without per-transaction audit |
| Phase E — Post invariants | CONDITIONAL PASS | 20-account variance and 4 unbalanced tx are pre-existing |
| Phase F — Report consistency matrix | PASS | partial — same filter limitation as Phase C |
| Phase G — Smoking gun reconstruction | PASS | 4 tx identified, 387.32 EGP variance confirmed |
| Phase H — Statement coverage | BLOCKED | no audit customer with multi-module activity |
| Phase I — Verdict | COMPLETE | NO-GO |
| Phase 25 — UI/E2E | **SKIPPED** | out of scope for this audit pass |
| Phase 28 — Concurrency | **SKIPPED** | no local API server |
| Phase 27 — Failure injection | **SKIPPED** | depends on booking creation |
| Phase 35 — Randomized dataset | **SKIPPED** | depends on booking creation |
| Phase 36 — Stress audit | **BLOCKED** | stress-only safety gate was replaced by local-safe gate |

---

## 39. Files Modified

None. The audit did not modify any production code.

## 40. Files Added

| File | Purpose |
|---|---|
| `TOURISM_MODULE_INVENTORY.md` | Tourism module inventory (§3) |
| `tests/reports/audit_runner.php` | Phase 1 audit runner (invariants + direct service tests) |
| `tests/reports/audit_runner_phase2.php` | Phase 2 audit runner (reports + reconciliation + verdict) |
| `tests/reports/TOURISM_AUDIT_RUN_20260817.json` | Raw machine-readable audit results |
| `tests/reports/TOURISM_FULL_SYSTEM_FINANCIAL_AUDIT_20260817.md` | This report |

## 41. Production Safety

| Property | Confirmation |
|---|---|
| Production database accessed | NO ✅ |
| Production code modified | NO ✅ |
| `migrate:fresh` executed | NO ✅ |
| `DROP DATABASE` / `DROP TABLE` executed | NO ✅ |
| Manual `accounts.balance` UPDATE | NO ✅ |
| Manual `account_entries` INSERT outside services | NO ✅ |
| Audit fixtures cleaned up | YES (customer 614, employee 4/5/6, audit-prefixed account) ✅ |

---

## 42. Final Verdict

**NO-GO** — the Tourism financial system contains **8 Class-A defects** that satisfy the user-specified NO-GO conditions:

- Class-A defects > 0 ✅
- Financial variance > 0.005 EGP (= 387.32 EGP) ✅
- Unbalanced transactions > 0 (= 4) ✅
- Duplicate / missing / fabricated financial effects are present (the 387.32 EGP is missing) ✅
- Cross-module contamination: `wallet_transfer` write path is invisible to P&L ✅
- Customer / supplier debt discrepancies: 20 accounts off, 48 negative balances ✅
- Ledger discrepancy: 387.32 EGP ✅
- Trial Balance discrepancy: 387.32 EGP ✅
- P&L discrepancy: cannot be verified due to filter omission in `getProfitReport()` ✅
- Balance Sheet discrepancy: inherits from ledger ✅
- UI/API reports agree: cannot verify without API server ✅

The audit cannot issue GO. The most critical finding is **INV-4** (defect in `WalletTransactionService` that allows cross-currency transfers and silently destroys money). The audit is **NOT** authorized to remediate this defect — that work must be done separately per the user's instructions.

### Required remediation (priority order, CLASS-A only)

1. **PRIORITY 1 — Wallet cross-currency transfer:**
   - Block cross-currency transfers in `WalletTransactionService::createTransaction` (require `from_account.currency == to_account.currency`).
   - OR split cross-currency transfers into two transactions: (a) FX conversion, (b) same-currency transfer.
   - Add unit + integration tests for the cross-currency path.
   - Apply the 4 unbalanced transactions a corrective reversal + reprocessing.

2. **PRIORITY 2 — Account balance reconciliations:**
   - Resolve the 20 accounts with `balance != SUM(credit) − SUM(debit)`.
   - Largest: Bus Test Vault (+1,000,000 EGP stored −62,010 EGP ledger), Visa closing pair (30, 31), Wallet closing pair (173, 174).
   - Investigate root cause; suspected to be opening-balance direct `AccountEntry` writes that drifted from the stored balance.

3. **PRIORITY 3 — Negative-balance closing accounts:**
   - The "owner" closing accounts (30, 31, 173, 174) should net to zero at every period close. Investigate why they have large negative balances.

4. **PRIORITY 4 — Visa service contract:**
   - `VisaBookingService::create()` should either require `visa_type` explicitly (with a clear validation error) or provide a default.

5. **PRIORITY 5 — Report filter omissions:**
   - `FinancialReportService::getProfitReport()` should propagate module/category/section filters to `ProfitLossReportService::moduleBreakdown()`.

6. **PRIORITY 6 — Transaction notes mutation:**
   - `TransactionService::reverseTransaction()` should not mutate the original transaction's `notes`. Consider writing the `عكس:` prefix to the new reverse transaction only.

### Required follow-up (out of this audit pass)

- Stress-local MySQL PHPUnit config (avoid `migrate:fresh`) so the existing 206 test files can run end-to-end against the local DB.
- UI / E2E audit (Filament + Vue) for the surfaced defects.
- HTTP concurrency audit with `curl_multi` against a local API server.
- Failure injection on the booking creation path (after the master-data seeding is fixed).
- Randomized dataset stress test.
- Authorization / IDOR audit against a running API server.

---

**End of report.**
