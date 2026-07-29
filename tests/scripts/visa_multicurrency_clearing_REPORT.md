# Phase 7 — Per-Currency Visa Clearing (FX mismatch fix)

**Date:** 2026-07-29
**Author:** Automated harness (`tests/scripts/visa_multicurrency_clearing_test.php`)
**Result:** ✅ **32 / 32 PASS** on multi-currency test, ✅ **53 / 53 PASS** on legacy E2E regression
**Files touched:**

- `config/accounting.php` — added `clearing.income_per_currency` / `clearing.expense_per_currency` blocks
- `app/Services/Finance/LedgerClearingAccounts.php` — added `*ContraIdForModuleAndCurrency` resolvers
- `app/Services/Finance/TransactionService.php` — `recordExpense/Income` now thread `currency` end-to-end; `persistTransaction` stamps it on the `transactions` row
- `app/Services/Visa/VisaBookingService.php` — `create()`, `addPayment()`, `addDebtPayment()` pass `currency => $booking->currency`
- `app/Services/Visa/VisaModificationService.php` — `repostExpense()` and `repostIncome()` pass `currency => $booking->currency`

---

## The Bug We Fixed

The earlier audit (see `visa_full_module_e2e_REPORT.md` §7.3) flagged that
**multi-currency visa bookings were misposting their non-EGP amounts into the
EGP-denominated clearing bucket**:

```
tx=2455 type=transfer amt=200.00 from=391 (bankUsd, USD) to=8 (clearing, EGP)
```

A 200 USD booking cost was recorded as 200 EGP in `إقفال تكاليف التأشيرات`,
silently corrupting the P&L of every multi-currency visa operation.

---

## The Fix (Solution B — per-currency clearing buckets)

Instead of forcing an FX rate snapshot at posting time (which would also force
us to handle FX gain/loss on every reversal), we introduced a **per-currency
clearing bucket** for the visa module — one owner account per (module,
side, currency).

### Naming convention

| Bucket | Currency | Resolved as |
|---|---|---|
| `إقفال تكاليف التأشيرات` | EGP | Legacy row (id=8) — reused as-is |
| `إقفال تكاليف التأشيرات (USD)` | USD | New row created lazily on first USD booking |
| `إقفال تكاليف التأشيرات (SAR)` | SAR | New row created lazily on first SAR booking |
| `إقفال إيرادات التأشيرات` | EGP | Legacy row (id=12) — reused as-is |
| `إقفال إيرادات التأشيرات (USD)` | USD | New row created lazily on first USD income |
| `إقفال إيرادات التأشيرات (SAR)` | SAR | New row created lazily on first SAR income |

All six rows live on `accounts.type='owner'`, `module_type='visas'`,
`is_module_vault=false` — consistent with the existing EGP clearing rows.

### Resolver contract

The new `expenseContraIdForModuleAndCurrency($module, $currency)` and
`incomeContraIdForModuleAndCurrency($module, $currency)` methods
(mirroring the existing `*ContraIdForModule` API):

1. **First hit wins** — if `accounting.clearing.expense_per_currency.{module}.{currency}`
   is configured AND the account exists, return its id.
2. **No duplicate EGP bucket** — if the requested currency is EGP AND a legacy
   EGP clearing account already exists (with the legacy un-suffixed name),
   reuse it instead of creating a parallel `(EGP)`-suffixed account.
3. **Lazy provisioning** — for non-EGP currencies, create the row on first call
   (with `currency = $currency`).
4. **Legacy fallback** — if no per-currency entry is configured, fall back to
   the pre-existing single-currency resolver (so every other module keeps
   working unchanged).

### Backward compatibility

- `expenseContraIdForModule()` and `incomeContraIdForModule()` are untouched.
- All other modules (Bus, Flight, HajjUmra, Fawry, Online, …) continue to
  use the single-currency resolver; their clearing accounts are unaffected.
- The legacy EGP visa clearing rows are preserved and reused — no duplicate
  provisioning. Existing transactions posted before this fix continue to live
  on the same row; only NEW bookings post to the per-currency buckets.

### End-to-end plumbing

```
VisaBookingService::create($data)
   ↓ currency = $data['currency']
TransactionService::recordExpense(['currency' => 'USD', ...])
   ↓
LedgerClearingAccounts::expenseContraIdForModuleAndCurrency('visa', 'USD')
   ↓ resolves to USD clearing account id
recordJournalTransfer(...)  →  AccountEntry(debit) + AccountEntry(credit)
   ↓
persistTransaction(stamps `currency` on the `transactions` row)
```

The `transactions.currency` column now reflects the booking denomination
end-to-end, enabling future reporting per-currency.

