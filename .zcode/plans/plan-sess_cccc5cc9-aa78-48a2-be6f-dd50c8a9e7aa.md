# HAJJ & UMRAH MODULE — INCREMENTAL EXECUTION PLAN

## Defect Policy (Strict — From User)

**Mode**: Option 1 — Report + fix critical bugs only (financial / data / security).
**Decision Rule**: Investigate every failure → classify (app defect / test defect / env / expected / pre-existing) → fix only critical bugs → never weaken tests.

**Chain of evidence required** at every phase:
- Tests / Assertions / PASS / FAIL / WARN / SKIP counts
- Defects found (classified)
- Fixes applied (with regression test)
- Final stable baseline before proceeding to next phase

---

## Phase Status (Pre-execution Baseline)

| Phase | Deliverable | Status |
|---|---|---|
| 0 | `docs/HAJJ_UMRAH_COVERAGE_MATRIX.md` | ✅ DONE |
| 1 | `docs/HAJJ_UMRAH_MODULE_INVENTORY.md` | ✅ DONE |
| 2 | `tests/Feature/HajjUmra/HajjUmraDatabaseIntegrityTest.php` | ✅ DONE (not yet executed) |
| 2.5 | `tests/Feature/HajjUmra/HajjUmraTestCase.php` | ⏳ NEW |
| 3–16 | 14 test files | ⏳ PENDING |
| 17 | `scripts/audit_hajj_umra_full.php` | ⏳ PENDING |
| 18 | `HAJJ_UMRAH_FULL_AUDIT_20260814.md` | ⏳ PENDING |

---

## Step 1 — Phase 2 Execution (Run Database Integrity Tests)

**Command**:
```
vendor/bin/phpunit --filter HajjUmraDatabaseIntegrityTest
```

**Investigation protocol** (for every failure):
1. Reproduce locally.
2. Inspect actual DB state via SQLite PRAGMA / raw queries.
3. Inspect schema in `database/migrations/*hajj_umra*` and `*umrah*`.
4. Classify failure (5 categories from defect policy).
5. Document in `docs/HAJJ_UMRAH_PHASE2_DEFECTS.md`.

**Fix protocol** for genuine critical defects:
- Add regression test FIRST (failing).
- Make smallest safe production-code change.
- Re-run failed test → verify pass.
- Re-run full Phase 2 suite.
- Re-run all existing Hajj/Umra tests (`vendor/bin/phpunit --filter "Tests\\Feature\\HajjUmra"`).
- Verify no regression in adjacent modules (`tests/Feature/TourismDivision`).

**Output**: `docs/HAJJ_UMRAH_PHASE2_RESULTS.md` containing the baseline table:
```
Phase 2: Tests: N | Assertions: N | PASS: N | FAIL: N | WARN: N | SKIP: N | Defects: N | Fixes: N
```

**Gate**: Do NOT proceed to Phase 2.5 unless Phase 2 is 100% green OR all remaining failures are documented as non-critical.

---

## Step 2 — Phase 2.5: HajjUmraTestCase Foundation (NEW)

Create `tests/Feature/HajjUmra/HajjUmraTestCase.php` modeled on `VisaTestCase` + `OnlineTestCase`.

**Reusable setup/helpers** (from Agent 3 mapping):
- `setUp()` — admin User, Sanctum auth, optional Employee row
- `createTreasuryAccount(string $name, string $currency, float $balance, string $moduleType='tourism', string $module='hajj_umra', bool $isModuleVault=true): Account`
- `createProgram(string $programType='umrah', float $selling=20000, float $purchase=15000): Program`
- `createCustomer(string $name, ?string $phone=null, ?string $nationalId=null): Customer`
- `createUmrahSupplier(string $name, string $currency='EGP'): UmrahSupplier`
- `createHajjUmraExecutingCompany(string $name, string $currency='SAR'): HajjUmraExecutingCompany`
- `bookingPayload(array $overrides=[]): array`
- `createBooking(array $overrides=[]): array` (POSTs and returns `['response','booking']`)

