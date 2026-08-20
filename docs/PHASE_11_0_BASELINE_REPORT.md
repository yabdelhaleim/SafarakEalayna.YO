# PHASE 11.0 — BASELINE REPORT
## Flight Module Production-Readiness Audit

**Branch**: `phase-10-tourism-production-audit-hajj-umra` (continuing audit lineage)
**Date**: 2026-08-20
**Environment**: MySQL `127.0.0.1:3306` / `safarakealayna` (DB_CONNECTION=mysql)
**PHPUnit**: SQLite `:memory:` (test suite), MySQL (production / concurrency tests)
**Prior Phase Reports**: PHASE_8_5, PHASE_8_6, PHASE_9 (Visa + Hajj/Umra audit lineage already merged)

---

## 1. CRITICAL DISCOVERY — ONE MODEL, ONE SERVICE, DISCRIMINATOR-POWERED

The Flight module does **NOT** have three separate models/controllers/services for the three booking paths. Instead:

- **One model**: `App\Models\Flight\FlightBooking`
- **One controller**: `App\Http\Controllers\Api\V1\Flight\FlightController`
- **One service**: `App\Services\Flight\FlightBookingService` (3,439 lines)
- **One table**: `flight_bookings` (single, with discriminator columns)
- **One URL prefix**: `/api/v1/flight/bookings` for all three paths

The three paths are distinguished by **two discriminator fields** on the booking row:

| Field | Values | Default | Determines |
|---|---|---|---|
| `booking_channel_type` | `SYSTEM` (GDS/NDC), `SIGN` (direct carrier), `GROUP` | `SIGN` | Source label / display only |
| `purchase_balance_source` | `carrier`, `system`, `group` | resolved from input | **Financial path** (which balance pool pays for the ticket) |

The `resolvePurchaseBalanceSource()` method (line 839) hard-fails the booking if `booking_channel_type=GROUP` is sent without `flight_group_id`. Beyond that, the channel type is mostly **display-only** — the actual financial behavior is dictated by `purchase_balance_source`.

---

## 2. THREE-PATH FLOW MATRIX

