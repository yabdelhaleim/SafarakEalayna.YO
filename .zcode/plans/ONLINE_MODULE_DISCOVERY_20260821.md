# ONLINE MODULE — DISCOVERY INVENTORY
**Branch:** phase-10-tourism-production-audit-hajj-umra
**Date:** 2026-08-21
**Audit Lifecycle:** DISCOVER → MAP → UNDERSTAND → IDENTIFY → TRACE → EXECUTE → VERIFY

---

## A. MODULE IDENTITY

| Field | Value |
|---|---|
| Module name (code) | `Online` |
| Arabic name | الخدمات الإلكترونية |
| English label | Online Services / Online |
| Business purpose | Record & track customer electronic-service transactions (e.g. visas-online, stamps, attestations, training courses, gov services, customs clearance). Each transaction has purchase price (cost), selling price (revenue), profit, customer (registered or walk-in), provider, employee, payment method, vault account, and status (pending/completed/failed/cancelled). The module manages customer debts and treasury effects via the shared `transactions`/`account_entries` ledger. |
| Currency policy | **EGP-only** (Phase 10 cross-currency guard rejects non-EGP vault/AR). |
| Sole permission | `manage_online` (`App\Support\UserPermissions::MANAGE_ONLINE`). |

---

## B. REAL MODULE INVENTORY

### B.1 Backend — `app/Models/Online/`
| File | Class | Table | Notes |
|---|---|---|---|
| `OnlineTransaction.php` | `App\Models\Online\OnlineTransaction` | `online_transactions` | Central fact table. Uses `SoftDeletes, ClearsCache, ModelDeletionGuard, ModelProfitMutationGuard`. `booted()` observer blocks external profit writes (only via `runProfitMutation`), auto-computes profit, blocks direct `$tx->delete()` outside the canonical service. |
| `OnlineServiceType.php` | `App\Models\Online\OnlineServiceType` | `online_service_types` | Master data: code, name_ar/en, color, icon, is_active, order. `scopeActive`, accessor `getNameAttribute()`. |
| `OnlineServiceProvider.php` | `App\Models\Online\OnlineServiceProvider` | `online_service_providers` | Master data with `default_purchase_account_id` FK → `accounts.id`. `metadata` JSON cast. `scopeActive`. |

### B.2 Backend — `app/Http/Controllers/Api/V1/Online/`
| Controller | Endpoints |
|---|---|
| `OnlineSettingsController` | `serviceTypes / providers / paymentMethods / accounts / customers / employees / statuses / all` (read-only aggregators). `accounts` filters `module_type IN (online, office)` & `type IN LIQUIDITY_TYPES`. `customers` filters `module_type='online'` OR has online transactions. |
| `OnlineCustomerController` | `POST /settings/customers` — uses `CustomerService`, forces `module_type='online'`, `type=Individual`. |
| `OnlineServiceTypeController` | Full CRUD via `OnlineServiceTypeService` + `active()` shortcut. |
| `OnlineServiceProviderController` | Full CRUD via `OnlineServiceProviderService` + `active()` shortcut. |
| `OnlineTransactionController` | List (cached 60s), create, show, update, destroy (`role:admin` middleware), `dailySummary`, `customerBalances`, `customerStatement`. |

### B.3 Backend — `app/Services/Online/`
| Service | Key responsibility |
|---|---|
| `OnlineTransactionService` | **Authoritative write path.** DB::transaction-wrapped create / update / delete. Posts 3 GL legs (income → AR mirror, cash settlement → vault, expense → provider vault). Handles status transitions additively. Walk-in AR reclamation (FIFO + credit memo). EGP-only guard. |
| `OnlineServiceTypeService` | Master CRUD; `delete` throws if transactions exist. |
| `OnlineServiceProviderService` | Master CRUD; `delete` throws if transactions exist. |
| `README.md` | Architectural contract document (deletion/reversal semantics). |