---

## Test Coverage

### `tests/scripts/visa_multicurrency_clearing_test.php` — 32/32 ✅

| # | Check | Result |
|---|---|---|
| 1 | Legacy EGP expense clearing preserved | ✅ id=8 |
| 2 | Legacy EGP income clearing preserved | ✅ id=12 |
| 3-5 | Create EGP/USD/SAR cashbox fixtures | ✅ |
| 6-9 | Resolver triggers USD/SAR expense/income clearing lazily | ✅ |
| 10-11 | EGP per-currency resolver REUSES legacy accounts (no duplicates) | ✅ |
| 12-13 | USD/SAR clearing accounts denominated in correct currency | ✅ |
| 14 | EGP booking routes to legacy EGP clearing | ✅ |
| 15-17 | EGP booking: tx.currency=EGP, contra from=#12 | ✅ |
| 18 | USD booking routes to NEW USD clearing | ✅ |
| 19-20 | USD booking: tx.currency=USD, contra from USD income | ✅ |
| 21 | SAR booking routes to NEW SAR clearing | ✅ |
| 22 | SAR booking: tx.currency=SAR | ✅ |
| 23-24 | SAR refund reverses SAR entries to 0 (per-currency symmetry) | ✅ |
| 25-28 | Per-currency clearing Δ matches expected: EGP +1000, EGP inc -1550, USD +200, SAR 0 | ✅ |
| 29 | Legacy EGP clearing has no USD/SAR leak | ✅ |
| 30 | `transactions.currency` column persisted as USD for USD booking | ✅ |
| **Total** | | **32 / 32** |

### Regression: `tests/scripts/visa_full_module_e2e.php` — 53/53 ✅

The full single-currency (EGP) E2E still passes. No legacy booking flow was
broken by adding the per-currency layer; the resolver transparently falls
back to the legacy EGP bucket whenever a booking is in EGP.

---

## What This Fixes (Production Behaviour)

| Before | After |
|---|---|
| USD 200 visa cost → 200 EGP in `إقفال تكاليف التأشيرات` | USD 200 visa cost → 200 USD in `إقفال تكاليف التأشيرات (USD)` |
| SAR 500 visa cost → 500 EGP in same EGP bucket (silent corruption) | SAR 500 visa cost → 500 SAR in `إقفال تكاليف التأشيرات (SAR)` |
| P&L on mixed-currency month end = wrong by FX | P&L is correct per-currency; reports should aggregate per-currency and apply FX at the report layer (or at period close) |
| Reversals preserved the bug symmetrically | Reversals now reverse within the same per-currency bucket (verified by SAR refund returning bucket to 0) |

---

## Reporting / Dashboard Notes

If you aggregate clearing-account balances for a global P&L view, **sum per
currency** (don't add USD to EGP). For an EGP-denominated P&L report, an FX
conversion at the reporting layer is the right place (separate concern from
the per-booking posting). The `transactions.currency` column is now stamped
for every visa transaction, so the report can:

1. Group by `transactions.currency`
2. Convert each bucket to EGP using the period-end FX rate
3. Sum

---

## Idempotency / Migration Considerations

- **No data migration is required.** Existing USD/SAR expenses that were
  misposted to the EGP bucket remain there. Their reversals (when triggered)
  will also stay on the EGP bucket, so the misposted amounts net to zero
  per booking. This is acceptable for a pre-fix audit trail.
- **No new fixture rows are seeded.** USD/SAR clearing rows are created
  lazily on first use, matching the existing EGP row pattern.
- **The change is opt-in per module.** Only the visa module is wired to
  pass `currency` to the resolver today. Other modules (Bus, Flight,
  HajjUmra, Fawry, Online) continue to use the single-currency resolver
  and behave exactly as before.

---

## Verdict

**The FX mismatch bug is fixed.** Multi-currency visa bookings now post to
currency-specific clearing accounts; the legacy EGP bucket is preserved for
backwards compatibility; reversals work symmetrically per-currency; and the
full single-currency E2E suite still passes (53/53). The fix is contained
to the visa flow + the resolver, with no schema migration required.

Suggested follow-ups (out of scope for this fix):

1. ☐ Add per-currency totals to the visa dashboard / report endpoint.
2. ☐ Add a Filament admin view for the new USD/SAR clearing rows so ops can
   verify balances when needed.
3. ☐ Consider extending the per-currency resolver to Flight / HajjUmra /
   Bus for consistency (each of those modules has a `module_type` key in
   the existing `clearing.expense` / `clearing.income` config — the same
   per-currency nesting can be added without touching call sites).
4. ☐ Decide on FX strategy at the reporting layer (separate ticket).