### PATHA — CUSTOMER (DIRECT / SIGN)
- **Debtor (cost)**: `FlightCarrier` (the carrier's prepaid balance pool).
- **Debtor (selling)**: Customer (debt recorded on customer's ledger account).
- **Receivable on create**: Customer AR (via clearing → customer GL transfer).
- **Receivable on payment**: Cashbox/vault chosen by caller (`payment.account_id`).
- **Refund account on cancel**: Caller-selected (must match booking currency).
- **State machine**: `PENDING → CONFIRMED` (when fully paid) / `→ CANCELLED` (no refund) / `→ REFUNDED` (with refund).

### PATH B — SYSTEM (GDS / NDC)
- **Debtor (cost)**: `FlightSystem` (the GDS/NDC balance pool).
- **Debtor (selling)**: Customer (same as Path A).
- **Receivable on create**: Customer AR.
- **Receivable on payment**: Cashbox.
- **Refund account on cancel**: Same currency constraint.
- **State machine**: Identical to Path A.

### PATH C — GROUP
- **Debtor (cost)**: `FlightGroup` (the group's B2B account; tracks debt via `FlightGroupTransaction`).
- **Debtor (selling)**: **Customer** — `recordSaleToCustomer` is called regardless of path (line 435). This is **the** business decision: customer owes the office the selling price; group owes the airline the cost. They are kept independent.
- **Receivable on create**: Customer AR for selling-price leg, plus a FlightGroupTransaction for cost leg.
- **Receivable on payment**: Cashbox (from customer). The group's debt to the airline is **separate** and repaid via the dedicated `POST /api/v1/flight/groups/{group}/pay-debt` endpoint.
- **Refund account on cancel**: Caller-selected (must match booking currency).
- **State machine**: Identical to Path A.

### KEY BUSINESS RULE (verified by `FlightGroupController::payDebt` and `FlightBookingService::recordPurchaseFromGroup`)

> The current implementation records **TWO separate AR/debt relationships** on a group booking:
> 1. **Group AR** = `purchase_price` (cost of the ticket) — the office's wholesale debt to the airline via the group.
> 2. **Customer AR** = `selling_price` — the retail amount the customer owes the office.
>
> When the customer pays, only the customer's AR decreases and the cashbox is credited. The group's wholesale debt remains until the office explicitly pays it back via `POST /groups/{group}/pay-debt`.

This is a **deliberate separation** of the B2B (group ↔ airline) and B2C (office ↔ customer) financial flows. Phase 11 must preserve this behavior unless a defect is found.

---

## 3. ARCHITECTURE MAP

### Backend
| Layer | Path | Purpose |
|---|---|---|
| Routes | `routes/api.php:167-305` | `/api/v1/flight/*` prefix; auth:sanctum + admin middleware |
| Controllers | `app/Http/Controllers/Api/V1/Flight/` (12 files) | FlightController, AviationController, FlightSystemController, FlightCarrierController, FlightGroupController, FlightTreasuryController, FlightDashboardController, RefundController, ModificationController, AirlineAccountController, AirportController, PassengerController |
| FormRequests | `app/Http/Requests/Flight/` | StoreFlightBookingRequest, UpdateFlightBookingRequest, UpdateFlightPricesRequest, StoreFlightPaymentRequest, StoreFlightRefundRequest, RechargeFlightSystemRequest, StoreAviationBookingRequest |
| Services | `app/Services/Flight/` | FlightBookingService (3,439 lines), AviationService, RefundService, ModificationService, AirlineAccountDebitService, FlightCarrierRechargeService, FlightSystemRechargeService, FlightGroupThresholdService |
| Finance services | `app/Services/Finance/` | TransactionService, TreasuryService, PrepaidLedgerService, LedgerClearingAccounts, TreasuryLedgerMirror, TreasuryAccountResolver, RefundAuditLogger, AccountService |
| Models | `app/Models/Flight/` (13 models) | FlightBooking, FlightCarrier, FlightSystem, FlightGroup, FlightGroupTransaction, FlightSystemTransaction, FlightPassenger, FlightPayment, FlightRefund, FlightSegment, FlightTicket, AirlineAccount, AirlineCredit, AirlineTransaction, RefundRequest, TicketModification |
| Policy | `app/Policies/FlightBookingPolicy.php` | `pay()` + `cancel()` — admin/owner OR owning employee only (B-1 IDOR fix) |
| Permission | `App\Support\UserPermissions::MANAGE_FLIGHTS` | Custom JSON column on `users`; NOT spatie |
| Observers | `app/Observers/FlightGroupObserver.php` | Threshold tracking |
| Events | `TicketModified`, `FlightGroupThresholdNotification`, `PassengerAlertNotification`, `BalanceTamperDetectedNotification` |
| Guards | `ModelDeletionGuard`, `ModelProfitMutationGuard`, `LedgerBalanceMutationGuard` (on FlightBooking, FlightCarrier, FlightSystem) |

### Frontend
| Page | File | Route |
|---|---|---|
| Dashboard | `resources/js/views/flights/FlightDashboard.vue` | `/flights` |
| List | `resources/js/views/flights/FlightIndex.vue` | `/flights/list` |
| Create | `resources/js/views/flights/FlightCreate.vue` (4,450 lines) | `/flights/create` |
| Edit | `resources/js/views/flights/FlightEdit.vue` | `/flights/{id}/edit` (no-edit, returns 405) |
| Show | `resources/js/views/flights/FlightShow.vue` (1,712 lines) | `/flights/{id}` |
| Customers | `resources/js/views/flights/FlightCustomersIndex.vue` | `/flights/customers` |
| Passengers | `resources/js/views/flights/PassengersIndex.vue` | `/flights/passengers` |
| Treasury | `resources/js/views/flights/FlightTreasuryOverview.vue` | `/flights/treasury` |
| Airline txns | `resources/js/views/flights/FlightAirlineTransactions.vue` | `/flights/airline-transactions` |
| Carriers debt | `resources/js/views/flights/FlightCarriersDebt.vue` | `/flights/carriers-debt` |
| Carrier details | `resources/js/views/flights/FlightCarrierDetails.vue` | `/flights/carriers/{id}` |
| Groups | `resources/js/views/flights/FlightGroupsIndex.vue` | `/flights/groups` |
| Pinia Store | `resources/js/stores/flightStore.js` (1,246 lines) | Single global `useFlightStore` |

### Database Tables
```
flight_bookings        — main booking table (15+ additive migrations)
flight_segments        — segments per booking
flight_passengers      — passengers per booking (note: legacy `passengers` table too)
flight_tickets         — tickets per booking (or per passenger)
flight_pricings        — detailed pricing (replaced flight_pricing)
flight_payments        — payments (SoftDeletes)
flight_refunds         — refunds from cancel
flight_systems         — GDS/NDC systems
flight_carriers        — direct carrier balances (no mass-update on balance)
flight_groups          — group accounts (SoftDeletes, credit_limit, threshold config)
flight_group_transactions — group ledger (type: debt/payment)
flight_system_transactions — system ledger
airline_accounts       — separate "bank-like" balance for airlines
airline_transactions   — credit/debit audit
airline_credits        — credit vouchers
refund_requests        — multi-currency refund flow (with idempotency_key)
ticket_modifications   — change-management ledger
```

---

## 4. KEY SAFEGUARDS DISCOVERED (already in place)

1. **B-1 IDOR Fix** (2026-08-15): `FlightBookingPolicy::pay()` and `cancel()` restrict to admin/owner OR the booking's `employee_id`. `StoreFlightPaymentRequest::prepareForValidation()` whitelists allowed fields and rejects unknown keys (e.g., customer_id spoofing).

2. **D3 Idempotency Fix** (2026-08-15): `flight_payments.idempotency_key` (nullable, 100 chars) + UNIQUE index `fp_idem_uniq`. Pre-check inside `lockForUpdate()` + DB-level backstop.

3. **INCIDENT-2026-08-17 No-Edit Contract**: `FlightBookingService::updateBooking()` and `updatePrices()` throw `\LogicException`. PUT/PATCH on bookings returns 405. Tourism-wide rule.

4. **DEFECT-1 Auto-promotion** (2026-08-15): PENDING → CONFIRMED when cumulative payments ≥ selling_price. Inside same DB transaction as the payment insert.

5. **DEFECT-2 Sale GL preservation** (2026-08-15): `sale_gl_transaction_id` is NOT cleared on cancel; reversal uses new mirror rows (additive accounting).

6. **D4 Defensive price guard** (2026-08-15): Service-layer rejection of negative prices (was reachable via CLI/internal callers bypassing FormRequest).

7. **Carrier/System balance guards**: Direct mass-update blocked except via `LedgerBalanceMutationGuard::run()` or service-managed `debit()`/`credit()` methods.

8. **Cascading deletes**: `FlightPassenger` / `FlightSegment` hard-deleted when booking soft-deleted (no orphan rows).

9. **Pre-existing flight test suite** (24 test files in `tests/Feature/Flight/` and `tests/Feature/FlightBooking*Test.php`) covers: full E2E, multi-currency, payment ownership, deletion reversal, system/carrier controllers, group flow, no-double-income, payment reversal, treasury overview, soft-delete real-world, group currency column, KWD payment conversion.

---

## 5. KNOWN GAPS / OPEN RISKS

1. **`App\Http\Controllers\Api\FlightBookingController.php`** — older orphan file; routes/api.php uses the V1 namespace. **MUST VERIFY** it is unreachable.

2. **Two parallel Filament resource trees**:
   - `app/Filament/Admin/Resources/FlightBookings/` (Filament v3)
   - `app/Filament/Resources/Flight/` (Filament v2)
   
   Both exist. Need to verify which is actually registered.

3. **Same FormRequest for cancel and refund**: `StoreFlightRefundRequest` is used by both `FlightController::cancel()` and the RefundController flow. Could cause validation surprises if the two flows diverge.

4. **No dedicated Jobs directory for flight** — async work is via `ShouldQueue` on listeners/mail.

5. **AviationController is a "lighter alternate surface"** for the same booking table — needs verification that its financial flow matches `FlightBookingService`.

6. **PHPUnit default DB is SQLite `:memory:`** — Phase 11.12 (HTTP concurrency) requires MySQL with `StressayGuard`. Tests need explicit `--database=mysql` flag.

---

## 6. PHASE 11.0 DELIVERABLE — FLOW MATRIX

| Concern | PATH A — CUSTOMER (SIGN) | PATH B — SYSTEM (GDS) | PATH C — GROUP |
|---|---|---|---|
| Discriminator | `booking_channel_type=SIGN` (or null) | `booking_channel_type=SYSTEM` | `booking_channel_type=GROUP` |
| Cost source | `purchase_balance_source=carrier` | `purchase_balance_source=system` | `purchase_balance_source=group` |
| Cost debtor | FlightCarrier.balance (debit) | FlightSystem.balance (debit) | FlightGroup.account_id (debit via FlightGroupTransaction) |
| Selling debtor | Customer.account_id | Customer.account_id | Customer.account_id (separate from group debt) |
| Sale journal | clearing → customer (recordSaleToCustomer) | same | same |
| Prepaid GL | PrepaidLedgerService::consumeCogs('flight_carrier') | PrepaidLedgerService::consumeCogs('flight_system') | (not used; group debt ledger only) |
| Payment source | customer → cashbox (recordJournalTransfer type=Transfer) | same | same |
| Cancel — cost reversal | creditBackFlightCarrier (purchaseEgp − airlinePenalty) | creditBackFlightSystem | reverseGroupPurchase |
| Cancel — sale reversal | recordJournalTransfer (customer → clearing) for saleReversalAmount | same | same |
| Cancel — refund | refundTreasuryAccount (cashbox → customer) | same | same |
| Delete — payment reversal | reverseSinglePayment per payment (skipped if already refunded) | same | same |
| Delete — sale reversal | recordJournalTransfer (customer → clearing) at full selling_price | same | same |
| Delete — residual clearing | conditional journal if refund had penalties kept | same | same |
| Delete — cost reversal | creditBackFlightCarrierExact (airlinePenalty from existing refund) | creditBackFlightSystemExact | reverseGroupPurchase |
| Status terminal | CANCELLED (no refund) / REFUNDED (refund > 0) | same | same |
| Soft delete | flight_bookings.trashed + cascade passengers/segments | same | same |

---

## 7. STOP-CONDITION CHECKPOINTS

Phase 11 mandates STOP on:
- Class-A defect (data corruption, money creation/destruction)
- Class-B defect (silent wrong balance, missing reversal)
- Wrong debtor (customer charged for group debt, or vice versa)
- Cross-currency corruption
- Refund mismatch
- Concurrency race producing financial inconsistency
- Production-safety violation

The audit will pause at the first such defect and surface it.

---

## 8. NEXT STEPS

- **Phase 11.1**: MASTER DATA — verify customer/system/group/airline/currency master data can never create financial corruption
- **Phase 11.2**: FE+BE E2E — Vue page contracts verified against backend response shape
- **Phase 11.3**: 24-scenario matrix per path
- **Phase 11.4**: Multi-currency matrix (EGP/USD/EUR + mismatch tests)
- **Phase 11.5**: Payment/partial-payment deep (10k booking split into 1k+2k+3k+4k)
- **Phase 11.6**: Debt ownership audit (Customer A/B + Group A/B separation; IDOR)
- **Phase 11.7**: Financial reconciliation (sum debit == sum credit)
- **Phase 11.8**: Refund deep audit
- **Phase 11.9**: Cancel deep audit
- **Phase 11.10**: Delete/reverse deep audit
- **Phase 11.11**: Idempotency
- **Phase 11.12**: True HTTP concurrency (C1-C10) against MySQL
- **Phase 11.13**: Failure injection
- **Phase 11.14**: Security / IDOR
- **Phase 11.15**: State machine
- **Phase 11.16**: FE financial display audit
- **Phase 11.17**: Reporting reconciliation
- **Phase 11.18**: Final verdict (30-section report)