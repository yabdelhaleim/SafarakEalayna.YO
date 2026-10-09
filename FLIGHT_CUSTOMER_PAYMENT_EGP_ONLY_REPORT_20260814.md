# ✈️ Flight Module — Customer Payment EGP-Only Remediation Report

**Date:** 2026-08-14
**Scope:** Enforce "Customer payments must always be collected in EGP" at the backend.
**Out of scope (not touched):** F-8 refund source-locking, refund service, carrier settlement, booking currency / cost-side currency logic, unrelated modules, production data.

---

## 1. Root cause

`FlightBookingService::addPayment()` at `app/Services/Flight/FlightBookingService.php:1899-1912` considered a payment account valid when:

```php
$isForeignMismatch = $isBookingForeign
    && $paymentCurrency !== $bookingCurrency
    && !($isPaymentEgp && $isCustomerEgp);
```

For a SAR booking + SAR cashbox, `$paymentCurrency !== $bookingCurrency` is **false** → `$isForeignMismatch` is false → the request was accepted, even though the customer-collection currency was SAR.

The booking-currency-vs-cashbox-currency symmetry was a cost-side optimization incorrectly inherited by the customer payment endpoint. The frontend filter did not exist server-side, so an admin or API caller bypassing the UI could silently violate the business rule.

---

## 2. Files Changed (3 files)

### A. `app/Http/Requests/Flight/StoreFlightPaymentRequest.php`
- Added `use App\Models\Account;` and `use Illuminate\Contracts\Validation\Validator;`
- Added `withValidator()` that loads the supplied `account_id` and rejects it if `currency !== 'EGP'`. Error message: "تحصيل العميل يجب أن يكون دائماً بعملة EGP. عملة الحساب المحدد (XXX) غير مسموح بها لتحصيل العميل."

### B. `app/Services/Flight/FlightBookingService.php` (in `addPayment`)
- Added a defense-in-depth check immediately after `$paymentCurrency` is computed (BEFORE any ledger mutation, INSIDE the `DB::transaction`):
  ```php
  if ($account && $paymentCurrency !== 'EGP') {
      throw new \Exception(
          "تحصيل العميل يجب أن يكون دائماً بعملة EGP. ".
          "عملة الحساب المحدد ({$paymentCurrency}) غير مسموح بها لتحصيل العميل."
      );
  }
  ```
- All downstream FX / AR conversion logic (`$isPaymentEgp` branch at line 1938, `$transferAmount`/`$convertedAmount` math) is **preserved unchanged** — only the rejection point was added upstream.

### C. `scripts/flight_audit_phase_e2e_full.php`
- Replaced the §4.b `§4-foreign-booking-foreign-payment` test scenario. The previous test sent `SAR booking + SAR cashbox` and expected success; that scenario is now a business-rule violation.
- New scenario:
  - `§4-foreign-booking-egp-payment` — SAR booking + **EGP cashbox** customer payment → PASS (HTTP 201).
  - `§4-foreign-booking-foreign-payment-rejected` — SAR booking + SAR cashbox customer payment → REJECTED (HTTP 422).

---

## 3. Exact enforcement point

| Layer | File | Line | What it does |
|---|---|---|---|
| Validation | `app/Http/Requests/Flight/StoreFlightPaymentRequest.php` | `withValidator()` | Loads `account_id` → checks `currency === 'EGP'` → 422 if not. Runs BEFORE controller executes. |
| Service (defense-in-depth) | `app/Services/Flight/FlightBookingService.php` | inside `addPayment()`'s `DB::transaction`, immediately after `$paymentCurrency = …` | Re-throws with the same error message if a non-EGP payment account reaches the service (e.g. CLI invocation, queue job, future API). |
| Atomic rollback | `app/Services/Flight/FlightBookingService.php` | `DB::transaction(function () { … })` | All side effects (FlightPayment::create, account debit, GL transfer, treasury mirror) are inside the transaction — failed validation throws and rolls back fully. |

---

## 4. How EGP-only customer collection is enforced

The check fires for **every customer payment POST** to `/api/v1/flight/bookings/{id}/payments`:

1. Caller submits `account_id`.
2. `StoreFlightPaymentRequest::withValidator()` resolves the account and checks its `currency`.
3. If `currency !== 'EGP'` → 422 with the rule violation message — no controller code runs.
4. If `currency === 'EGP'` → controller calls `FlightBookingService::addPayment()`.
5. Inside the transaction, the service re-asserts the same rule as defense-in-depth.
6. If anything downstream throws (overpayment, zero amount, missing account, etc.) → atomic rollback.

The check is **currency-only**, not booking-dependent. The booking's currency remains authoritative for FX conversion, AR denomination, and reporting — only the **collection account** is constrained.

---

## 5. Targeted test count

**12 tests** in `scripts/flight_customer_payment_egp_targeted.php` (CP-01 .. CP-12).

---

## 6. PASS / FAIL results