### B.4 Backend — `app/Http/Requests/Online/`
| Request | Rule highlights |
|---|---|
| `CreateOnlineCustomerRequest` | `full_name` required, `phone` nullable, `type` ∈ Individual/Company. |
| `StoreOnlineServiceTypeRequest` | `code` unique (normalized lowercase/underscore). `prepareForValidation` normalizes. |
| `UpdateOnlineServiceTypeRequest` | Same as store, `code` `sometimes` + unique ignore. |
| `StoreOnlineServiceProviderRequest` | `code` unique; `default_purchase_account_id` → exists in accounts. |
| `UpdateOnlineServiceProviderRequest` | Same, with `code` `sometimes`. |
| `StoreOnlineTransactionRequest` | service_type_id active; provider_id optional active; customer_id optional; customer_name required when no customer_id (walk-in); customer_phone required when no customer_id; purchase/selling_price numeric; amount_paid optional; payment_method required active code; account_id required active AND `OnlineLiquidityAccount` rule + `PaymentMethodAccountType::matches()`. `prepareForValidation` coerces IDs/strings. |
| `UpdateOnlineTransactionRequest` | All `sometimes`/`nullable`; same liquidity + payment-method validation; supports status transitions between `OnlineTransactionStatus` cases. |

### B.5 Backend — `app/Http/Resources/Online/`
| Resource | Returns |
|---|---|
| `OnlineServiceTypeResource` | id, code, names, descriptions, color, icon, is_active, order, `transactions_count`, created_by. |
| `OnlineServiceProviderResource` | id, code, names, descriptions, color, icon, contact_phone, contact_account, metadata, `default_purchase_account` + id, is_active, order. |
| `OnlineTransactionResource` | Full resource: service_type, provider, customer, employee, prices/profit, payment_method (+label/color), account, ref, status (+label/color), failure_reason, notes, expense/income_transaction (+eager), created_by. |

### B.6 Backend — Rules / Enums
- `app/Rules/OnlineLiquidityAccount.php` — accepts `module_type='online'`, `module='online'`, OR `module_type='office'` (unified office vault). Rejects bus/fawry/wallet_transfer/tourism, subject accounts, inactive.
- `app/Enums/OnlineTransactionStatus.php` — `Pending | Completed | Failed | Cancelled` with `label()`, `color()`.
- `app/Enums/TransactionModule.php` — `Online = 'online'` case (label `الخدمات الإلكترونية`).

### B.7 Backend — Filament Admin (admin UI, no API route)
| Resource | Pages | Notes |
|---|---|---|
| `OnlineTransactions/OnlineTransactionResource` | `ManageOnlineTransactions` + `OnlineStats` widget | EditAction → `OnlineTransactionService::update`; DeleteAction → `OnlineTransactionService::delete`. Form filters account_id to `module_type IN (online, office)`. Profit field disabled (auto-computed). |
| `OnlineServiceTypes/OnlineServiceTypeResource` | List/Create/Edit | Master CRUD. |
| `OnlineServiceProviders/OnlineServiceProviderResource` | List/Create/Edit | Master CRUD with contact + settlement sections. |
| `OnlineBankAccounts/OnlineBankAccountResource` | List/Create/Edit | Wrapper over shared `accounts` table (Bank type, module_type='online'). `shouldRegisterNavigation()=false`. |
| `OnlineWallets/OnlineWalletResource` | List/Create/Edit | Wrapper over shared `accounts` table (Wallet type, module_type='online'). `shouldRegisterNavigation()=false`. |
| `Concerns/BelongsToOnlineModuleNavigation` | — | Trait exposing `getNavigationLabel()` from `OnlineModuleNavigation::PARENT_LABEL`. |
| `Support/OnlineModuleNavigation` | — | Constants: `PARENT_LABEL = 'الخدمات الأونلاين'`, `NAVIGATION_GROUP = 'الخدمات الأونلاين'`. |

### B.8 Database — `database/migrations/`
| File | Operation |
|---|---|
| `2026_05_06_080000_redesign_online_module_tables.php` | CREATE/DROP: redesign drops legacy `online_transactions / online_service_types / online_services`; recreates 3 fresh tables with current schema. |
| `2026_06_03_201200_add_amount_paid_to_online_transactions.php` | ADD `amount_paid decimal(12,2) NULLABLE`. |
| `2026_06_24_132142_add_cancelled_status_to_online_transactions.php` | ALTER status enum → adds `'cancelled'` (driver-aware raw SQL). |
| `2026_07_27_122858_seed_online_module_accounts.php` | Originally seeded accounts; **now NO-OP** (data-seed disabled). |
| `2026_07_27_140000_add_cancelled_audit_to_online_transactions.php` | ADD `cancelled_by`, `cancelled_at` + composite index `online_tx_status_deleted_idx`. |

