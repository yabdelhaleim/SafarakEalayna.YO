# Phase 3 (B-2) — Flight payment no longer creates duplicate income transaction

**Date:** 2026-08-19
**Branch:** `fix/flight-payment-no-double-income` (off `fix/flight-payment-ownership`)
**Commit:** `35ee24f`
**Status:** ✅ **COMPLETE — STOP for user review**

---

## 1. Defect recap

**B-2** (Tourism Full Audit 2026-08-18): every `FlightPayment::create()` was
followed by a `recordIncome()` call that produced a fresh `type=Income`
transaction tied to the booking. For a booking paid in N partial
installments, the trial balance therefore showed **N × `selling_price`**
of income instead of the correct **1 × `selling_price`**.

A WIP on top of the WIP tried to paper over this by *rekeying*
`related_type` from `FlightBooking` → `FlightPayment` (the "rekey trick")
to bypass the single-active-income guard at `TransactionService::650`.
That made the booking writable but did not fix the trial balance
over-counting — it just hid it from the guard.

The guard itself (`TransactionService:650-675`) was added on
2026-07-27 by FC-AUDIT-20260814 to enforce the rule "each booking has
exactly one ACTIVE sale income". See `docs/PHASE_1_6_SINGLE_ACTIVE_INCOME_ANALYSIS.md`
for the full analysis.

## 2. The fix

**Single-line behavioural change** at `FlightBookingService::addPayment`
(line 2079–2092):

```diff
- $transaction = $this->transactionService->recordIncome([
+ $transaction = $this->transactionService->recordJournalTransfer([
      'amount' => $transferAmount,
      'converted_amount' => $convertedAmount,
      'exchange_rate' => $booking->exchange_rate ?? null,
+     'from_account_id' => $customerAccount->id,
      'to_account_id' => $accountId,
      'module' => TransactionModule::Flight->value,
+     'type' => \App\Enums\TransactionType::Transfer->value,
      'related_type' => FlightPayment::class,
      'related_id' => $payment->id,
      'notes' => $paymentNotes,
  ]);
```

**Semantics** — a payment against an existing booking is a NEUTRAL
transfer (customer AR → cashbox). The booking's sale income was already
recorded at `recordSaleToCustomer` during `createBooking`
(`FlightBookingService:3169-3205`); each payment is just cash collection
against existing debt, not a new sale.

This mirrors the FC-AUDIT-20260814 fix already applied to the
**HajjUmra** module (`HajjUmraBookingService.php:635-645`). The
single-active-income guard at `TransactionService:650` guards only
`type=Income`, so the type change makes the B-2 fix natural — **no
rekey trick required**.

## 3. The comment block

Updated the rationale block at `FlightBookingService.php:2009-2034` to
document the new design and explicitly call out that:

- The previous "rekey trick" explanation is **obsolete**.
- The new design relies on the `TransactionType::Transfer` value being
  *outside* the guard's filter (`$typeValue === TransactionType::Income->value`).
- Step ordering is unchanged — `FlightPayment::create()` first (with
  `transaction_id=NULL`), then `recordJournalTransfer`, then
  `FlightPayment::update(transaction_id=...)`, then
  `TreasuryLedgerMirror`. If step 2 fails, the payment row is
  soft-deleted so we never leave an orphan payment without a
  transaction.

## 4. The test

New file `tests/Feature/Flight/FlightPaymentNoDoubleIncomeTest.php`
(412 lines, 4 tests, 21 assertions, **all pass**):

| # | Test | Asserts |
|---|------|---------|
| 1 | `single payment creates one transfer no extra income` | 1 sale + 1 payment transfer; 0 income-type txs; `sale_gl_transaction_id` unchanged |
| 2 | `n partial payments create exactly one sale and n transfers` (N=4) | 1 sale + 4 payment transfers; sum(payments) = sum(transfers) = 1000 EGP; all 4 payment txs are `type=Transfer`; `sale_gl_transaction_id` unchanged |
| 3 | `single active income guard still works after b2 fix` | 2nd `type=Income` on same (FlightBooking, id) → `InvalidArgumentException` "Duplicate income transaction blocked" |
| 4 | `income slot is freed after reversal` | Path C — reversed income (notes prefix `عكس:`) frees the slot; new income succeeds |

Test fixture fixes required during writing (educational notes for future
test authors):

- `Account.module_type = 'tourism'` is **rejected** for subject accounts
  (`type` in `{customer, supplier}`). Use `'flights'` for Flight
  customer AR accounts. See `Account.php:264-291` + `AccountModuleContract`.
- `Transaction.module = 'flight'` (singular) per `TransactionModule`
  enum — the transactions table and the accounts table use *different*
  naming conventions on this column.
- HTTP test auth requires `Sanctum::actingAs($user)`, not
  `$this->actingAs($user)`.

## 5. Test baseline

| Phase | Failed | Skipped | Passed | Δ Failed | Δ Passed |
|-------|--------|---------|--------|----------|----------|
| Phase 1.5 (baseline) | 148 | 6 | 1898 | — | — |
| Phase 2 (B-1) | 152 | 6 | 1902 | +4 | +4 |
| **Phase 3 (B-2)** | **152** | **6** | **1906** | **0** | **+4** |

**Analysis of the delta vs Phase 2:**
- **0 new failures** introduced by the B-2 fix.
- **+4 new passing tests** from the B-2 verification suite (tests 1–4 above).
- The +4 pre-existing baseline failures (Bus/Fawry/Customer/Wallet) from
  Phase 2 are **unchanged** — they are out of scope per user directive.
  These are tracked as Phase 10 backlog.
- **No regressions** in any Tourism/Hajj/Visa test.
- All pre-existing "duplicate income" related tests
  (`duplicate_income_guard_*`, `single_active_income_*`,
  `booking_sale_records_one_income_*`) still pass.

## 6. What was NOT touched (per user directive)

- ❌ **Historical data** — 22 legacy cases with duplicate-income rows
  are untouched. Deferred to **Phase 4 (read-only plan)**.
- ❌ **BusBookingPolicy.php** — untracked, never committed.
- ❌ **`.env.backup_incident_20260818`** — untracked, never committed.
- ❌ **HajjUmra / Visa / Bus payment paths** — out of scope for this
  phase. HajjUmra already has the canonical pattern from
  FC-AUDIT-20260814.
- ❌ **Migrations / seeders** — none added (per Phase 1.5 rule).
- ❌ **Production DB** — not touched. Tests use SQLite `:memory:` only.

## 7. Files changed

```
app/Services/Flight/FlightBookingService.php         |  19 +/-  16   (the fix + comment)
tests/Feature/Flight/FlightPaymentNoDoubleIncomeTest.php (NEW)    412  (the test)
```

## 8. What I need from you (next decision)

Per your standing directive *"متكملش لوحدك"* — I am **STOPPING here
for review**. The next phase per the original 10-phase plan is:

> **Phase 4 — Plan historical-data correction for 22 cases (read-only, do not execute)**

Please confirm:
1. Phase 3 (B-2) fix is accepted as-is, OR
2. Adjustments needed (e.g., revert, change test scope, etc.)

Then say "اعتمد Phase 3 — ابدأ Phase 4" and I'll plan Phase 4 as a
read-only inventory of the 22 legacy cases — *no execution, no
mutations*.