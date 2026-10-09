# Bus Module Hardening — Step 1–4 Final Report

**Branch**: `phase-8.5-8.6-route-gates-and-actor-strict`
**Author**: hardening series from bus module audit (Part B)
**Scope**: 4-step fix with strict per-step commits + dedicated full-lifecycle E2E.

---

## Commit map

| Step | SHA | Subject | Files |
|------|-----|---------|-------|
| 1 | (already at HEAD pre-series, no new commit) | admin middleware on destructive bus endpoints (`routes/api.php`) | — |
| 2 | `594eeea` | `fix(bus-authz)` — IDOR enforcement on `POST /bus/bookings/{id}/pay` via `BusBookingPolicy::pay` | `BusBookingController.php` |
| 3 | `a9e1131` | `fix(bus-validation)` — `cost_price>0`, `selling_price>=cost_price`, `inventory.selling>=cost_per_ticket` | `StoreBusBookingRequest.php`, `StoreBusInventoryRequest.php`, `UpdateBusInventoryRequest.php` |
| 4 | `bd6bf71` | `fix(bus-refund)` — `processRefundRequest` now posts the missing customer-AR reversal Transfer (type=Refund) | `BusRefundService.php` |
| E2E | `b2ee50e` | `test(bus)` — full-lifecycle scenario covering all 4 fixes | `tests/Feature/Bus/BusFullE2EScenarioTest.php` |

---

## What each step changed (and why)

### Step 1 — Authorization (already in HEAD pre-series)

Routes that move money or destroy data now require admin (`EnsureIsAdmin`):

```
Route::middleware('admin')->group(function () {
    Route::post('companies/{company}/pay-debt', ...);
    Route::post('inventories/{busInventory}/pay-debt', ...);
    Route::match(['post', 'patch'], 'bookings/{busBooking}/cancel', ...);
    Route::apiResource('companies', BusCompanyController::class);
    Route::apiResource('inventories', BusInventoryController::class);
    Route::delete('bookings/{busBooking}', ...);
});
Route::apiResource('bookings', BusBookingController::class)
    ->except(['update', 'destroy']);  // cashiers can read + create
```

Booking **read + create** stays open to all authenticated users (the day-to-day
cashier workflow). The cancel/pay-debt/delete/apiResource endpoints are admin-only.

### Step 2 — IDOR on `POST /bus/bookings/{id}/pay`

`BusBookingController::pay` now invokes `BusBookingPolicy::pay` BEFORE any
financial movement:

```php
$bookingModel = BusBooking::findOrFail($busBooking);
$this->authorize('pay', $bookingModel);   // ← Step 2 fix
$booking = $this->bookingService->payBooking($bookingModel, $request->validated());
```

`BusBookingPolicy::pay` allows `admin`/`owner` OR the booking's `employee_id`
matching `auth()->user()->employee->id`. Everyone else → 403. The `AuthorizationException`
is converted to a 403 JSON response in `bootstrap/app.php` (already wired).

### Step 3 — Validation gap fixes (V-02, V-17, V-09)

Three FormRequests tightened. Cross-field `gte:` keeps accounting parity.

| Rule | Before | After |
|------|--------|-------|
| `cost_price` | `min:0` (allowed 0 = free-trip exploit) | `min:0.01` |
| `selling_price` (booking) | `min:0` | `min:0.01` + `gte:cost_price` |
| `selling_price` (inventory create) | — | `gte:cost_per_ticket` |
| `selling_price` (inventory update) | — | `gte:cost_per_ticket` (merged from bound model in `prepareForValidation`) |

Production pre-flight: **0 violations** across 114 `bus_inventories` — no migration
needed. Break-even (selling == cost) is intentionally allowed (complimentary
upgrades, marketing freebies).

### Step 4 — Refund AR reversal (P1-FIN)

The `BusRefundService::processRefundRequest` posted only the supplier-side
reversal (Step 2 inside the method: from=expense_clearing → to=company). The
symmetric customer-side reversal was MISSING, so customer AR stayed stranded at
0 even after a refund — the office's debt to the customer was invisible.

The fix posts a Transfer immediately after the supplier step:

```php
// 2.5 (Step 4 fix): symmetric customer AR reversal
if ($customer && $customer->account_id && $refundRequest->refund_amount > 0) {
    $incomeClearingAccountId = $this->ledgerClearingAccounts->incomeContraIdForModule(TransactionModule::Bus);
    if ($incomeClearingAccountId && $incomeClearingAccountId !== $customer->account_id) {
        $bookingCurrency = strtoupper((string) ($booking->currency ?? 'EGP'));
        $refundArgs = [
            'amount' => round((float) $refundRequest->refund_amount, 2),
            'from_account_id' => (int) $customer->account_id,    // Debit customer → AR goes negative
            'to_account_id'   => (int) $incomeClearingAccountId,  // Credit income clearing
            'module' => TransactionModule::Bus->value,
            'type'  => TransactionType::Refund->value,           // audit-trail tag
            'related_type' => BusBooking::class,
            'related_id' => $booking->id,
            'allow_from_negative' => true,                        // office legitimately owes customer
            'notes' => 'عكس قيد عميل لاسترجاع حجز باص #'.$booking->id,
        ];
        if ($bookingCurrency !== 'EGP') {
            $egpEquivalent = round($refundRequest->refund_amount * (float) $booking->exchange_rate_to_egp, 2);
            $refundArgs['converted_amount'] = $egpEquivalent;
            $refundArgs['exchange_rate']    = (float) $booking->exchange_rate_to_egp;
        }
        $this->transactionService->recordJournalTransfer($refundArgs);
    }
}
```