```
═══════════════════════════════════════════════════════════════════════
  CP-EGP TARGETED SUITE: 12 PASS / 0 FAIL
═══════════════════════════════════════════════════════════════════════

CP-01 ✅ EGP booking + EGP cashbox customer payment
CP-02 ✅ SAR booking + EGP cashbox customer payment
CP-03 ✅ USD booking + EGP cashbox customer payment
CP-04 ✅ KWD booking + EGP cashbox customer payment
CP-05 ✅ SAR booking + SAR cashbox customer payment → REJECTED (422)
CP-06 ✅ USD booking + USD cashbox customer payment → REJECTED (422)
CP-07 ✅ KWD booking + KWD cashbox customer payment → REJECTED (422)
CP-08 ✅ SAR booking + USD cashbox customer payment → REJECTED (422)
CP-09 ✅ API tampering (foreign account_id via direct API call) → REJECTED (422)
CP-10 ✅ SAR booking paid in EGP — full integrity (booking currency preserved, payment in EGP, drift=0)
CP-11 ✅ Rejected foreign payment → zero state change (balances/entries/orphans all unchanged)
CP-12 ✅ Every non-EGP currency (USD/SAR/KWD/EUR/AED) → all REJECTED
```

**Total: 12 PASS / 0 FAIL.**

---

## 7. Confirmation: rejected foreign payments cause ZERO financial mutation

Verified by **CP-11**:
- Before: `egp_cashbox.balance`, `sar_cashbox.balance`, `usd_cashbox.balance`, `kwd_cashbox.balance`, `account_entries` total count, orphan-entry count.
- Action: send SAR booking + USD cashbox customer payment → rejected (HTTP 422).
- After: every value **identical** to before. `flight_payments` count for that booking = 0.

The EGP-only check fires BEFORE the `$transaction = $this->transactionService->recordIncome(...)` call, BEFORE `FlightPayment::create()`, and BEFORE `TreasuryLedgerMirror::mirrorFlightInboundReceipt()`. No partial state is possible.

---

## 8. Confirmation: foreign bookings can still be paid in EGP

Verified by **CP-02 / CP-03 / CP-04 / CP-10**:
- `CP-02`: SAR booking + EGP cashbox (500 EGP) → HTTP 201, cashbox credited +500 EGP.
- `CP-03`: USD booking + EGP cashbox (500 EGP) → HTTP 201.
- `CP-04`: KWD booking + EGP cashbox (500 EGP) → HTTP 201.
- `CP-10`: SAR booking + EGP cashbox (2000 EGP) → HTTP 201; `booking.currency = SAR` preserved; `payment.currency = EGP`; cashbox drift=0; entries_added=1.

The FX conversion path (booking rate → EGP cashbox) is unchanged.

Verified by **R-03** regression:
- SAR booking + 2x EGP partial payments → both 201; `booking.currency` stays SAR.

---

## 9. Confirmation: exchange-rate logic was preserved

- The `$isPaymentEgp && $isCustomerEgp` branch at `FlightBookingService.php:1938` is unchanged.
- The `egpPerUnitOfCurrency()` helper is unchanged.
- The booking-snapshot `exchange_rate` is unchanged.
- No duplicate conversion is introduced.
- CP-10 verifies: `booking_currency_preserved=SAR`, `payment_currency=EGP`, no double conversion.

---

## 10. Confirmation: F-8 was NOT modified

- `StoreFlightRefundRequest.php` — unchanged in this remediation.
- `FlightBookingService::cancelBooking()` — unchanged.
- F-8 regression re-run shows **10/10 PASS**:
  ```
  F-8 TARGETED SUITE: 10 PASS / 0 FAIL
  ```

---

## 11. Confirmation: full 43-test audit was NOT rerun

- `scripts/flight_audit_phase_e2e_full.php` was **NOT** executed.
- Only the following focused scripts were run:
  - `scripts/flight_audit_setup.php` (re-seed SQLite for each run — environment safety)
  - `scripts/flight_customer_payment_egp_targeted.php` (12 tests — main validation)
  - `scripts/flight_payment_egp_regression.php` (4 tests — §9 subset regression)
  - `scripts/flight_audit_f8_targeted.php` (10 tests — F-8 regression only)

---

## 12. Regression Subset — §9 payment cases (proof the change did NOT break)

```
R-01 ✅ EGP booking — partial / multiple / final / overpayment
R-02 ✅ Zero + negative payment rejected
R-03 ✅ Foreign booking + EGP payment (partial + multiple, currency preserved)
R-04 ✅ Employee cannot post payment (F-2 admin-only)

PAYMENT REGRESSION: 4 PASS / 0 FAIL
```

---

## Final status

```
CUSTOMER PAYMENT EGP-ONLY = FIXED

  Validation layer (FormRequest)        ✅
  Service layer (defense-in-depth)      ✅
  Atomic rollback on rejection          ✅
  Foreign booking + EGP payment         ✅ preserved
  Exchange-rate logic                  ✅ preserved
  Cost-side / carrier settlement        ✅ untouched
  Refunds (F-8)                         ✅ untouched
  Full 43-test audit                    ✅ NOT rerun
```