**Reusable delta-based assertions**:
- `assertAccountBalanceDelta(Account $a, float $expectedDelta): void` — uses delta from cached `balance` − ledger sum baseline, no opening-entry required
- `assertLedgerBalancedForAccount(int $accountId): void` — `SUM(credit) − SUM(debit)` matches cached delta
- `assertLedgerGloballyBalanced(): void` — sweeps all accounts
- `assertTransactionBalanced(int $transactionId): void` — Σdebit = Σcredit per transaction
- `assertBookingBalanced(int $bookingId): void` — filtered via `module='hajj_umra'`
- `assertCustomerNetDue(int $customerId, float $expected): void`
- `assertSupplierNetDue(int $accountId, float $expected): void`

**Isolation guarantees** (from `RefreshDatabase`):
- Every test gets a fresh in-memory SQLite.
- No financial record, customer, program, or treasury leaks between tests.

**Validation**:
1. Run full existing Hajj/Umra suite → verify identical results to pre-Phase-2.5 baseline (no behavioral change to existing tests).
2. Run a small smoke test using the new base (`HajjUmraTestCaseSmokeTest.php`) → verify auth, treasury creation, customer/program creation, booking creation, delta assertion all work.
3. Document baseline in `docs/HAJJ_UMRAH_PHASE2_5_RESULTS.md`.

**Important**: The 10 existing test files are NOT modified, refactored, or touched in any way. They continue to use their own setup. The new base is for new audit tests only.

---

## Step 3 — Phase 3: Master Data Lifecycle

**New test file**: `tests/Feature/HajjUmra/HajjUmraMasterDataTest.php` (~25 tests, uses `HajjUmraTestCase`).

Coverage:
- Customer (create / read / update / soft delete / restore / search / pagination / validation / duplicate handling)
- Program (same matrix + auto-executing-company observer behavior)
- HajjUmraExecutingCompany (same matrix + auto-account creation)
- UmrahSupplier (same matrix + auto-account creation)
- Hotel, TripSupervisor, AccommodationType, VisaAgent, VisaDuration reference data

**Run**, investigate failures per defect policy, fix critical bugs, document in `docs/HAJJ_UMRAH_PHASE3_RESULTS.md`.

**Gate**: 100% green (or all non-critical documented) → proceed to Phase 4.

---

## Step 4 — Phase 4: Booking Lifecycle

**New test file**: `HajjUmraBookingLifecycleTest.php` (~20 tests).

Coverage:
- Single customer create (no companion)
- Companion attached
- Multi-passenger (adult / child_with_bed / child_no_bed / infant)
- Update selling_price → reposts income (additive)
- Update purchase_price → reposts expense (additive)
- Update with companion_purchase_price + accommodation_extra
- Update passengers (delete + recreate)
- Cancel non-trashed → additive reversal
- Cancel cancelled → idempotency 422
- Refund paid booking → status=refunded
- Refund cancelled → BLOCKED (422)
- Refund refunded → BLOCKED (422)
- Soft delete via `deleteBookingWithReversal` → additive reversal
- Restore → balances restored
- Status transitions: pending → confirmed → in_progress → completed

**Run, investigate, fix, document** in `docs/HAJJ_UMRAH_PHASE4_RESULTS.md`.

**Gate**: green → Phase 5.

---

## Step 5 — Phase 5: Customer Payments

**New test file**: `HajjUmraPaymentComprehensiveTest.php` (~25 tests).

Coverage:
- Full payment (20,000 → 20,000, due=0)
- Partial (5,000 + 7,000 + 8,000 = 20,000)
- 10 partial payments of 1,000 each on 10,000 booking
- Overpayment → credit balance (existing rule)
- Zero / negative / missing amount → 422
- Invalid account → 422
- Invalid currency mismatch (booking EGP, account USD) → 422
- Non-existent booking → 404
- Cancelled booking → 422
- Refunded booking → 422
- Fully paid booking → allowed (creates overpayment credit)
- Unauthorized (no auth) → 401
- Cross-module vault (`module_type='office'`) rejected → 422
- Initial payment in create flow
- Payment to different account (bank vs cashbox) with currency match

