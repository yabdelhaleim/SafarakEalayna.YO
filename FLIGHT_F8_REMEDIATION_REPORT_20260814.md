# ✈️ Flight F-8 Refund Source-Locking Remediation Report

**Date:** 2026-08-14
**Scope:** F-8 ONLY — refund auto-source rule enforcement.
**Environment:** SQLite isolated (`storage/app/local_flight_audit.sqlite`), `.env` locked (chmod 444), admin token from `flight_audit_setup.php`.

---

## 1. Files Changed (2 files)

### A. `app/Http/Requests/Flight/StoreFlightRefundRequest.php`
- Added `use App\Models\Account;`, `use App\Models\Flight\FlightBooking;`, `use App\Models\Flight\FlightPayment;`, `use Illuminate\Contracts\Validation\Validator;`
- Added `withValidator()` method that enforces:
  1. If caller supplies `account_id`, its type MUST be `cashbox` / `bank` / `wallet` (AccountType enum unwrapped to string for compare)
  2. If refund > 0 and no payments exist on the booking → reject (no fabrication of destination)
  3. If refund > 0 and caller supplies `account_id` that differs from the original payment's `account_id` → reject (F-8 source-lock)
- Kept backward-compatible `account_id` field; the validator only enforces rules on it.

### B. `app/Services/Flight/FlightBookingService.php`
- Replaced the "refund > 0 requires account_id" exception with an auto-derivation from the most-recent `FlightPayment::account_id` for the booking.
- Added defense-in-depth re-check INSIDE the cancel transaction:
  1. The refund destination must equal the original payment's `account_id` (else `InvalidArgumentException`).
  2. The account type must be `cashbox` / `bank` / `wallet` (AccountType enum unwrapped) — else `InvalidArgumentException`.

---

## 2. Root Cause

The original `cancelBooking` flow took the caller-supplied `data['account_id']` verbatim and passed it to `refundTreasuryAccount()` (lines ~2286–2291 pre-fix). The FormRequest only validated that the account `exists`; there was no rule tying it to the booking's actual payment source. An admin who selected any account type (Customer AR, Cashbox B, etc.) at the cancel screen would have their selection honored, breaking the spec rule:

> "Refund must automatically return from the SAME account/cashbox that originally received the payment."

Additionally, the service would throw an exception if the caller forgot to send `account_id` instead of deriving it from the booking's actual payment history — pushing the burden of knowing the right source onto every caller (a UI hole waiting to happen).

---

## 3. Exact Fix Implemented

### Layer 1 — `StoreFlightRefundRequest::withValidator()` (validation layer)

```php
public function withValidator(Validator $validator): void {
    $validator->after(function (Validator $v) {
        $airlinePenalty = (float) ($this->input('airline_penalty', 0));
        $officePenalty  = (float) ($this->input('office_penalty', 0));
        $callerAccountId = $this->input('account_id');

        $booking = $this->route('flightBooking') instanceof FlightBooking
            ? $this->route('flightBooking')
            : FlightBooking::find($this->route('flightBooking'));
        if (!$booking) return;

        $payments = FlightPayment::query()
            ->where('flight_booking_id', $booking->id)
            ->whereNull('deleted_at')
            ->orderByDesc('id')->get();
        $impliedRefund = max(0.0, (float) $payments->sum('amount') - $airlinePenalty - $officePenalty);

        // (1) Reject non-liquidity account types — refund MUST land in cashbox/bank/wallet.
        if ($callerAccountId !== null) {
            $acct = Account::query()->find($callerAccountId);
            if ($acct) {
                $typeValue = is_object($acct->type) && property_exists($acct->type, 'value')
                    ? $acct->type->value : (string) $acct->type;
                if (! in_array(strtolower($typeValue), ['cashbox','bank','wallet'], true)) {
                    $v->errors()->add('account_id', "نوع حساب الاسترداد ({$typeValue}) غير مسموح.");
                    return;
                }
            }
        }

        // (2) If refund will occur but no payments exist → reject (no fabrication).
        if ($impliedRefund > 0.0001 && $payments->isEmpty()) {
            $v->errors()->add('account_id', 'لا توجد مدفوعات مسجلة، فلا يمكن إجراء مرتجع.');
            return;
        }

        // (3) F-8 SOURCE-LOCK: supplied account_id MUST equal original payment's account_id.
        if ($impliedRefund > 0.0001 && $callerAccountId !== null && !$payments->isEmpty()) {
            $sourceAccountId = (int) ($payments->first()->account_id ?? 0);
            if ($sourceAccountId > 0 && (int) $callerAccountId !== $sourceAccountId) {
                $v->errors()->add('account_id', 'لا يمكن توجيه المرتجع إلى حساب مختلف عن الحساب الذي استلم الدفعة الأصلية.');
            }
        }
    });
}
```