### B.9 Database — Tables (final shape)

#### `online_service_types`
| Column | Type | Default | Nullable |
|---|---|---|---|
| id | bigIncrements | — | no |
| code | string(80) UNIQUE | — | no |
| name_ar, name_en | string | — | no |
| description_ar, description_en | text | — | yes |
| color | string(20) | `'#6B7280'` | no |
| icon | string | — | yes |
| is_active | boolean | true | no |
| order | unsignedInteger | 0 | no |
| created_by | FK→users.id | — | yes |
| timestamps, softDeletes | | | |

Indexes: `code` (unique), `is_active`, `order`.

#### `online_service_providers`
| Column | Type | Default | Nullable |
|---|---|---|---|
| id | bigIncrements | — | no |
| code | string(80) UNIQUE | — | no |
| name_ar, name_en | string | — | no |
| description_ar, description_en | text | — | yes |
| color | string(20) | `'#6B7280'` | no |
| icon | string | — | yes |
| contact_phone, contact_account | string | — | yes |
| metadata | json | — | yes |
| default_purchase_account_id | FK→accounts.id | — | yes |
| is_active | boolean | true | no |
| order | unsignedInteger | 0 | no |
| created_by | FK→users.id | — | yes |
| timestamps, softDeletes | | | |

Indexes: `code` (unique), `is_active`, `order`, `default_purchase_account_id`.

#### `online_transactions` (central fact table)
| Column | Type | Default | Nullable |
|---|---|---|---|
| id | bigIncrements | — | no |
| service_type_id | FK→online_service_types.id | — | no |
| provider_id | FK→online_service_providers.id | — | yes |
| customer_id | FK→customers.id | — | yes |
| customer_name | string | — | yes |
| customer_phone | string | — | yes |
| customer_country | string | — | yes |
| employee_id | FK→employees.id | — | yes |
| purchase_price | decimal(12,2) | 0 | no |
| selling_price | decimal(12,2) | 0 | no |
| amount_paid | decimal(12,2) | — | yes (added 2026-06-03) |
| profit | decimal(12,2) | 0 | no |
| payment_method | string(80) | — | no |
| account_id | FK→accounts.id | — | no |
| reference_number | string | — | yes |
| expense_transaction_id | FK→transactions.id | — | yes |
| income_transaction_id | FK→transactions.id | — | yes |
| status | ENUM('pending','completed','failed','cancelled') | 'completed' | no |
| failure_reason | text | — | yes |
| cancelled_by | FK→users.id | — | yes (added 2026-07-27) |
| cancelled_at | timestamp | — | yes (added 2026-07-27) |
| notes | text | — | yes |
| created_by | FK→users.id | — | yes |
| timestamps, softDeletes | | | |

Indexes: `service_type_id, provider_id, customer_id, employee_id, payment_method, account_id, status`, `(status, deleted_at)`.

### B.10 Seeders
- `database/seeders/OnlineModuleProductionTestSeeder.php` — idempotent, NOT called from `DatabaseSeeder`. Seeds 6 service types (stamps / attestations / visas_online / training_courses / gov_services / customs_clearance), 5 providers (momtaz / etidal / masarat / etimad / absher), 5 customers (4 individuals + 1 company), 2 cashboxes (EGP 30,000 + USD 1,000 — module_type='office'), 6 payment methods (cash / bank_transfer / cash_wallet / postal_transfer / office_safe / office_drawer).

