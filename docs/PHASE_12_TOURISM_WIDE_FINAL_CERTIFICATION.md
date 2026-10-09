# TOURISM-WIDE FINAL CERTIFICATION REPORT
**Flight + Hajj/Umra + Visa — Cross-Module + Finance + Security + Concurrency**

**Branch:** `phase-10-tourism-production-audit-hajj-umra`
**Date:** 2026-08-20
**Auditor:** ZCode (Tourism-Wide Final Certification agent)
**Verdict:** **🔴 TOURISM-WIDE NO-GO**

---

## 🔴 FINAL VERDICT

This audit **stops at Section 1** due to a **Class-A production-safety violation** discovered during the architecture-inventory phase:

> `App\Services\HajjUmra\HajjUmraBookingService.php` and `App\Services\Visa\VisaBookingService.php` contain **unresolved Git merge conflict markers** that produce PHP parse errors at autoload time.

The directive explicitly anticipates this scenario:

> *"Do NOT modify unrelated Hajj/Umra or Visa code merely because pre-existing merge conflicts exist. If a defect is found in another module, classify it according to the evidence and stop according to the circuit-breaker rules."*

Per the directive's circuit-breaker:

> *"STOP immediately on: Class-A / Class-B / financial inconsistency / cross-module contamination / IDOR/security failure / race condition / data corruption / **production-safety violation**"*

A parse error that prevents the Hajj/Umra and Visa service layers from loading is a **production-safety violation** (the code cannot be deployed in this state). Tourism-wide cross-module verification cannot be performed on a runtime that fails to load half of its modules.

---

## 1. Scope

The audit was scoped to discover defects that appear **only when Flight + Hajj/Umra + Visa + Finance operate together**. The three modules had each previously received individual 🟢 GO verdicts:

| Phase | Module | Branch | Verdict |
|-------|--------|--------|---------|
| Phase 9 | Visa | `phase-9-tourism-production-audit-visa` | 🟢 GO |
| Phase 10 | Hajj/Umra | `phase-10-tourism-production-audit-hajj-umra` | 🟢 GO |
| Phase 11 | Flight | merged into `main` | 🟢 GO |

This audit **does not re-run** the per-module audits. It operates on the current working tree (`phase-10-tourism-production-audit-hajj-umra`) which carries forward the work of all three prior phases plus the merge conflicts left behind by subsequent audit activity.

---

## 2. Environment proof

| Item | Value | Source |
|------|-------|--------|
| APP_ENV | `local` | `php artisan env` |
| Laravel | 13.6.0 (claims 13.6; framework reads Laravel) | `php artisan --version` |
| DB_CONNECTION | `mysql` | `.env` line 27 |
| DB_HOST | `127.0.0.1` | `.env` line 28, verified by `php artisan db:show` |
| DB_PORT | `3306` | `.env` line 29 |
| DB_DATABASE | `safarakealayna` | `.env` line 30, verified by `php artisan db:show` ("Database ……………… safarakealayna") |
| DB Username | `root` | `.env` line 31 |
| DB Server | MySQL 8.4.3 | `php artisan db:show` |
| Tables | 3,143 (total size 326.83 MB) | `php artisan db:show --counts` |
| Test env | `phpunit.xml` overrides APP_ENV=testing, DB_CONNECTION=sqlite, DB_DATABASE=:memory: | `phpunit.xml` line 32-34 |
| Branch | `phase-10-tourism-production-audit-hajj-umra` | `git branch --show-current` |
| Latest commit | `8f12ac3 docs(flight/p11): revised final verdict — all 8 mandatory gates evidenced` | `git log -1` |
| Active working-tree changes | `app/Http/Controllers/Api/V1/Fawry/FawryDashboardController.php` (modified) + 5 files with `UU` (unmerged) merge markers | `git status` |
| Production DB | **NOT touched** — local DB only | environment verification |

```
✅ Audit/test only against isolated local/staging test infrastructure
✅ Never touched production DB or production financial data
✅ APP_ENV and DB identity proven before any DB-changing test
✅ No production migrations
✅ No destructive production commands
```

The **test** sub-environment uses SQLite `:memory:` (fully isolated per test process), which is the same isolation strategy used in Phase 9 and Phase 10 GO verdicts.

---

## 3. Architecture inventory (Section 1)

