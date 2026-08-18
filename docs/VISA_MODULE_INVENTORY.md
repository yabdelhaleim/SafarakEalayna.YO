# Visa Module — Complete Inventory

> **Generated**: 2026-08-14 (Audit Start Date)
> **Project**: SafarakEalayna — Laravel 11/12
> **Module Path**: `app/Services/Visa/`, `app/Models/Visa*.php`, `app/Filament/Admin/Resources/Visas*/`
> **Audit Goal**: Full E2E verification of Frontend → Backend → DB → Finance/GL pipeline

---

## 1. Executive Summary

The Visa Module is a **fully implemented production module** with 11 migrations, 5 dedicated services, 6 API controllers, 5 Filament resources, 8 Vue views, and 1 Pinia store. It is **substantially already audited** through:

- `scripts/visa_module_full_e2e.php` — 16 E2E scenarios (T1-T16)
- `visa_module_production_full_test.php` — production validation
- `tests/Feature/Visa/*` — 7 PHPUnit feature test files (43KB largest)
- `tests/e2e/visa_*.php` + `tests/scripts/visa_*.php` — additional scripts

**This audit extends existing coverage** by filling the 11 gaps identified below.

---

## 2. Module Inventory

### 2.1 Database Tables (5 core + 2 reference)

| Table | Purpose | Key Columns | Soft Deletes |
|---|---|---|---|
| `visa_details` | Master visa application record | `visa_type`, `country`, `duration`, `entry_type`, `visa_agent_id`, `visa_duration_id`, `status`, `visa_number`, `validity_from/to`, `submission_date`, `expected_result_date`, `executing_*` | Yes |
| `visa_bookings` | Booking transaction | `customer_id`, `visa_detail_id`, `module`, `purchase_price`, `selling_price`, `service_fee`, `profit`, `currency`, `status`, `account_id`, `expense_transaction_id`, `income_transaction_id`, `employee_id`, `created_by` | Yes |
| `visa_payments` | Payment records | `visa_booking_id`, `amount`, `currency`, `payment_method`, `account_id`, `transaction_id`, `transaction_reference`, `payment_date`, `paid_by` | Yes (added 2026-07-11) |
| `visa_agents` | Executing agents (supplier-equivalent) | `company_name`, `contact_person`, `phone`, `email`, `country`, `visa_type`, `default_cost_price`, `account_id`, `is_active` | Yes |
| `visa_durations` | Reference durations | `code` (unique), `label_ar/en`, `months`, `entry_type`, `sort_order`, `is_active` | No |
| `account_entries` (shared) | GL double-entry rows | `transaction_id`, `account_id`, `debit`, `credit`, `notes`, `currency` | No |
| `transactions` (shared) | Journal entry headers | `type`, `module`, `amount`, `from_account_id`, `to_account_id`, `related_type`, `related_id`, `currency`, `notes` | No |

### 2.2 Backend Services

| Service | Responsibility | File |
|---|---|---|
| `VisaBookingService` | Create/Update/Find/Paginate/AddPayment/AddDebtPayment (happy path) | `app/Services/Visa/VisaBookingService.php` |
| `VisaRefundService` | Cancel/Refund/DeleteWithReversal (financial reversals) | `app/Services/Visa/VisaRefundService.php` |
| `VisaModificationService` | RepostExpense/RepostIncome (additive re-posting on price changes) | `app/Services/Visa/VisaModificationService.php` |
| `VisaTreasuryService` (via VisaTreasuryController) | Treasury overview, account transactions | controller delegates |
| `TransactionService` (shared) | recordExpense/recordIncome/recordTransfer/reverseTransaction | `app/Services/Finance/TransactionService.php` |

### 2.3 API Controllers

| Controller | Routes | File |
|---|---|---|
| `VisaBookingController` | CRUD + cancel/refund/destroy/payments/modifications | `app/Http/Controllers/Api/V1/Visa/VisaBookingController.php` |
| `VisaAgentApiController` | Agent CRUD + cost-price lookup | `app/Http/Controllers/Api/V1/Visa/VisaAgentApiController.php` |
| `VisaAgentFinanceController` | Dues/Withdraw/Repay | `app/Http/Controllers/Api/V1/Visa/VisaAgentFinanceController.php` |
| `VisaTreasuryController` | Overview/AccountTransactions | `app/Http/Controllers/Api/V1/Visa/VisaTreasuryController.php` |
| `VisaController` (legacy) | CustomerBalances/CustomerStatement/PayCustomerDebt | `app/Http/Controllers/Api/V1/VisaController.php` |
| `HajjUmraReferenceController` | Settings: agents/durations/statuses | `app/Http/Controllers/Api/V1/HajjUmraReferenceController.php` |