### B.11 Frontend — `resources/js/`
| File | Purpose |
|---|---|
| `views/online/OnlineIndex.vue` | Transaction list + daily summary cards (purchase/selling/profit) + filters + pagination. |
| `views/online/OnlineExecute.vue` | Create transaction form (service type, provider, customer, payment method, account, amounts). |
| `views/online/OnlineTreasury.vue` | Treasury / financial KPIs (wallet/bank/cashbox totals + accounts list). |
| `views/online/OnlineCustomerBalances.vue` | Customer debts / balances report (CSV export, stats cards, filters). |
| `views/online/OnlineServiceTypesIndex.vue` | Service Types CRUD UI. |
| `views/online/OnlineProvidersIndex.vue` | Service Providers CRUD UI. |
| `components/online/OnlineSummaryCard.vue` | Reusable KPI card. |
| `stores/onlineStore.js` | Pinia store: state, getters, actions for all master data + transaction CRUD + daily summary. |
| `router/index.js` (lines 393-452) | 6 routes under `/online`: `index / treasury / execute / customer-balances / service-types / providers`. |
| `layouts/DashboardLayout.vue` | Sidebar group "Online Module" (الخدمات الإلكترونية) with sub-items; gated by `manage_online` permission; active when path starts with `/online`. |

### B.12 API Routes (`routes/api.php` lines 404-450)
All under `/api/v1/online`, group middleware: `auth:sanctum, active, CaptureFinancialPostingContext, RejectBannedFinancialBypassMarkers`.

| Method | URI | Name | Action | Middleware |
|---|---|---|---|---|
| GET | `online/settings/all` | (implicit) | `OnlineSettingsController@all` | group |
| GET | `online/settings/service-types` | (implicit) | `OnlineSettingsController@serviceTypes` | group |
| GET | `online/settings/providers` | (implicit) | `OnlineSettingsController@providers` | group |
| GET | `online/settings/payment-methods` | (implicit) | `OnlineSettingsController@paymentMethods` | group |
| GET | `online/settings/accounts` | (implicit) | `OnlineSettingsController@accounts` | group |
| GET | `online/settings/customers` | (implicit) | `OnlineSettingsController@customers` | group |
| POST | `online/settings/customers` | (implicit) | `OnlineCustomerController@store` | group |
| GET | `online/settings/employees` | (implicit) | `OnlineSettingsController@employees` | group |
| GET | `online/settings/statuses` | (implicit) | `OnlineSettingsController@statuses` | group |
| GET | `online/service-types/active` | (implicit) | `OnlineServiceTypeController@active` | group |
| GET | `online/service-types` | `online_service_types.index` | `OnlineServiceTypeController@index` | group |
| POST | `online/service-types` | `online_service_types.store` | `OnlineServiceTypeController@store` | group |
| GET | `online/service-types/{onlineServiceType}` | `online_service_types.show` | `OnlineServiceTypeController@show` | group |
| PUT/PATCH | `online/service-types/{onlineServiceType}` | `online_service_types.update` | `OnlineServiceTypeController@update` | group |
| DELETE | `online/service-types/{onlineServiceType}` | `online_service_types.destroy` | `OnlineServiceTypeController@destroy` | group |
| GET | `online/providers/active` | (implicit) | `OnlineServiceProviderController@active` | group |
| GET | `online/providers` | `online_service_providers.index` | `OnlineServiceProviderController@index` | group |
| POST | `online/providers` | `online_service_providers.store` | `OnlineServiceProviderController@store` | group |
| GET | `online/providers/{onlineServiceProvider}` | `online_service_providers.show` | `OnlineServiceProviderController@show` | group |
| PUT/PATCH | `online/providers/{onlineServiceProvider}` | `online_service_providers.update` | `OnlineServiceProviderController@update` | group |
| DELETE | `online/providers/{onlineServiceProvider}` | `online_service_providers.destroy` | `OnlineServiceProviderController@destroy` | group |
| GET | `online/customer-balances` | (implicit) | `OnlineTransactionController@customerBalances` | group |
| GET | `online/customer-statement` | (implicit) | `OnlineTransactionController@customerStatement` | group |
| GET | `online/transactions/daily-summary` | (implicit) | `OnlineTransactionController@dailySummary` | group |
| GET | `online/transactions` | `online_transactions.index` | `OnlineTransactionController@index` | group |
| POST | `online/transactions` | `online_transactions.store` | `OnlineTransactionController@store` | group |
| GET | `online/transactions/{onlineTransaction}` | `online_transactions.show` | `OnlineTransactionController@show` | group |
| PUT/PATCH | `online/transactions/{onlineTransaction}` | `online_transactions.update` | `OnlineTransactionController@update` | group |
| DELETE | `online/transactions/{onlineTransaction}` | `online_transactions.destroy` | `OnlineTransactionController@destroy` | group + `role:admin` |