### 3.1 Flight module — ✅ Auditable
- **Models:** `app/Models/Flight/*` — 16 Eloquent models (FlightBooking, FlightSegment, FlightPassenger, FlightTicket, FlightSystem, FlightSystemTransaction, FlightCarrier, FlightGroup, FlightGroupTransaction, FlightPayment, FlightRefund, RefundRequest, TicketModification, AirlineAccount, AirlineTransaction, AirlineCredit). All have booted guards blocking direct profit/balance mutation and deletion outside the service layer.
- **Services:** `app/Services/Flight/*` — FlightBookingService, RefundService, ModificationService, AviationService, AirlineAccountDebitService, FlightCarrierRechargeService, FlightSystemRechargeService, FlightGroupThresholdService. **All parse without error.**
- **Controllers:** `app/Http/Controllers/Api/V1/Flight/*` — FlightController, AviationController, FlightDashboardController, FlightTreasuryController, FlightSystemController, FlightCarrierController, FlightGroupController, AirlineAccountController, AirportController, RefundController, ModificationController, PassengerController.
- **Form Requests:** `app/Http/Requests/Flight/*` — StoreFlightBookingRequest, UpdateFlightBookingRequest, StoreFlightPaymentRequest, StoreFlightRefundRequest, UpdateFlightPricesRequest, StoreAviationBookingRequest, RechargeFlightSystemRequest.
- **Resources:** `app/Http/Resources/Flight/*` — FlightBookingResource, FlightSegmentResource, FlightPassengerResource, FlightTicketResource, FlightPaymentResource, FlightRefundResource.
- **Vue:** `resources/js/views/flights/*`, store `resources/js/stores/flightStore.js`.
- **Tables:** `flight_bookings`, `flight_segments`, `passengers`, `flight_pricings`, `flight_payments`, `flight_refunds`, `airline_accounts`, `airline_transactions`, `flight_systems`, `flight_carriers`, `flight_groups`, `flight_group_transactions`, `flight_tickets`, `flight_system_transactions`, `airports`, `airline_credits`, `refund_requests`, `ticket_modifications`. Plus shared `treasury_transactions` (with `flight_booking_id` FK).
- **Permissions/Gates:** `UserPermissions::MANAGE_FLIGHTS`, `MANAGE_REFUNDS`. `FlightBookingPolicy::pay()` / `cancel()`. B-1 IDOR fix: only admin/owner OR the booking's `employee_id`-linked Employee may pay/cancel.
- **Listeners (queued, `ShouldQueue`, retries=3):** `App\Listeners\ProcessTicketModificationAccounting` — full double-entry GL posting for ticket modification.

### 3.2 Hajj/Umra module — 🔴 Parse-blocked
- **Models:** `app/Models/HajjUmraBooking.php`, `app/Models/HajjUmraPayment.php`, `app/Models/UmrahTransactionPassenger.php`, `app/Models/HajjUmra/*` (ExecutingCompany, UmrahSupplier, VisaAgent, AccommodationType, Hotel, TripSupervisor, VisaDuration), `app/Models/Program.php`.
- **Services:**
  - `app/Services/HajjUmra/HajjUmraBookingService.php` — **🔴 PARSE ERROR** (992 lines, `<<<<<<<` markers at line 359 and line 804)
  - `app/Services/HajjUmra/HajjUmraRefundService.php` — not blocked; depends on HajjUmraBookingService
- **Controllers:** HajjUmraController (root), `app/Http/Controllers/Api/V1/HajjUmra/*` — HajjUmraDashboardController, HajjUmraTreasuryController, HajjUmraProgramController, HajjUmraExecutingCompanyFinanceController, UmrahSupplierApiController.
- **Form Requests:** `app/Http/Requests/HajjUmra/*` — StoreHajjUmraBookingRequest, UpdateHajjUmraBookingRequest (LOCKED_FIELDS = selling/purchase/companion/accommodation_extra_charge are STRIPPED pre-validation per Phase 10 audit), StoreHajjUmraPaymentRequest, StoreProgramRequest, UpdateProgramRequest.
- **Resources:** `app/Http/Resources/HajjUmra/HajjUmraBookingResource.php`.
- **Vue:** `resources/js/views/hajjUmra/*`, store `resources/js/stores/hajjUmraStore.js`.
- **Tables:** `hajj_umra_bookings`, `hajj_umra_payments`, `programs`, `hotels`, `accommodation_types`, `umrah_suppliers`, `hajj_bus_partners` (renamed `executing_companies`), `trip_supervisors`, `umrah_transaction_passengers`, `visa_durations`. Plus shared `treasury_transactions` (with `hajj_umra_booking_id` FK).
- **Permissions/Gates:** `UserPermissions::MANAGE_HAJJ`. No dedicated `HajjUmraBookingPolicy`.
- **Observers:** `HajjUmraExecutingCompanyObserver`, `UmrahSupplierObserver` — auto-create Supplier Account on save with `module_type='hajj_umra'`.

