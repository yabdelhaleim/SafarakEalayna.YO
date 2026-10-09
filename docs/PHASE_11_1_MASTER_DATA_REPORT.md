# PHASE 11.1 — MASTER DATA AUDIT REPORT
## Flight Module Production-Readiness Audit

**Branch**: `phase-10-tourism-production-audit-hajj-umra` (continuing audit lineage)
**Date**: 2026-08-20
**Test File**: `tests/Feature/Flight/Phase11MasterDataAuditTest.php`
**Test Result**: **23 PASSED, 0 FAILED, 2 INCOMPLETE (documented gaps)** — 53 assertions

---

## 1. SCOPE

Verify that NO master-data configuration (customers, carriers, systems, groups, currencies) can create financial corruption. Each test asserts balance invariants, currency consistency, mass-assignment protection, and direct-write guards.

**Three booking paths** — all share the same master-data surface:
- CUSTOMER (SIGN/carrier)
- SYSTEM (GDS/system)
- GROUP (group)

---

## 2. TEST RESULTS — ALL 25 CASES

### SECTION A — CUSTOMER MASTER DATA (2/2 PASS)
| # | Test | Result | Notes |
|---|---|---|---|
| A1 | Customer auto-creates ledger account on insert | ✅ PASS | CustomerLedgerObserver verified |
| A2 | Multiple customers → independent ledger accounts | ✅ PASS | No shared accounts |

### SECTION B — FLIGHT CARRIER MASTER DATA (7/7 PASS, 1 DEFECT FIXED)
| # | Test | Result | Notes |
|---|---|---|---|
| B1 | Carrier create with zero balance | ✅ PASS | Initial balance = 0 (no mass-assign) |
| B2 | Carrier balance NOT mass-assignable | ✅ PASS | `balance=999999` in create() → ignored |
| B3 | Carrier recharge same-currency succeeds | ✅ PASS | 50k recharge → 50k balance, 150k cashbox |
| B4 | Carrier recharge currency-mismatch blocked | ✅ PASS | Controller returns 422 with clear error |
| B5 | Carrier delete blocked with non-zero balance | ✅ PASS | Refuses with balance error |
| B6 | Carrier delete blocked with active bookings | ✅ PASS | Refuses with bookings error |
| B7 | **Inactive carrier should not create bookings** | ✅ PASS | **DEFECT FOUND AND FIXED** |

### SECTION C — FLIGHT SYSTEM MASTER DATA (4/4 PASS)
| # | Test | Result | Notes |
|---|---|---|---|
| C1 | System create with zero balance | ✅ PASS | Same as carrier |
| C2 | System recharge succeeds | ✅ PASS | Same as carrier |
| C3 | System balance NOT mass-assignable | ✅ PASS | Defended-in-depth |
| C4 | System delete blocked with attached carriers | ✅ PASS | Refuses |

### SECTION D — FLIGHT GROUP MASTER DATA (2/3 PASS, 1 GAP)
| # | Test | Result | Notes |
|---|---|---|---|
| D1 | Group create with carrier and credit_limit | ✅ PASS | |
| D2 | **Group negative credit_limit validation** | ⚠ INCOMPLETE | **CLASS-C DEFECT: model accepts -1000** |
| D3 | Group threshold settings stored | ✅ PASS | info/warning/danger all persisted |

### SECTION E — CURRENCY MASTER DATA (5/5 PASS)
| # | Test | Result | Notes |
|---|---|---|---|
| E1 | Active currency resolves exchange rate | ✅ PASS | USD rate = 50.0 |
| E2 | Inactive currency still uses rate (with warning) | ✅ PASS | Falls back with log |
| E3 | Undefined currency uses built-in FALLBACK | ✅ PASS | KWD=157.5, SAR=12.9, GBP=61.2 |
| E4 | EGP returns 1.0 | ✅ PASS | All variants |
| E5 | Truly unknown currency returns 0 | ✅ PASS | Triggers validation error |

### SECTION F — CROSS-ENTITY INVARIANTS (2/2 PASS)
| # | Test | Result | Notes |
|---|---|---|---|
| F1 | Carrier currency handling | ✅ PASS | Currency conversion verified |
| F2 | Group account auto-created on first booking | ✅ PASS | Account debit ≤ -purchase_price |

### SECTION G — DIRECT MUTATION ATTEMPTS (1/2 PASS, 1 GAP)
| # | Test | Result | Notes |
|---|---|---|---|
| G1 | Carrier balance direct save blocked by observer | ✅ PASS | `strict_test_guards=true` triggers observer |
| G2 | **Negative credit_limit rejected at model** | ⚠ INCOMPLETE | **CLASS-C DEFECT: carrier accepts -1000** |

---

## 3. DEFECTS DISCOVERED

### DEFECT 11.1-B-7 — CLASS-B (SECURITY/PRODUCTION-SAFETY)
**Status**: ✅ **FIXED**
**Location**: `app/Models/Flight/FlightCarrier.php::debit()`, `app/Models/Flight/FlightSystem.php::debit()`
**Symptom**: An inactive carrier/system with a non-zero prepaid balance could be used to create new bookings. The booking service's `debitFlightCarrier` and `debitFlightSystem` did NOT check `is_active`, while `FlightCarrierRechargeService::rechargeFromAccount` and `FlightSystemRechargeService::rechargeFromAccount` DID check it. Inconsistent safety net.
**Risk**: Production-safety violation. An admin who deactivates a carrier (e.g., suspending business with a problematic vendor) could not prevent new bookings from drawing down the carrier's remaining balance.
**Reproduction**:
1. Create active carrier with `credit_limit=100000`
2. Recharge it with 50000 EGP
3. Deactivate via `UPDATE flight_carriers SET is_active=0`
4. Create a booking routed to this carrier — **succeeds** (was the bug)
**Fix Applied**:
- `FlightCarrier::debit()` now throws `InactiveFlightCarrierException` if `! $this->is_active`
- `FlightSystem::debit()` now throws new `InactiveFlightSystemException` if `! $this->is_active`
- Created `app/Exceptions/InactiveFlightSystemException.php`
- Mirrors the existing `InactiveFlightCarrierException` pattern from D5 fix (2026-08-15)