### Layer 2 — `FlightBookingService::cancelBooking()` (service layer, defense-in-depth)

Replaced the throwing block:

```php
if ($refundAmount > 0 && empty($data['account_id'])) {
    throw new \InvalidArgumentException('يجب اختيار حساب الصرف عند وجود مبلغ مرتجع للعميل.');
}
```

with auto-derivation from the original payment:

```php
if ($refundAmount > 0 && empty($data['account_id'])) {
    $sourcePayment = FlightPayment::query()
        ->where('flight_booking_id', $booking->id)
        ->whereNull('deleted_at')->orderByDesc('id')->first();
    if ($sourcePayment && $sourcePayment->account_id) {
        $data['account_id'] = (int) $sourcePayment->account_id;
    } else {
        throw new \InvalidArgumentException('لا توجد مدفوعات مسجلة على هذا الحجز لتحديد حساب الاسترداد الأصلي.');
    }
}
```

Then added a defense-in-depth re-check immediately after Step 3.5:

```php
if ($refundAmount > 0 && !empty($data['account_id'])) {
    $sourcePayment = FlightPayment::query()
        ->where('flight_booking_id', $booking->id)
        ->whereNull('deleted_at')->orderByDesc('id')->first();
    if ($sourcePayment && (int) $sourcePayment->account_id !== (int) $data['account_id']) {
        throw new \InvalidArgumentException(
            'انتهاك قاعدة F-8: لا يمكن توجيه المرتجع إلى حساب ({'.(int) $data['account_id'].'}) '
            .'مختلف عن الحساب الذي استلم الدفعة الأصلية ({'.(int) $sourcePayment->account_id.'}).'
        );
    }
    if ($refundAccount) {
        $typeValue = is_object($refundAccount->type) && property_exists($refundAccount->type, 'value')
            ? $refundAccount->type->value : (string) $refundAccount->type;
        if (! in_array(strtolower($typeValue), ['cashbox','bank','wallet'], true)) {
            throw new \InvalidArgumentException(
                "نوع حساب الاسترداد ({$typeValue}) غير مسموح. يجب أن يكون cashbox/bank/wallet."
            );
        }
    }
}
```

Both layers wrap inside the existing `DB::transaction(...)` (Step 1–6), so failed validations throw and atomically roll back — no orphan entries, no partial refund record, no partial balance update.

---

## 4. Targeted F-8 Test Results

**Test script:** `scripts/flight_audit_f8_targeted.php`
**Results file:** `storage/logs/flight_audit_f8_targeted_results.json`

| # | Test | Expected | Actual | Result |
|---|---|---|---|---|
| **F8-01** | Refund without `account_id` auto-returns to source Cashbox A | auto-source | HTTP 200, refund_account_id=3 (cashbox A), cashbox_delta=500 (5000 paid - 4500 refunded) | ✅ PASS |
| **F8-02** | Refund to Customer AR account is rejected | HTTP 4xx | HTTP 422, AR balance unchanged, booking stays PENDING | ✅ PASS |
| **F8-03** | Refund to a different Cashbox B is rejected | HTTP 4xx | HTTP 422, Cashbox B balance unchanged, booking stays PENDING | ✅ PASS |
| **F8-04** | Partial refund auto-returns to source Cashbox A | auto-source | HTTP 200, refund_account_id=3 (cashbox A), refund_amount=3000 (5000 paid - 2000 penalty), cashbox_delta=-3000 | ✅ PASS |
| **F8-05** | Multiple sequential cancels — first succeeds with auto-source, second rejected | first 200 / second 4xx | first HTTP 200, refund_account_id=3; second HTTP 422 | ✅ PASS |
| **F8-06** | Over-penalty (6000 > 5000 paid) does NOT move money | refund_amount=0, no balance change | HTTP 200, refund_amount=0, cashbox_delta=0 | ✅ PASS |
| **F8-07** | Successful refund — full integrity (balance, entries, refund record, original-payment relation, no orphans, no drift) | all layers agree | HTTP 200, refund_account_id=3, payment_account_id=3, refund_amount=5000, drift=0, orphans=0 | ✅ PASS |
| **F8-08** | Failed refund (invalid account_id) → complete rollback | HTTP 4xx + no state change | HTTP 422, cashbox_delta=0, status PENDING→PENDING, refund_count unchanged | ✅ PASS |
| **F8-09** | Employee cannot refund (F-2 admin-only) | HTTP 403 | HTTP 403, booking PENDING | ✅ PASS |
| **F8-10** | `account_id` tampering (AR account) is rejected | HTTP 4xx + no money moved | HTTP 422, cashbox_delta=0, no refund posted, booking PENDING | ✅ PASS |

**TOTAL: 10 PASS / 0 FAIL ✅**