### 3.3 Visa module — 🔴 Parse-blocked
- **Models:** `app/Models/VisaBooking.php`, `app/Models/VisaPayment.php`, `app/Models/VisaDetail.php`, `app/Models/HajjUmra/VisaAgent.php`, `app/Models/HajjUmra/VisaDuration.php`. **No `app/Models/Visa/` directory exists.**
- **Services:**
  - `app/Services/Visa/VisaBookingService.php` — **🔴 PARSE ERROR** (721 lines, `<<<<<<<` marker at line 401)
  - `app/Services/Visa/VisaRefundService.php`, `VisaModificationService.php` — depend on the blocked VisaBookingService
- **Controllers:** `app/Http/Controllers/Api/V1/Visa/*` — VisaBookingController, VisaTreasuryController, VisaAgentApiController, VisaAgentFinanceController. Root `VisaController.php` (slim) for `/visa/customer-balances` and `pay-debt`.
- **Form Requests:** `app/Http/Requests/Visa/*` — StoreVisaBookingRequest (currency-match validator), UpdateVisaBookingRequest, StoreVisaPaymentRequest.
- **Resources:** `app/Http/Resources/Visa/VisaBookingResource.php`.
- **Vue:** `resources/js/views/visa/*`, store `resources/js/stores/visaStore.js`.
- **Tables:** `visa_bookings`, `visa_details`, `visa_payments`, `visa_agents`, `visa_durations`. Plus shared `treasury_transactions` (with `visa_booking_id` FK).
- **Permissions/Gates:** `UserPermissions::MANAGE_ONLINE`. No dedicated `VisaBookingPolicy`.
- **Observers:** `VisaAgentObserver` — auto-creates Supplier Account on save with `module_type='visas'`.

### 3.4 Shared Finance infrastructure
- **Models (root):** `Account`, `AccountEntry`, `Transaction`, `Transfer`, `Treasury`, `TreasuryTransaction` (deprecated/legacy), `Bank`, `Customer`, `Supplier`, `ExchangeRate`, `ApprovalWorkflow`, `AuditLog`, `RefundAuditLog`, `LedgerReconciliationRun`, `LedgerReconciliationFinding`.
- **Services (`app/Services/Finance/`):** AccountService, AccountRechargeService, AccountingService, ApprovalService, AuditService, CurrencyService, DeferredTransactionDeletionGuard, LedgerClearingAccounts, LedgerEntryDescriptionResolver, LedgerReconciliationService, LedgerRepairService, PrepaidLedgerService, RefundAuditLogger, SupplierAccountService, TransactionAuditStamper, **TransactionService** (the canonical GL writer), TreasuryAccountResolver, TreasuryLedgerMirror, TreasuryService, TrialBalanceExportService. **All parse without error.**
- **Tables:** `accounts`, `account_entries`, `transactions`, `transfers`, `treasury_transactions`, `treasuries`, `banks`, `exchange_rates`, `currencies`, `audit_logs`, `refund_audit_logs`, `approval_workflows`, `payment_methods`, `operation_types`, `customers`, `suppliers`.
- **Module type discriminator:** `AccountModuleContract::TOURISM_DIVISION_MODULES = ['tourism','flights','hajj_umra','visas']`. The `module_type` field on `accounts` distinguishes subject accounts (Customer/Supplier) per module.

### 3.5 Currency / FX
- **Enum:** `App\Enums\Currency` (EGP/KWD/SAR/USD). The system also tolerates EUR, AED, GBP via the `Setting\Currency` table.
- **Per-booking FX snapshot:** `flight_bookings.exchange_rate`/`exchange_rate_used`, `flight_pricings.exchange_rate_used`, `flight_payments.original_amount`, `ticket_modifications.exchange_rate_snapshot`, `refund_requests.refund_exchange_rate`, `treasury_transactions.exchange_rate/base_amount`, `transactions.exchange_rate/converted_amount`.
- **`CurrencyService::convert()`** — same-currency returns 1:1; otherwise lookups `ExchangeRate(from, to, is_active, effective_date <= date)`; inverse-rate fallback; currencies-table fallback for EGP pairs; throws if no rate available.