**29 API operations** in total.

### B.13 Permissions
- Sole Online permission: `manage_online` (`App\Support\UserPermissions::MANAGE_ONLINE`).
- Granted to: `admin` / `owner` by default. To `employee`: only via explicit `users.permissions` JSON column (deny-by-default after SEC-1 fix, 2026-08-21).
- Re-used at `routes/api.php:664-667` for Visa payment endpoints (intentional cross-permission — `manage_online` covers "Visas + Online Services").
- `defaultEmployeeModules()` in `UserPermissions` includes `MANAGE_ONLINE` (legacy grant — no longer auto-applied).

### B.14 Tests (`tests/Feature/`)
| File | Purpose |
|---|---|
| `Online/OnlineTestCase.php` | Base test class. Seeds 4 accounts (EGP cashbox/bank/wallet, USD cashbox, all module_type='office'), 1 service type, 1 provider, 1 admin user, 1 payment method. Helpers: `makeCustomer()`, `glBalance()`, `accountBalance()`, `assertLedgerBalancedForAccount()`, `assertOnlineLedgerBalanced()`. |
| `Online/OnlineTransactionBookingFlowTest.php` | 9 tests: full payment EGP, partial payment walk-in AR, walk-in creation, partial walk-in, cross-currency reject, edit selling reposts income, edit amount_paid reposts cash, status transitions (Completed↔Cancelled, Pending→Completed). |
| `Online/OnlineTransactionSoftDeleteTest.php` | 7 tests: cancel full payment returns balances, partial cancel restores debt, idempotency, direct delete throws (guard), soft-deleted visibility, audit fields, walk-in overpayment reclaim. |
| `Online/OnlineModuleProductionAuditTest.php` | Production audit: full lifecycle + double-entry equilibrium, soft-deleted update behavior. |
| `OnlineServicesApiCrudTest.php` | HTTP API contract: settings aggregation, full CRUD over service types / providers / transactions / daily-summary. |
| `OnlinePaymentAccountSelectionTest.php` | 4 tests: settings returns only office liquidity + matching method types; cash+bank rejected; tourism account rejected; partial update cash→bank_transfer rejected. |
| `TourismDivision/OnlineProductionTest.php` | Cross-module production test. |
| `scripts/online_module_*.php`, `scripts/online_api_e2e_test.sh` | Standalone E2E scripts (manual). |

### B.15 Cross-module integrations
- **`Account` (`app/Models/Account.php`)** — Online uses `module_type IN ('online','office')` for liquidity accounts. `LedgerClearingAccounts::onlineWalkInArAccountId()` creates AR mirror `ذمم عملاء الخدمات الإلكترونية غير مسجلين` (type=Customer, module_type='online', currency='EGP').
- **`Transaction` (`app/Models/Transaction.php`)** — Polymorphic `related_type = 'App\Models\Online\OnlineTransaction'`. Module-tagged via `module='online'`.
- **`AccountEntry` (`app/Models/AccountEntry.php`)** — Read for `customerStatement` (filtered by `transactions.module='online'`). No direct write path (writes go through `TransactionService`).
- **`Customer` (`app/Models/Customer.php`)** — `onlineTransactions()` relation. `module_type='online'` auto-set on creation via Online flow. Shares the SHARED `customers` table.
- **`Employee` (`app/Models/Employee.php`)** — FK on `online_transactions.employee_id`. `OnlineSettingsController::employees` returns active employees.
- **`User`** — FK on `created_by` and `cancelled_by`. `permissions` JSON may contain `manage_online`.
- **`Visa` (`routes/api.php:667`)** — `/api/v1/visa/.../payments` gated by `permission:manage_online` (cross-use by design).
- **`Wallet`, `Fawry`** — Sibling office-division modules sharing the same architectural pattern (LedgerClearingAccounts walk-in AR mirrors, FIFO reclamation).
- **`VerifySoftDeletes` console command** — asserts OnlineTransaction + OnlineServiceType use SoftDeletes trait.