### 2.4 API Routes Inventory

**Public-ish (auth + active):**
- `GET  /api/v1/visa/settings/agents`
- `GET  /api/v1/visa/settings/durations`
- `GET  /api/v1/visa/settings/statuses`
- `GET  /api/v1/visa/treasury/overview`
- `GET  /api/v1/visa/treasury/accounts/{account}/transactions`
- `GET  /api/v1/visa/agents/dues`
- `GET  /api/v1/visa/bookings` (paginated, filterable)
- `POST /api/v1/visa/bookings` (create)
- `GET  /api/v1/visa/bookings/{visa}` (show)
- `PUT/PATCH /api/v1/visa/bookings/{visa}` (update)
- `POST /api/v1/visa/bookings/{visa}/payments`
- `GET  /api/v1/visa/bookings/{visa}/modifications` (history)
- `GET  /api/v1/visa/customer-balances`
- `GET  /api/v1/visa/customer-statement`

**Admin-only:**
- `POST /api/v1/visa/agents/{agent}/withdraw`
- `POST /api/v1/visa/agents/{agent}/repay`
- `DELETE /api/v1/visa/bookings/{visa}` (admin-only — soft delete + reversal)
- `POST /api/v1/visa/bookings/{visa}/cancel` (admin-only)
- `POST /api/v1/visa/bookings/{visa}/refund` (admin-only)
- `POST /api/v1/visa/customers/{customer}/pay-debt` (admin-only)

### 2.5 Filament Resources (Admin UI)

- `VisaCluster` — root navigation cluster (التأشيرات)
- `VisaBookingResource` — list/create/edit/view visa bookings
- `VisaAgentResource` — list/edit executing agents (with statement + debts actions)
- `VisaDurationResource` — manage durations reference
- `VisaBankAccountResource` — bank accounts filtered to `module_type='visas'`
- `VisaWalletResource` — wallet accounts filtered to `module_type='visas'`
- `VisaAgentDebtStatement` — custom Filament page for paying agent debts

### 2.6 Frontend (Vue SPA)

**Views** (`resources/js/views/visa/`):
- `VisaIndex.vue` — list with stats cards, filters, pagination
- `VisaCreate.vue` — 5-step wizard
- `VisaEdit.vue`, `VisaShow.vue`, `VisaDetails.vue`
- `VisaAgentsFinance.vue` — agent finance dashboard
- `VisaTreasury.vue` — treasury view
- `VisaCustomerBalances.vue` — customer balances/statement

**Store** (`resources/js/stores/visaStore.js`):
- 19 actions: `fetchBookings`, `fetchBookingById`, `createBooking`, `updateBooking`, `cancelBooking`, `deleteBooking`, `addPayment`, `fetchCustomers`, `createCustomer`, `createVisaAgent`, `fetchSettings`, `fetchAccounts`, `fetchVisaTreasuryOverview`, `fetchAccountVisaTransactions`, `fetchVisaAgentsDues`, `recordVisaAgentWithdraw`, `recordVisaAgentRepay`, `fetchVisaCustomerBalances`, `fetchVisaCustomerStatement`, `payVisaCustomerDebt`

### 2.7 Enums

- `VisaType`: Tourist, Business, Visit, Transit, Work, Student, Umrah, Hajj, Residence (9 types)
- `VisaStatus`: Draft, Submitted, UnderReview, Approved, Rejected, Issued, Cancelled, Refunded (8 statuses)
- `VisaEntryType`: Single, Multiple, Triple
- `VisaPaymentMethod`: (likely cash/bank_transfer/card/wallet — read from Filament form)
- `TransactionModule::Visa` — for GL tagging

### 2.8 Models

- `App\Models\VisaBooking` — uses `SoftDeletes`, `ModelDeletionGuard`, `ModelProfitMutationGuard`
- `App\Models\VisaDetail` — `visaDetail()` relation, belongs to `VisaAgent`, `VisaDuration`
- `App\Models\VisaPayment` — `SoftDeletes` (added 2026-07-11)
- `App\Models\HajjUmra\VisaAgent` — has `account_id`, `default_cost_price`
- `App\Models\HajjUmra\VisaDuration`

### 2.9 Observers / Guards

- `App\Observers\VisaAgentObserver` — auto-creates Supplier-type Account when `VisaAgent` created without `account_id`
- `App\Support\Finance\ModelDeletionGuard` (trait on `VisaBooking`) — blocks raw `$booking->delete()` except inside canonical paths
- `App\Support\Finance\ModelProfitMutationGuard` (trait on `VisaBooking`) — blocks direct `profit` column writes
- `App\Support\Finance\LedgerBalanceMutationGuard` — required for any direct `Account.balance` writes