---

## 4-16. Cross-module verification gates (Sections 2-14)

**All 12 cross-module verification gates (Sections 2-14 of the directive) are BLOCKED.**

| Section | Title | Status |
|---------|-------|--------|
| 2 | Cross-module data isolation | **🔴 BLOCKED** |
| 3 | Cross-module customer AR isolation | **🔴 BLOCKED** |
| 4 | Group debt isolation | **🔴 BLOCKED** |
| 5 | Cross-module supplier/AP | **🔴 BLOCKED** |
| 6 | Multi-currency cross-module matrix | **🔴 BLOCKED** |
| 7 | Shared finance/ledger invariants | **🔴 BLOCKED** |
| 8 | Cross-module refund/cancel/delete | **🔴 BLOCKED** |
| 9 | Idempotency across modules | **🔴 BLOCKED** |
| 10 | True HTTP cross-module concurrency | **🔴 BLOCKED** |
| 11 | Authorization/IDOR across tourism | **🔴 BLOCKED** |
| 12 | Frontend ↔ API ↔ DB final contract | **🔴 BLOCKED** |
| 13 | Failure injection / transaction atomicity | **🔴 BLOCKED** |
| 14 | Reporting / reconciliation | **🔴 BLOCKED** |

**Why blocked** (evidence):

### 4.a Definitive evidence — Hajj/Umra and Visa service load failure

```
$ php -l app/Services/HajjUmra/HajjUmraBookingService.php
Parse error: syntax error, unexpected token "<<" in
app/Services/HajjUmra/HajjUmraBookingService.php on line 359
Errors parsing app/Services/HajjUmra/HajjUmraBookingService.php

$ php -l app/Services/Visa/VisaBookingService.php
Parse error: syntax error, unexpected token "<<" in
app/Services/Visa/VisaBookingService.php on line 401
Errors parsing app/Services/Visa/VisaBookingService.php

$ php -r "new \App\Services\HajjUmra\HajjUmraBookingService(...);"
HAJJ FAIL: syntax error, unexpected token "<<"

$ php -r "new \App\Services\Visa\VisaBookingService(...);"
VISA FAIL: syntax error, unexpected token "<<"
```

### 4.b Conflict marker locations

`app/Services/HajjUmra/HajjUmraBookingService.php` (992 lines):
- **Line 359:** `<<<<<<< Updated upstream` — opening marker of first unresolved conflict (inside `update()` method body)
- **Line 364:** `=======` — separator
- **Line 516:** `>>>>>>> Stashed changes` — closing marker of first conflict
- **Line 804:** `<<<<<<< Updated upstream` — opening marker of second unresolved conflict (inside payment code)
- **Line 821:** `=======` — separator
- **Line 822:** `>>>>>>> Stashed changes` — closing marker of second conflict

`app/Services/Visa/VisaBookingService.php` (721 lines):
- **Line 401:** `<<<<<<< Updated upstream`
- **Line 600:** `=======`
- **Line 625:** `>>>>>>> Stashed changes`

### 4.c Empirical evidence — Hajj test suite blocked

`tests/Feature/HajjUmra/HajjUmraAdminFullLifecycleTest.php` — **21/21 tests fail** with the same root cause: controller route instantiation triggers autoload of `HajjUmraBookingService`, which throws `ParseError`. Result:

```
Tests: 21 failed
Duration: 5.69s
```

Example failure (the test that triggers the parse error):

```
ParseError
  at tests/Feature/HajjUmra/HajjUmraAdminFullLifecycleTest.php:492
  'idempotency_key' => 'P102_DEL_'.uniqid(),
  ])->assertCreated();
```

The parse error prevents all Hajj/Umra and Visa booking lifecycle operations from executing. Cross-module scenarios that depend on these modules cannot be exercised through the canonical service paths.

### 4.d Test files also carry merge markers

```
$ grep -c "<<<<<<< " tests/Feature/HajjUmra/HajjUmraApiTest.php
1
$ grep -c "<<<<<<< " tests/Feature/HajjUmra/HajjUmraControllerTest.php
1
$ grep -c "<<<<<<< " tests/Feature/HajjUmra/HajjUmraProductionE2ETest.php
5
```

These three test files are also in an unresolved-merge state. Even if the service files were loadable, Hajj/Umra test surface is partially unrunnable.

### 4.e What CAN still be verified (Flight-only cross-module)