### B.16 Unknowns / Requires trace
- `OnlineLiquidityAccount` rule allows both `module='online'` and `module_type='online'` AND `module_type='office'` — needs explicit test for each branch.
- `OnlineTransactionService::delete` runs the soft-delete inside `OnlineTransaction::run` (gate open) — but the `deleting` observer in the model `throws RuntimeException` outside the gate. The cancellation status flip + soft-delete is in step 3-4 of `delete()`. The `with_trashed` filter in `getAll()` was added in Phase 10. The Filament List page hides trashed rows by default.
- `defaultEmployeeModules()` still includes `MANAGE_ONLINE` (legacy grant). After SEC-1 fix the runtime `effectiveFor()` is deny-by-default for non-admin/non-owner. But the seeder-level default is still set — discrepancy worth auditing.
- `app/Services/Finance/LedgerClearingAccounts::onlineWalkInArAccountId()` was not opened in this pass — its body is referenced by tests but not read here.
- `Online*` Policies do not exist — authorization is purely middleware-based.
- `Online*` Events / Listeners / Jobs / Notifications / Mailables do not exist.

---

## C. OPERATION INVENTORY (29 operations)

| # | Operation | HTTP | Auth | Financial | Reversible |
|---|---|---|---|---|---|
| 1 | `GET /online/settings/all` | GET | auth | No | N/A |
| 2 | `GET /online/settings/service-types` | GET | auth | No | N/A |
| 3 | `GET /online/settings/providers` | GET | auth | No | N/A |
| 4 | `GET /online/settings/payment-methods` | GET | auth | No | N/A |
| 5 | `GET /online/settings/accounts` | GET | auth | No | N/A |
| 6 | `GET /online/settings/customers` | GET | auth | No | N/A |
| 7 | `POST /online/settings/customers` | POST | auth | No | N/A (creates customer only) |
| 8 | `GET /online/settings/employees` | GET | auth | No | N/A |
| 9 | `GET /online/settings/statuses` | GET | auth | No | N/A |
| 10 | `GET /online/service-types/active` | GET | auth | No | N/A |
| 11 | `GET /online/service-types` | GET | auth | No | N/A |
| 12 | `POST /online/service-types` | POST | auth | No | N/A (blocked if transactions exist) |
| 13 | `GET /online/service-types/{id}` | GET | auth | No | N/A |
| 14 | `PUT/PATCH /online/service-types/{id}` | PUT/PATCH | auth | No | N/A |
| 15 | `DELETE /online/service-types/{id}` | DELETE | auth | No | N/A (blocked if transactions exist) |
| 16 | `GET /online/providers/active` | GET | auth | No | N/A |
| 17 | `GET /online/providers` | GET | auth | No | N/A |
| 18 | `POST /online/providers` | POST | auth | No | N/A (blocked if transactions exist) |
| 19 | `GET /online/providers/{id}` | GET | auth | No | N/A |
| 20 | `PUT/PATCH /online/providers/{id}` | PUT/PATCH | auth | No | N/A |
| 21 | `DELETE /online/providers/{id}` | DELETE | auth | No | N/A (blocked if transactions exist) |
| 22 | `GET /online/customer-balances` | GET | auth | No (read-only aggregate) | N/A |
| 23 | `GET /online/customer-statement` | GET | auth | No (read-only ledger) | N/A |
| 24 | `GET /online/transactions/daily-summary` | GET | auth | No (read-only aggregate) | N/A |
| 25 | `GET /online/transactions` | GET | auth | No (read-only list) | N/A |
| 26 | `POST /online/transactions` | POST | auth | **YES** | Yes (cancel) |
| 27 | `GET /online/transactions/{id}` | GET | auth | No | N/A |
| 28 | `PUT/PATCH /online/transactions/{id}` | PUT/PATCH | auth | **YES** (reposts GL) | Yes (status transitions) |
| 29 | `DELETE /online/transactions/{id}` | DELETE | auth + role:admin | **YES** (reverses GL) | Reversal is the operation |

**Financially impactful operations: 26 (POST), 28 (PATCH), 29 (DELETE)**.

---

## D. FINANCIAL FLOW (extract from `OnlineTransactionService::create`)