**Run, investigate, fix, document**.

**Gate**: green → Phase 6.

---

## Step 6 — Phase 6: Debt System

**New test file**: `HajjUmraDebtLifecycleTest.php` (~15 tests).

Coverage:
- Create debt (30,000 booking, 10,000 paid → debt=20,000)
- Partial settlements
- Multiple settlements
- Over-settlement (21,000 on 20,000 debt → credit)
- Settlement after cancellation → 422
- Delete settlement via booking delete → accounting reverses
- `customerBalances` aggregates correctly
- `customerStatement` running balance accurate

**Run, investigate, fix, document**.

**Gate**: green → Phase 7.

---

## Step 7 — Phase 7: Supplier / Agent Payables

**New test file**: `HajjUmraPayablesTest.php` (~15 tests).

Coverage:
- Executing company withdraw / repay
- `dues` endpoint aggregates total_withdrawn / repaid / net_due
- Auto-creates account on company creation
- Repay blocked when source balance insufficient (GAP #HJ-6)
- Multi-currency (EGP / USD / SAR)
- Cancel / supplier booking → AP reversal
- Refund → AP reversal
- Soft delete booking → AP zero
- Restore booking → AP back

**Run, investigate, fix, document**.

**Gate**: green → Phase 8.

---

## Step 8 — Phase 8 & 9: Financial Transactions + GL / Accounting

**New test file**: `HajjUmraAccountingTraceTest.php` (~20 tests).

For every operation:
- Trace source → transaction(s) → AccountEntry (debit/credit) → account.balance delta
- Verify no duplicate, no missing, no orphan, no ghost balance
- Per transaction: Σ debit = Σ credit
- Per account: balance = Σ credit − Σ debit (delta-based)
- After full lifecycle: all accounts still balance
- Multi-currency entries segregated (no USD mixing with EGP on same account)
- Reversals marked with `عكس:` prefix in notes
- Original transactions preserved (additive reversal)

**Run, investigate, fix, document**.

**Gate**: green → Phase 10.

---

## Step 9 — Phase 10: Soft Delete + Restore (Comprehensive)

**New test file**: `HajjUmraSoftDeleteComprehensiveTest.php` (~15 tests).

Coverage:
- Booking soft delete + full accounting reversal (all accounts restored to pre-booking state)
- Payment soft delete (cascading from booking delete)
- Program soft delete blocked if bookings exist (admin)
- ExecutingCompany soft delete
- UmrahSupplier soft delete
- Restore booking → balances restored
- Restore payment (if model supports)
- `withTrashed()` query in Filament works
- `customerBalances` excludes trashed
- Trashed booking does NOT appear in `index` by default
- Trashed booking CAN be retrieved via `withTrashed`

**Run, investigate, fix, document**.

**Gate**: green → Phase 11.

---

## Step 10 — Phase 11: API Audit

**New test file**: `HajjUmraApiContractTest.php` (~30 tests, all 27 endpoints).

For each endpoint:
- Valid request → 200 / 201
- Missing required field → 422 with validation errors
- Invalid ID → 404 (or 422 for missing record)
- Unauthorized → 401
- Forbidden (non-admin on destructive) → 403
- Duplicate request → idempotency behavior
- Boundary values (very large / very small amounts)
- Response shape matches `ApiResponse` envelope
- Admin middleware on destructive routes enforced

**Run, investigate, fix, document**.

**Gate**: green → Phase 12.

---

## Step 11 — Phase 12: Pinia Store / Frontend Runtime

**New test file**: `HajjUmraPiniaStoreTest.php` (~25 tests, uses Node subprocess + mocked axios).

> **Browser E2E NOT available** — no Vitest / Cypress / Playwright configured. Documented limitation.

Coverage:
- `fetchBookings` populates state.bookings
- `fetchBookingById` populates currentBooking
- `createBooking` / `updateBooking` / `cancelBooking` / `deleteBooking`
- `addPayment` updates paid_amount / remaining_amount
- `fetchSettings` populates all dropdowns
- `fetchExecutingCompaniesDues` populates finance list
- `recordExecutingCompanyWithdraw` / `recordExecutingCompanyRepay`
- `fetchSuppliers` / `createSupplier`
- Error states: 400, 401, 403, 404, 422, 500, network failure
- Loading states reset properly
- Pagination metadata handled
- Search / filter params passed correctly

**Run, investigate, fix, document**.

**Gate**: green → Phase 13.

---

## Step 12 — Phase 13: Negative / Abuse Testing

**New test file**: `HajjUmraNegativeTest.php` (~25 tests).

Coverage: negative prices, negative payments, zero payments, invalid customer / booking / supplier / account / currency / status, duplicate submissions, double payment, payment after cancellation, delete paid booking (admin), delete customer with bookings (FK blocked), delete supplier with payables (FK blocked), unauthorized access, missing permissions, invalid IDs (string, negative, zero), manipulated payload (extra fields, wrong types).

**Run, investigate, fix, document**.

**Gate**: green → Phase 14.

---

## Step 13 — Phase 14: Concurrency

**New test file**: `HajjUmraConcurrencyTest.php` (~10 tests).

Coverage:
- Parallel payment of 10,000 each on outstanding 10,000 → only one succeeds
- Parallel cancel on same booking → only one succeeds
- Parallel delete on same booking → only one succeeds
- Duplicate booking submission (rapid-fire) → exactly one created
- Parallel refund → only one succeeds
- Verify `DB::beginTransaction` + `lockForUpdate` guards exist

**Run, investigate, fix, document**.

**Gate**: green → Phase 15.

---

## Step 14 — Phase 15: Full Business Scenario

**New test file**: `HajjUmraFullBusinessScenarioTest.php` (~10 tests, single comprehensive E2E).

1. Create customer (Hassan)
2. Create Hajj program (5-star, 14 nights, Makkah 7 + Madina 7)
3. Add executing company (with auto-account)
4. Add umrah supplier
5. Create booking 25,000 EGP (with companion + 4 passengers)
6. Initial payment 5,000
7. Partial 7,000
8. Final 13,000
9. Verify paid=25,000, due=0, status=confirmed
10. Modify booking: add 2,000 extra charge (reposts income)
11. Cancel one passenger (reposts)
12. Withdraw 10,000 from executing company
13. Repay 6,000 to executing company
14. Refund 2,000 partial adjustment
15. Soft delete one transaction
16. Restore transaction
17. Final reconciliation: every account balances, Σ debit = Σ credit per tx, customer balance = 0, executing company net due = 4,000, GL total balanced

**Run, investigate, fix, document**.

**Gate**: green → Phase 16.

---

## Step 15 — Phase 16: Final Data Integrity Post-Audit

**New test file**: `HajjUmraPostAuditIntegrityTest.php` (~15 tests).

After all above scenarios:
- No orphan hajj_umra_payments
- No orphan umrah_transaction_passengers
- No duplicate transaction_id entries
- No negative balances on cashboxes / wallets / banks
- No ghost balances on customer accounts
- All hajj_umra transactions have ≥ 2 account_entries
- All transactions have a module tag
- All soft-deleted records have `deleted_at` populated
- Restore works without duplicate entries
- Cross-currency entries don't mix

**Run, investigate, fix, document**.

**Gate**: green → Phase 17.

---

## Step 16 — Phase 17: Full Regression

Run sequentially:
1. `vendor/bin/phpunit --filter "Tests\\Feature\\HajjUmra"` — all HajjUmra tests (existing 113 + new ~250 = ~363 tests)
2. `vendor/bin/phpunit tests/Feature/TourismDivision` — verify no cross-module regression
3. Classify every failure (A: app defect, B: test defect, C: env, D: expected, E: unrelated pre-existing)
4. Document in `docs/HAJJ_UMRAH_PHASE17_REGRESSION.md`

---

## Step 17 — Phase 17.5: Standalone Audit Runner

### 17.5a — Inspect existing scripts (read-only)
- `scripts/hajj_umra_full_e2e.php` (78KB)
- `scratch/hajj_umra_full_e2e_audit.php` (24KB)
- `scripts/hajj_umra_local_setup.php` (7KB)

Document:
- What scenarios they cover
- Database setup / assertions / helpers / known defects / SQLite vs other DB
- Reusable patterns vs obsolete logic

Do NOT execute destructive portions blindly. Do NOT modify the existing files.

### 17.5b — Create `scripts/audit_hajj_umra_full.php`
Per current 18-phase spec:
- Isolated test execution
- Hajj/Umra test data seeding
- API / business-flow testing
- Financial / debt / payment / DB integrity / soft-delete / negative / reconciliation
- Clear PASS / FAIL / WARN / SKIP reporting

Reuse proven patterns from existing scripts where useful. Output JSON to `storage/app/hajj_umra_audit_report_20260814.json`.

### 17.5c — Coverage comparison
Produce table mapping existing-script scenarios → new audit coverage. Ensure no useful scenario silently lost.

---

## Step 18 — Phase 18: Final Audit Report

**Output**: `HAJJ_UMRAH_FULL_AUDIT_20260814.md`

Structure (matching `VISA_MODULE_FULL_AUDIT_20260814.md`):
1. Executive Summary — verdict, headline numbers, defect summary
2. Coverage Matrix — table mapping 18 phases × existing/new tests
3. Module Inventory — summarize from Phase 1
4. Backend Results — PHPUnit counts per phase
5. Frontend Results — Pinia store results + browser E2E limitation note
6. API Results — endpoint matrix
7. Database Results — integrity checks
8. Payment / Debt / Supplier Results
9. Financial / GL Reconciliation Results
10. Soft Delete Results
11. Concurrency Results
12. Defect Register — every defect with ID, severity, root cause, fix, regression test, verification
13. Final Reconciliation — explicit balance tables
14. Final Verdict — GO / CONDITIONAL GO / NO-GO

---

## Defect Documentation Standard

For every genuine defect, add to `docs/HAJJ_UMRAH_DEFECT_REGISTER.md`:

```text
DEFECT ID: HJ-NNN
Severity: CRITICAL / HIGH / MEDIUM / LOW
Status: OPEN / FIXED / DEFERRED

Scenario:
Expected:
Actual:
Root Cause:
Affected Files:
Financial / Data Impact:
Fix Applied:
Regression Test:
Verification:
```

---

## Permissions Required

- **Bash**: `php artisan test`, `php artisan migrate:fresh`, `vendor/bin/phpunit`, executing standalone scripts (`scripts/audit_hajj_umra_full.php`), file operations (read existing scripts, create new test files, run scripts)
- **Write**: new test files, new audit script, new docs (results, defects, coverage), isolated SQLite DB file
- **Edit**: application code fixes (only when critical defect confirmed — smallest safe change)
- **Read**: source inspection throughout (services, controllers, migrations, tests, scripts)

---

## Final Verdict Criteria

- **GO**: All 18 phases green; zero unresolved critical/high defects; financial invariants hold; full reconciliation passes
- **CONDITIONAL GO**: Some non-critical defects remain documented; financial integrity intact
- **NO-GO**: Critical/high defect remains unresolved; financial invariant violated; reconciliation fails

The final report must show: original defect → root cause → fix → regression test → final verification result. The goal is not a perfect-looking report; the goal is establishing actual production safety.