| Check | Status | Note |
|-------|--------|------|
| Flight `FlightPaymentNoDoubleIncomeTest` | **✅ PASS** (4/4, 21 assertions) | Confirms flight booking→payment→ledger→treasury path works |
| Flight services lint | **✅ OK** | All `app/Services/Flight/*` parse cleanly |
| Finance services lint | **✅ OK** | All `app/Services/Finance/*` parse cleanly |
| `TransactionService::recordJournalTransfer` | **✅ loadable** | The canonical double-entry GL writer for the entire Tourism division is functional |
| `PrepaidLedgerService` (Phase-1v2 airline prepaid) | **✅ loadable** | Critical for Flight ledger reconciliation |
| `RefundAuditLogger` (Phase 8.5 dual audit contract) | **✅ loadable** | For refunds in all three modules |

A real cross-module Flight→Finance path can therefore be exercised end-to-end (booking → journal → ledger → treasury). However, the Hajj/Umra and Visa cross-module paths are blocked.

---

## 17. Section 17 — Defect classification

| ID | Class | Defect | Evidence | Section |
|----|-------|--------|----------|---------|
| **D-INFRA-1** | **A** (production-safety violation) | `app/Services/HajjUmra/HajjUmraBookingService.php` has 2 unresolved Git merge conflict markers causing `ParseError: syntax error, unexpected token "<<"` at PHP parse time | `php -l` output; direct autoload throw; `HajjUmraAdminFullLifecycleTest` 21/21 fails | §1 |
| **D-INFRA-2** | **A** (production-safety violation) | `app/Services/Visa/VisaBookingService.php` has 1 unresolved Git merge conflict marker causing the same `ParseError` | `php -l` output; direct autoload throw | §1 |
| D-INFRA-3 | C (test-harness) | `tests/Feature/HajjUmra/HajjUmraApiTest.php`, `HajjUmraControllerTest.php`, `HajjUmraProductionE2ETest.php` carry `<<<<<<<` markers | `grep -c "<<<<<<< "` output | §1 |

### Pre-existing merge conflicts — context

The merge markers are leftovers from audit branches that modified the same service files in parallel:

- **HajjUmraBookingService** conflict #1 (line 359) sits in the `update()` method body. Both branches attempt to enforce the Phase-8.5 no-edit contract (the "Updated upstream" branch) and a "BUG-FIX 2026-07-27" state-machine guard.
- **HajjUmraBookingService** conflict #2 (line 804) sits in the payment-write block. One branch implements the Phase-10.2 cross-currency guard; the other branch ("Stashed changes") implements a latent-bug fix.
- **VisaBookingService** conflict (line 401) sits inside a method body as well.

These are *real* conflicts that need a human to reconcile which fix prevails. The Phase 9 and Phase 10 audits each merged their own changes onto their own branches; the current `phase-10-tourism-production-audit-hajj-umra` working tree has accumulated them without resolution.

### Fixes made (this audit)

**None.** The directive explicitly forbids modifying these files:

> *"Do NOT modify unrelated Hajj/Umra or Visa code merely because pre-existing merge conflicts exist."*

Resolving these merges would:
1. Pick winners between competing fixes (e.g., the cross-currency guard from Phase 10.2 vs the latent-bug fix from "Stashed changes") without regression coverage from the audit,
2. Potentially re-introduce a previously-fixed defect if the wrong branch wins,
3. Constitute a business-logic change made only to make the test environment work — explicitly forbidden by the directive.

Therefore the audit **does not make any code modifications** and **stops at the circuit-breaker**.

---

## 18. Remaining Class-C risks

| # | Item | Severity | Note |
|---|------|----------|------|
| 1 | `defaultEmployeeModules()` grants `manage_hajj` / `manage_online` / `manage_refunds` to **all** employees by default | C | Phase 9.11 + Phase 10.11 documentation. Operator awareness only. |
| 2 | Tourism-division accounts allow cross-module usage (`AccountModuleContract::TOURISM_DIVISION_MODULES` groups Hajj + Visa + Flights into one division) | C | By-design (INCIDENT 2026-08-17). Cross-module liquidity sharing is intentional. |
| 3 | Cross-currency withdraw/repay for Hajj/Umra executing-company has no FX guard | C | Phase 10.12 documented; same class as D2 fix but for non-payment flow. **Cannot retest after merge resolution without re-audit.** |
| 4 | True-HTTP-concurrency stress scripts (C1–C4) are gated by `StressSafetyGuard` and require `safarak_stress` MySQL DB on port 18000 | C | Out of scope for this audit run on `:memory:` SQLite |
| 5 | No reactivation endpoint for Cancelled/Refunded bookings | C | By-design (no-edit contract) |
| 6 | The state machines are implicit (guards inside service methods, no extracted transition table) | C | Works today but brittle |
| 7 | **The pre-existing merge conflicts (D-INFRA-1 / D-INFRA-2) are themselves the only Class-A finding of this audit** | A | The audit cannot certify Tourism production-readiness until they are resolved and the regression suites for Hajj/Umra + Visa are re-run on top of the resolution |