---

## 5. Whether F-8 is Fully Fixed

**YES** — F-8 is fully fixed at two layers (validation + service), with atomic rollback on any failure. The original finding ("RefundService accepts any account_id from request") is closed:

| Original F-8 risk | Mitigation now in place |
|---|---|
| Caller supplies arbitrary `account_id` for refund | FormRequest validates supplied `account_id` matches original payment's `account_id` AND is cashbox/bank/wallet type |
| Caller forgets to supply `account_id` | Service auto-derives from `FlightPayment::account_id` of the booking |
| Caller tries AR account (Customer Receivable) | FormRequest + service both reject with type-mismatch error |
| Caller tries a different cashbox | FormRequest rejects the source-mismatch; service re-asserts as defense-in-depth |
| Service-layer bypass (e.g. direct controller invocation) | Service-layer re-check inside `DB::transaction` is the final guard |
| Failed refund leaves orphan entries / partial state | Existing `DB::transaction` + `LedgerBalanceMutationGuard` rollback — verified by F8-08 |

---

## 6. `§4-foreign-booking-foreign-payment` — Business-Rule Test Mismatch

**Status:** ⚠️ FLAGGED — not changed per directive ("Do NOT silently change unrelated Flight Module code").

**Test scenario:** SAR-denominated booking paid by customer into SAR cashbox.

**Analysis:**

The test `§4-foreign-booking-foreign-payment` lives in `scripts/flight_audit_phase_e2e_full.php`. It:
1. Creates a SAR-denominated booking.
2. Calls `POST /api/v1/flight/bookings/{id}/payments` with `amount=50` and `account_id=$sarCashbox->id` (SAR cashbox).

This IS a CUSTOMER payment (the endpoint is `/payments`, not a cost-side endpoint). Per the user's stated business rule:

> "CUSTOMER PAYMENTS ARE ALWAYS COLLECTED IN EGP."

However, the current `FlightBookingService::addPayment` at `app/Services/Flight/FlightBookingService.php:1899–1912` explicitly ALLOWS this case:

```php
$isForeignMismatch = $isBookingForeign
    && $paymentCurrency !== $bookingCurrency
    && !($isPaymentEgp && $isCustomerEgp);
```

For SAR booking + SAR cashbox:
- `$isBookingForeign = true`
- `$paymentCurrency !== $bookingCurrency` is **false** (both SAR)
- Therefore `$isForeignMismatch = false` → request ACCEPTED.

**Conclusion:** This test is exercising the CURRENT multi-currency collection path (SAR booking + SAR cashbox), but per the spec the customer collection should always go to an EGP cashbox. The test name implies "foreign-currency booking paid from foreign-currency cashbox" (a legitimate cost-side pattern), but the endpoint it uses is the CUSTOMER payment endpoint, not a cost-side endpoint.

**This is therefore a BUSINESS-RULE TEST MISMATCH, not a cost-side mislabel.**

**Disposition per directive:**
- I have NOT modified `addPayment` to reject SAR-cashbox customer payments.
- I have NOT changed the `§4-foreign-booking-foreign-payment` test or its name.
- The behavior is preserved as-is for backward compatibility.
- This finding should be raised separately in a future audit pass dedicated to the customer-payment EGP-only enforcement (a separate, higher-priority rule that pre-dates this F-8 work).

If a future remediation wants to enforce EGP-only customer collection, the fix would be:

```php
// In FlightBookingService::addPayment(), REPLACE the $isForeignMismatch block:
if ($isBookingForeign || ($paymentCurrency !== 'EGP' && $paymentCurrency !== $bookingCurrency)) {
    if ($paymentCurrency !== 'EGP') {
        throw new \Exception(
            "تحصيل العميل يجب أن يكون دائماً بعملة EGP. ".
            "عملة الحساب المحدد ({$paymentCurrency}) غير مسموح بها لتحصيل العميل."
        );
    }
}
```

But again, **not implemented here** per the directive.

---

## 7. Migration / Schema Changes

**None.** The existing `flight_payments.account_id` column already provides the source-payment traceability required by F-8. No `source_payment_id` migration was needed.

---

## 8. Out-of-Scope Items (not touched)

- `RefundService::createRefundRequest` / `processRefundRequest` / `reverseRefundRequest` (these use the separate `RefundRequest` model, not the `cancel` flow tested by §10.1).
- `AviationController`, `RefundController` (different refund paths).
- Filament Resources.
- Vue frontend.
- Production data.

---

**Summary:** F-8 is FIXED at two layers (validation + service) with full atomic rollback. Targeted suite: 10/10 PASS. The `§4-foreign-booking-foreign-payment` test scenario is flagged as a separate business-rule mismatch (customer collection to SAR cashbox) and left unchanged per directive.

— End of report —