---

## 3. Test Coverage Matrix (Existing vs Requested Phases)

| Phase | Existing Coverage | Gap | New Test File |
|---|---|---|---|
| **P1** Module Inventory | ✅ Built in this document | — | — |
| **P2** CRUD Testing | ✅ T1-T5 in script + BookingControllerTest | Adequate | — |
| **P3** Validation & Security | ⚠️ Partial (some 422 checks) | Comprehensive null/empty/invalid IDs/long strings/Arabic/Unicode | **VisaValidationTest.php** |
| **P4** Business Flows (status transitions) | ❌ No explicit transition tests | Test all 8 statuses + valid/invalid transitions | **VisaStatusTransitionTest.php** |
| **P5** Complete Financial Testing | ✅ T2, T6, T8-T11 | Adequate | — |
| **P6** Customer Debt 10K→4K→2K→4K scenario | ⚠️ Partial (T6 covers partial scenarios) | Exact 10K scenario from prompt | **VisaCustomerDebtScenarioTest.php** |
| **P7** Idempotency / Double Submission | ❌ Not tested | Double-click/refresh/dup API | **VisaIdempotencyTest.php** |
| **P8** Concurrency | ❌ Not tested | DB::transaction/lockForUpdate/race conditions | **VisaConcurrencyTest.php** |
| **P9** Refund/Cancellation | ✅ T10, T11 | Adequate | — |
| **P10** Rollback | ❌ Not tested | Mid-operation failure recovery | **VisaRollbackTest.php** |
| **P11** Database Integrity | ❌ Not tested (will be new audit script) | FK/duplicate/orphan/balance reconciliation | **audit_visa_db_integrity.php** |
| **P12** GL Reconciliation | ⚠️ Partial (per-tx balanced in script) | Comprehensive balance = SUM(credit) − SUM(debit) per account | **VisaLedgerReconciliationTest.php** |
| **P13** Frontend E2E | ❌ Not tested | Per user: API + Vue store validation only | **VisaApiContractTest.php** (API) |
| **P14** API Contract | ⚠️ Partial (BookingControllerTest) | Full envelope, all HTTP statuses, pagination, numeric types | **VisaApiContractTest.php** |
| **P15** Permissions | ❌ Not tested | Admin/Manager/Employee/ReadOnly/Unauth per endpoint | **VisaPermissionTest.php** |
| **P16** Edge Cases | ⚠️ Partial | 0 EGP, 0.01, very large, negative, Unicode | **VisaEdgeCasesTest.php** |
| **P17** Performance/Stress | ❌ Not tested | Bulk creation/load | **VisaPerformanceTest.php** |
| **P18** Regression | n/a (depends on findings) | Per-bug regression tests | TBD |

---

## 4. Existing Test Artifacts (NOT modified)

| File | Type | Coverage |
|---|---|---|
| `scripts/visa_module_full_e2e.php` | Standalone PHP | T1-T16 (16 scenarios, ~1700 lines) |
| `visa_module_production_full_test.php` | Standalone PHP | Production validation |
| `tests/Feature/Visa/VisaBookingControllerTest.php` | PHPUnit | Index/Store/Show/Update/Payment/Cancel/Refund/Modifications/Destroy |
| `tests/Feature/Visa/VisaAgentApiControllerTest.php` | PHPUnit | Agent CRUD |
| `tests/Feature/Visa/VisaAgentFinanceControllerTest.php` | PHPUnit | Dues/Withdraw/Repay |
| `tests/Feature/Visa/VisaBookingServiceDeadCodeTest.php` | PHPUnit | Service-layer invariants |
| `tests/Feature/Visa/VisaControllerTest.php` | PHPUnit | Legacy controller |
| `tests/Feature/Visa/VisaProductionE2ETest.php` | PHPUnit | Full production E2E (43KB) |
| `tests/Feature/Visa/VisaTreasuryControllerTest.php` | PHPUnit | Treasury endpoints |

---

## 5. Financial Integration Map

```
[Create Booking]
  ↓
  Customer.resolveCustomer() — Customer auto-created if phone matches
  ↓
  VisaDetail.create()  (master)
  ↓
  VisaBooking.create() — uses ModelProfitMutationGuard, ModelDeletionGuard
  ↓
  Customer.ensureCustomerAccount() — AR ledger Account auto-created/re-tagged to 'visas'
  ↓
  TransactionService.recordExpense(purchase_price) — debit Agent (or vault fallback), credit Vault (or cashbox)
  ↓
  TransactionService.recordIncome(selling_price + service_fee) — credit Customer AR
  ↓
  [Optional] addPayment(amount) — recordIncome(cashbox) + create VisaPayment row

[Update Booking] (price change only)
  ↓
  VisaModificationService.repostExpense() — additive reversal entries on original txn_id + new expense txn
  ↓
  VisaModificationService.repostIncome() — same pattern for income
  ↓
  Booking.expense_transaction_id / income_transaction_id updated to new IDs

[Cancel Booking]
  ↓
  VisaRefundService.cancel() — additive reversals on all original txns + status=cancelled

[Refund Booking]
  ↓
  VisaRefundService.refund() — additive reversals + status=refunded

[Delete Booking]
  ↓
  VisaRefundService.deleteWithReversal() — additive reversals + soft delete (cascades payments)
```