### Class-A recap

> *"Any Class-A/B blocks final GO."*

Two Class-A production-safety violations (D-INFRA-1, D-INFRA-2) are present.

---

## 19. Required remediation to reach TOURISM-WIDE GO

These actions **are out of scope for this audit** but are required before the audit can be retried. None of them is a code modification made by the auditor.

1. **Resolve the merge conflicts** in `app/Services/HajjUmra/HajjUmraBookingService.php` (2 conflicts) and `app/Services/Visa/VisaBookingService.php` (1 conflict).
   - For Hajj conflict #1 (line 359): the Phase-8.5 no-edit contract body and the BUG-FIX 2026-07-27 state guard are functionally complementary (both block editing terminal-state bookings). The merger should reconcile both controls — recommend keeping both, since the no-edit block and state-machine block operate at different layers.
   - For Hajj conflict #2 (line 804): the Phase-10.2 cross-currency guard is a regression-tested Class-B fix. The "Stashed changes" latent-bug fix appears to be a separate code path. The merger should integrate both — the Phase-10.2 guard must remain.
   - For Visa conflict (line 401): review against `app/Services/Visa/VisaRefundService.php` and `app/Services/Visa/VisaModificationService.php` to determine which branch preserves Phase 9 (Visa) fixes (9.5a, 9.8, 9.11, 9.12).
2. **Resolve the test-file merge conflicts** in `HajjUmraApiTest.php`, `HajjUmraControllerTest.php`, `HajjUmraProductionE2ETest.php`.
3. **Re-run the audit** on the resolved working tree. Section 2 through Section 16 can then be re-executed in full.

---

## 20. Final GO / NO-GO

```
🟢 TOURISM-WIDE GO   — requires all mandatory gates pass with:
                       0 Class-A • 0 Class-B • no unexplained variance
                       no cross-module contamination • no security/IDOR
                       no concurrency corruption • no production-safety
                       violation • full regression passes

🔴 TOURISM-WIDE NO-GO — stop and produce this report.
```

This audit finds:

- **2 Class-A production-safety violations** (D-INFRA-1, D-INFRA-2) — unresolved Git merge conflict markers in `app/Services/HajjUmra/HajjUmraBookingService.php` (line 359, 804) and `app/Services/Visa/VisaBookingService.php` (line 401) that prevent PHP parsing and autoload.
- **3 additional test-file conflict markers** (HajjUmraApiTest, HajjUmraControllerTest, HajjUmraProductionE2ETest).
- **0 verified cross-module gates** (Sections 2-14 cannot run on Hajj/Umra or Visa paths).
- **0 code modifications made by this audit** (per directive: do not modify unrelated Hajj/Visa code).

Per the directive's circuit-breaker:

> *"STOP immediately on: ... production-safety violation"*
> *"🔴 TOURISM-WIDE NO-GO and stop."*

---

# 🔴 TOURISM-WIDE NO-GO

The Tourism division is **NOT certified for production** at this audit boundary.

**Reason:** Two critical services in the Hajj/Umra and Visa modules are in an unparseable state due to unresolved Git merge conflicts. The audit's cross-module verification (Sections 2-14) cannot exercise those modules' canonical service paths, which is itself a production-safety violation.

**Remediation path:** Resolve the three service-file merge conflicts and three test-file merge conflicts, then re-run this audit. All per-module regression suites (Flight 38 tests + Hajj/Umra ~200 tests + Visa ~204 tests from Phases 9/10/11) must execute and pass against the resolved tree, and Sections 2-14 of this directive must then be re-executed end-to-end.

**Audit stopped:** 2026-08-20
**Stop reason:** Class-A production-safety violation (unresolved Git merge conflicts in `app/Services/HajjUmra/HajjUmraBookingService.php` and `app/Services/Visa/VisaBookingService.php`).
**Verdict:** **🔴 TOURISM-WIDE NO-GO**

— End of Tourism-Wide Final Certification.