```
INPUT  data = { service_type_id, provider_id?, customer_id?, customer_name?, customer_phone?,
                customer_country?, employee_id?, purchase_price, selling_price,
                amount_paid?, payment_method, account_id, reference_number?, status?,
                failure_reason?, notes? }

STEP 1  serviceType = OnlineServiceType::findOrFail(service_type_id)
        require serviceType.is_active = true            else: throw RuntimeException
STEP 2  provider = provider_id ? OnlineServiceProvider::find(provider_id) : null
STEP 3  data = ensureCustomerIsLinked(data)              # walk-in auto-creates Customer
STEP 4  (name, phone) = resolveCustomerNameAndPhone(data)
STEP 5  assertCurrencyCompatible(data)                   # EGP-only guard
STEP 6  purchase = data.purchase_price
        selling  = data.selling_price
        amountPaid = data.amount_paid ?? selling
        profit   = selling - purchase
        status   = OnlineTransactionStatus::tryFrom(data.status ?? 'completed')
STEP 7  DB::transaction →
          runProfitMutation() { OnlineTransaction::create({...profit=profit...}) }
STEP 8  if status == Completed:
          postFinancialEntries(tx, serviceType, provider, purchase, selling, customerName)

postFinancialEntries:
  (a) INCOME:  if selling > 0
        arAccountId = customer_id ? ensureCustomerAccount(customer_id).id
                                  : LedgerClearingAccounts::onlineWalkInArAccountId()
        TransactionService::recordIncome({
          amount         = selling,
          to_account_id  = arAccountId,
          module         = 'online',
          related_type   = OnlineTransaction::class,
          related_id     = tx.id,
          notes          = "تحصيل خدمة أونلاين...",
        })
        tx->income_transaction_id = $income->id

  (b) CASH SETTLEMENT:  if amount_paid > 0 AND tx.account_id
        TransactionService::recordJournalTransfer({
          amount              = amountPaid,
          from_account_id     = arAccountId,
          to_account_id       = tx.account_id,
          allow_from_negative = true,
          module              = 'online',
          related_type        = OnlineTransaction::class,
          related_id          = tx.id,
          notes               = "سداد جزئي...",
        })

  (c) EXPENSE:  if purchase > 0
        source = provider.default_purchase_account_id
              ?? (amountPaid > 0 ? tx.account_id : income_clearing_for('online'))
        TransactionService::recordExpense({
          amount          = purchase,
          from_account_id = source,
          module          = 'online',
          related_type    = OnlineTransaction::class,
          related_id      = tx.id,
          notes           = "تكلفة خدمة أونلاين...",
        })
        tx->expense_transaction_id = $expense->id

  $tx->save()
```

### Update flow (3 independent gates)

```
A. statusChanged AND originalStatus == Completed
   → reverse all Transaction{related_type=OnlineTransaction, related_id=tx.id}
B. statusChanged AND newStatus == Completed AND originalStatus != Completed
   → if no live (non-reversal) linked Transactions exist → postFinancialEntries
C. status == Completed AND (sellingChanged OR accountChanged OR customerChanged)
   → repostIncomeTransaction
   if status == Completed AND purchaseChanged
   → repostExpenseTransaction
   if status == Completed AND (amountPaidChanged OR accountChanged OR customerChanged)
   → repostCashPaymentTransaction
```

### Delete flow

```
1. Idempotency check: if already soft-deleted → return true
2. Re-read $tx (fresh values)
3. DeferredTransactionDeletionGuard::ensureNoLaterPayment(...)  # business rule
4. Reverse all linked Transactions (idempotent)
5. If walk-in (customer_id empty):
     reclaimWalkInArExcess(tx, customerName, vaultId):
       a) compute cancelledOverpayment + otherTxsOverpayment (FIFO)
       b) cap at walk-in AR's actual negative balance
       c) FIFO re-allocate to other walk-in txs for same name
       d) residual → recordJournalTransfer(vault → walk-in AR) [credit memo]
       e) zero out deleted tx.amount_paid
6. tx.status = Cancelled; stamp cancelled_by, cancelled_at; append failure_reason
7. Soft-delete via OnlineTransaction::run { $tx->delete() }
```

---

## E. RUNNING APP — TEST BASELINE

See `.zcode/plans/ONLINE_AUDIT_PROGRESS_20260821.md` for live progress log.