**Key invariants:**
- `Account.balance = SUM(credit) − SUM(debit)` (project convention; opposite of standard double-entry)
- `paid_amount` is computed property (NOT stored) — sum of all `visa_payments.amount` for the booking
- `remaining_amount = max(0, selling_price + service_fee − paid_amount)`
- All transactions have `module = TransactionModule::Visa->value`
- All transactions have `related_type = VisaBooking::class`, `related_id = booking->id`
- Visa reversals are **additive** (never modify original `Transaction.amount` or `AccountEntry.debit/credit`)

---

## 6. Known Bugs Already Fixed (during prior audits)

The codebase contains BUG-FIX comments dated 2026-07-27 addressing:
1. ✅ Cancelled booking update blocked (was creating phantom transactions)
2. ✅ Payment on cancelled booking blocked (was corrupting ledger)
3. ✅ Payment on trashed booking blocked
4. ✅ Payment on refunded booking blocked
5. ✅ Overpayment rejected in `addPayment()`
6. ✅ Repost pattern changed from mutation to additive reversal (mirrors HajjUmra fix)
7. ✅ `addDebtPayment()` rewritten — was creating Transactions without AccountEntries, dropping `account_id` silently
8. ✅ Customer account `module_type` re-tagged to `'visas'` when first used in visa flow

These are already tested in `scripts/visa_module_full_e2e.php` T16.1-T16.4 (guard tests).

---

## 7. New Test Files To Build (this audit)

```
tests/Feature/Visa/
  VisaTestCase.php                    ← base class
  VisaStatusTransitionTest.php        ← P4: 8 statuses + transitions
  VisaCustomerDebtScenarioTest.php    ← P6: 10K→4K→2K→4K
  VisaIdempotencyTest.php             ← P7: double-submit/refresh/dup API
  VisaConcurrencyTest.php             ← P8: DB locks + race conditions
  VisaRollbackTest.php                ← P10: mid-operation failure
  VisaValidationTest.php              ← P3: null/empty/long/Unicode
  VisaEdgeCasesTest.php               ← P16: 0/0.01/large/negative/Unicode
  VisaApiContractTest.php             ← P14: envelope + statuses + pagination
  VisaPermissionTest.php              ← P15: admin/manager/employee/readonly
  VisaLedgerReconciliationTest.php    ← P12: GL balance invariants
  VisaPerformanceTest.php             ← P17: bulk creation
```

```
root/
  audit_visa_module_full.php          ← standalone PHP, 18 scenarios
  audit_visa_db_integrity.php         ← FK/orphan/balance reconciliation
  VISA_MODULE_FULL_AUDIT_20260814.md  ← final report
```

---

## 8. Test Data Strategy

- **PHPUnit tests**: SQLite `:memory:` per test (via `RefreshDatabase` trait) — fully isolated, no cleanup needed
- **Standalone audit scripts**: local DB only (NEVER production — pre-flight check via `DB::connection()->getDatabaseName()`)
- **Test data tagging**: All audit script records tagged `notes LIKE 'Visa Audit 2026-08-14 %'` for bulk cleanup
- **Cleanup hook**: Each audit script's final block force-deletes in FK order (transactions → entries → payments → bookings → visa_details → customers → agents → accounts)

---

## 9. Boundary Constraints (DO NOT TOUCH during audit)

- Production code outside explicit bug fixes
- Production DB / `.env` (verified by `APP_ENV !== 'production'` pre-flight check)
- Existing passing tests (`scripts/visa_module_full_e2e.php`, `tests/Feature/Visa/*`)
- Filament resources (read-only verification, no UI changes)

---

## 10. Next Phase

Proceed to build:
1. `tests/Feature/Visa/VisaTestCase.php` (base class)
2. The 11 new feature test files (gap fillers)
3. `audit_visa_module_full.php` (master audit)
4. `audit_visa_db_integrity.php` (DB integrity)
5. Run everything
6. Fix any bugs found (with regression tests)
7. Generate final report `VISA_MODULE_FULL_AUDIT_20260814.md`