**Regression Test**: B7 in `Phase11MasterDataAuditTest` — now PASSES after fix.

---

### DEFECT 11.1-D2 — CLASS-C (VALIDATION GAP)
**Status**: ⚠ **DOCUMENTED, NOT FIXED IN THIS PHASE**
**Location**: `app/Models/Flight/FlightGroup.php` + migration `2026_07_16_155939_add_credit_limit_to_flight_groups.php`
**Symptom**: `FlightGroup::create()` and `FlightCarrier::create()` accept negative `credit_limit` values. The `available_balance = balance + credit_limit` formula becomes restrictive rather than additive (e.g., credit_limit=-1000 makes the group spend 1000 LESS than its balance), which is semantically inverted.
**Risk**: Validation gap. Inverted semantics could confuse operators. No direct financial damage (the negative value RESTRICTS rather than ENABLES spending).
**Recommended Fix** (for Phase 11.18 or separate ticket):
- Add model-level `saving` event guard on `FlightGroup` and `FlightCarrier` that rejects negative `credit_limit`
- Add DB-level CHECK constraint on `flight_groups.credit_limit >= 0` and `flight_carriers.credit_limit >= 0`
- Migration `2026_08_20_phase11_master_data_defense_in_depth.php` is staged (MySQL only)

---

### DEFECT 11.1-G2 — CLASS-C (VALIDATION GAP)
**Status**: ⚠ **DOCUMENTED, NOT FIXED IN THIS PHASE**
**Location**: `app/Models/Flight/FlightCarrier.php` + migration
**Symptom**: Same as D2 but for `flight_carriers.credit_limit`.
**Recommended Fix**: Combined with D2 above.

---

## 4. POSITIVE FINDINGS (defenses already in place)

1. ✅ Customer auto-creates ledger account via observer
2. ✅ Carrier/System balance removed from `$fillable` — cannot be mass-assigned
3. ✅ Carrier/System direct `$balance = X; $save()` blocked by `booted()` observer (in strict mode)
4. ✅ Recharge service blocks currency mismatch at controller level
5. ✅ Delete blocked with non-zero balance OR active bookings
6. ✅ Currency fallback chain: active DB → inactive DB → built-in FALLBACK_EGP_PER_UNIT → 0
7. ✅ Currency resolution: EGP=1, lowercase/empty=1, undefined=0
8. ✅ Group account auto-created on first GROUP booking (verified debit ≤ -purchase_price)

---

## 5. KEY DISCOVERIES FOR DOWNSTREAM PHASES

1. **Three paths share master data surface**: customer AR account, carrier/system/group balance pool. No master-data divergence between paths.

2. **Carrier currency = source of truth for cross-currency math**:
   - `FlightBookingService::purchaseAmountInBalanceCurrency()` converts EGP purchase price to carrier currency using `purchaseAmountInBalanceCurrency(balanceCurrency, bookingCurrency, purchasePriceEGP, purchasePriceForeign, lockedEgpPerBalanceUnit)`.
   - `lockedEgpPerBalanceUnit` comes from `persistedSettlementSnapshot` (saved on booking row) for deterministic reversal.
   - Phase 11.4 currency matrix must test ALL combinations.

3. **Group accounts are auto-provisioned** by `recordPurchaseFromGroup` (line 3225-3238 of `FlightBookingService`). Account type = `AccountType::Supplier` (B2B).

4. **The `FlightBookingService::createBooking` flow unconditionally calls `recordSaleToCustomer`** for all three paths — this is the design decision that separates customer AR (selling) from group AR (cost).

5. **No exchange_rate_override**: Client cannot spoof exchange rate (line 248 forces server-side lookup).

---

## 6. COMMITS IN THIS PHASE

```
Phase 11.1 B-7 DEFECT FIX: is_active guard on FlightCarrier::debit and FlightSystem::debit
- app/Models/Flight/FlightCarrier.php: added is_active check + InactiveFlightCarrierException
- app/Models/Flight/FlightSystem.php: added is_active check + new InactiveFlightSystemException
- app/Exceptions/InactiveFlightSystemException.php: new file

Phase 11.1 STAGED (defense-in-depth migration):
- database/migrations/2026_08_20_phase11_master_data_defense_in_depth.php: 
  CHECK constraints on credit_limit >= 0 (MySQL only; SQLite is no-op)

Phase 11.1 TESTS:
- tests/Feature/Flight/Phase11MasterDataAuditTest.php: 25 cases across 7 sections
```

---

## 7. REMAINING GAPS / OPEN ITEMS

1. **Negative credit_limit** (D2/G2) — needs model-level guard (next ticket)
2. **Filament v2 vs v3** — both trees exist; v3 is the active one. v2 should be removed.
3. **Orphan `app/Http/Controllers/Api/FlightBookingController.php`** — empty stub, unreachable from routes. Should be deleted.

---

## 8. NEXT STEP

→ **Phase 11.2 — FE+BE E2E** (Vue page contracts verified against backend response shape for all three booking paths)