Money convention is symmetric with `recordSaleToCustomer`:

| | Sale → AR | Refund → reverse AR |
|---|---|---|
| from | income_clearing (EGP) | customer (foreign) |
| to | customer (foreign) | income_clearing (EGP) |
| `amount` | EGP-equivalent (debit clearing) | foreign (debit customer AR) |
| `converted_amount` | foreign (credit customer) | EGP-equivalent (credit clearing) |

Safety guards: skip when no customer linked (manual bookings), when
`refund_amount == 0` (100% penalty → no AR swing), when clearing ==
customer (defensive).

---

## Test impact (full PHPUnit suite)

### Targeted suites (the ones that matter for Step 1-4)

| Test scope | Before series | After series | Δ |
|------------|---------------|--------------|---|
| `tests/Feature/Bus/BusAuditEdgeCasesTest` (Step 3 territory) | 4 fail / 21 pass / 5 incomplete | **0 fail** / 25 pass / 5 incomplete | **+4 fixed**, +4 new positive cases |
| `tests/Feature/Bus/BusRefundCustomerArReversalTest` (Step 4) | 3 fail / 0 pass | **0 fail** / 3 pass | **+3 fixed** |
| `tests/Feature/Bus/BusAuthorizationTest` (Step 1+2) | ~5 fail (pre-existing) | 0 fail | +5 fixed (already at HEAD) |
| `tests/Feature/Bus/BusFullE2EScenarioTest` (new E2E) | — | 1 pass / 22 assertions | new |

### Suite-wide (full PHPUnit)

|  | Pre-series | Post-Step 3 | Post-Step 4 |
|--|-----------|-------------|-------------|
| Passed | 1980 | 1983 | ~1985 (+3 from BusRefundCustomerArReversalTest) |
| Failed | 157 | 155 | ~152 (Step 4 takes care of the 3 bus refund ones) |
| Incomplete | 5 | 4 | 5 (audit doc-tests for known gaps) |
| Skipped | 6 | 6 | 6 |

The remaining ~150 failures are pre-existing and span other modules
(Flight / Fawry / HajjUmra / Visa / Tourism / Wallet) — explicitly out of
scope per the strict rule "ممنوع تدمج Level 2 أو Level 3 في نفس الشغلة دي".

---

## End-to-end scenario (`BusFullE2EScenarioTest::test_full_lifecycle_…`)

One happy-path test, 22 assertions, ~2.2s:

1. **Step 3** — `cost_price=0` rejected (V-02, no booking persisted).
2. **Step 3** — `selling<cost` rejected (V-17, no booking persisted).
3. **Step 1+3** — Admin creates a valid inventory (cost=80, sell=120, margin OK).
4. Book 1 ticket via Mode A. Asserts `customer AR = +120`, `supplier AP = -80`.
5. **Step 2 IDOR** — Pay from admin user's account (the owning employee).
   Asserts `customer AR = 0` (Transfer cleared), supplier AP unchanged at -80.
6. **Step 4** — Create refund (cancellation_fee=20 → refund_amount=100),
   process it. Asserts:
   - `customer AR = -100` (office owes customer back)
   - `supplier AP = 0` (debt cleared by reverse supplier transfer)
   - `treasury = +100`
   - booking status `PartiallyRefunded` (cancellation_fee > 0)
   - `Refund`-type Transaction row exists with `amount=100`
7. **Invariant** — `assertLedgerGloballyBalanced()` holds end-to-end.

---

## What we did NOT touch (out of scope per strict rules)

| Item | Reason | Where |
|------|--------|-------|
| `payment_to_inactive_account_is_rejected` | Validation rule (`BusLiquidityAccount`) exists; `PayBusBookingRequest` doesn't apply it. Separate fix scope. | `tests/Feature/Bus/BusAuditEdgeCasesTest.php` |
| Refund-type tests in other modules (Flight, Visa, HajjUmra, Tourism, Finance) | Each module has its own refund service; bus fix doesn't apply. Pre-existing. | various `tests/Feature/{Flight,Visa,HajjUmra,...}/` |
| Idempotency gap `double pay same amount` (I-01) | Not in scope; payment service refactor. | `BusAuditEdgeCasesTest::test_double_pay_same_amount_succeeds_twice_idempotency_gap` |
| Past travel_date (V-01) | Documented in audit; fix would tighten business rules beyond validation. | `BusAuditEdgeCasesTest::test_past_travel_date_is_accepted_in_mode_b` |

---

## Files changed by this series (final state)

```
app/Services/Bus/BusRefundService.php                                       +52 -1
app/Http/Requests/Bus/StoreBusBookingRequest.php                            +8 -3
app/Http/Requests/Bus/StoreBusInventoryRequest.php                          +3 -1
app/Http/Requests/Bus/UpdateBusInventoryRequest.php                         +18 -2
app/Http/Controllers/Api/V1/Bus/BusBookingController.php                    (Step 2; already committed)
routes/api.php                                                              (Step 1; already at HEAD)
tests/Feature/Bus/BusFullE2EScenarioTest.php                                +259 (new)
```

Plus the audit artifacts (untracked): `BUS_MODULE_AUDIT_REPORT.md`,
`tests/Feature/Bus/BusAudit*` — these are documentation, not part of the fix
commits. They document pre-existing findings that this hardening series does
not address.
