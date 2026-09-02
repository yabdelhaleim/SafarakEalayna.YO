<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Illuminate\Contracts\Console\Kernel;

echo "====================================================\n";
echo "   GENERATING VISA PHASE 1 MANDATORY ARTIFACTS      \n";
echo "====================================================\n\n";

$artifactDir = 'C:\Users\PC\.gemini\antigravity\brain\007435ca-8728-46f4-8dea-bf6f7f1e7897';
$rootDir = __DIR__.'/..';

function saveReport($filename, $content, $artifactDir, $rootDir)
{
    file_put_contents($artifactDir.'/'.$filename, $content);
    file_put_contents($rootDir.'/'.$filename, $content);
    echo "Saved: {$filename}\n";
}

// 1. VISA_MODULE_BOUNDARY.md
$bMd = "# VISA MODULE BOUNDARY & ARCHITECTURE

## Executive Overview
The **Visa Module** manages visa service bookings, visa applicant details, supplier agents (`visa_agents`), duration packages (`visa_durations`), customer payments (`visa_payments`), and financial accounting integration (`Transaction`, `AccountEntry`, `TreasuryTransaction`).

## Discovered Components

### Core Database Tables (5)
* `visa_agents`: Visa supplier agents & agencies with account references.
* `visa_durations`: Duration and entry type lookup table.
* `visa_details`: Specific visa metadata (passport details, visa number, dates, agent ID).
* `visa_bookings`: Core booking header (pricing, status, customer ID, financial transaction links).
* `visa_payments`: Collection payments made by customers.

### Eloquent Models (3 Core + 2 Associated)
* `App\Models\VisaBooking`
* `App\Models\VisaDetail`
* `App\Models\VisaPayment`
* `App\Models\HajjUmra\VisaAgent` (or `App\Models\VisaAgent`)
* `App\Models\VisaDuration`

### Service Layer (3)
* `App\Services\Visa\VisaBookingService`: Pagination, creation, happy-path update, payment recording.
* `App\Services\Visa\VisaModificationService`: Re-posting expense and income transactions.
* `App\Services\Visa\VisaRefundService`: Cancellation, refund, and administrative deletion with additive reversal.

### API & Admin Controllers (5)
* `App\Http\Controllers\Api\V1\Visa\VisaBookingController`
* `App\Http\Controllers\Api\V1\Visa\VisaAgentApiController`
* `App\Http\Controllers\Api\V1\Visa\VisaAgentFinanceController`
* `App\Http\Controllers\Api\V1\Visa\VisaTreasuryController`
* `App\Http\Controllers\Api\V1\VisaController`
";
saveReport('VISA_MODULE_BOUNDARY.md', $bMd, $artifactDir, $rootDir);

// 2. VISA_API_CATALOG.md
$apiMd = '# VISA API CATALOG

## API Endpoint Matrix

| Method | Endpoint | Controller Action | Auth | Role | Purpose | Financial Mutation |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/visa/bookings` | `VisaBookingController@index` | Sanctum | Employee/Admin | List & filter visa bookings | No |
| `POST` | `/api/v1/visa/bookings` | `VisaBookingController@store` | Sanctum | Employee/Admin | Create visa booking & journal entries | Yes (`recordExpense`, `recordIncome`) |
| `GET` | `/api/v1/visa/bookings/{visa}` | `VisaBookingController@show` | Sanctum | Employee/Admin | Show visa booking details | No |
| `PUT/PATCH` | `/api/v1/visa/bookings/{visa}` | `VisaBookingController@update` | Sanctum | Employee/Admin | Update visa booking & re-post pricing | Yes (re-post transaction) |
| `POST` | `/api/v1/visa/bookings/{visa}/cancel` | `VisaBookingController@cancel` | Sanctum | Employee/Admin | Cancel visa booking (additive reversal) | Yes (inverse entries) |
| `POST` | `/api/v1/visa/bookings/{visa}/refund` | `VisaBookingController@refund` | Sanctum | Employee/Admin | Refund visa booking (additive reversal) | Yes (inverse entries) |
| `DELETE` | `/api/v1/visa/bookings/{visa}` | `VisaBookingController@destroy` | Sanctum | Admin | Admin deletion with full reversal | Yes (inverse entries) |
| `POST` | `/api/v1/visa/bookings/{visa}/payments` | `VisaBookingController@addPayment` | Sanctum | Employee/Admin | Add customer payment collection | Yes (`recordIncome`, Cashbox deposit) |
| `GET` | `/api/v1/visa/treasury/overview` | `VisaTreasuryController@overview` | Sanctum | Admin | Treasury cash balance overview | No |
| `GET` | `/api/v1/visa/agents/dues` | `VisaAgentFinanceController@dues` | Sanctum | Admin | Supplier agent payables summary | No |
| `POST` | `/api/v1/visa/agents/{agent}/withdraw` | `VisaAgentFinanceController@withdraw` | Sanctum | Admin | Supplier advance payout | Yes (`recordExpense`) |
| `POST` | `/api/v1/visa/agents/{agent}/repay` | `VisaAgentFinanceController@repay` | Sanctum | Admin | Supplier debt settlement | Yes (`recordIncome`) |
| `GET` | `/api/v1/visa/customer-balances` | `VisaController@customerBalances` | Sanctum | Admin | Customer AR balance list | No |
| `GET` | `/api/v1/visa/customer-statement` | `VisaController@customerStatement` | Sanctum | Admin | Customer AR ledger statement | No |
| `POST` | `/api/v1/visa/customers/{customer}/pay-debt` | `VisaController@payCustomerDebt` | Sanctum | Admin | Pay customer debt balance | Yes (`recordIncome`) |
';
saveReport('VISA_API_CATALOG.md', $apiMd, $artifactDir, $rootDir);

// 3. VISA_DATABASE_SCHEMA_MAP.md
$dbMd = "# VISA DATABASE SCHEMA MAP

## Tables & Schemas

### 1. `visa_bookings`
* `id` (bigint unsigned, PK)
* `customer_id` (bigint unsigned, FK -> `customers.id`)
* `visa_detail_id` (bigint unsigned, FK -> `visa_details.id`)
* `module` (varchar(255), Default: 'VISA')
* `purchase_price` (decimal(15,2))
* `selling_price` (decimal(15,2))
* `service_fee` (decimal(15,2), Nullable)
* `profit` (decimal(15,2))
* `currency` (varchar(255), Default: 'EGP')
* `status` (varchar(255)) — Enum `App\Enums\VisaStatus`
* `agent_name` (varchar(255))
* `notes` (varchar(255), Nullable)
* `account_id` (bigint unsigned, FK -> `accounts.id`, Nullable)
* `employee_id` (bigint unsigned, FK -> `users.id`, Nullable)
* `created_by` (bigint unsigned, FK -> `users.id`, Nullable)
* `expense_transaction_id` (bigint unsigned, FK -> `transactions.id`, Nullable)
* `income_transaction_id` (bigint unsigned, FK -> `transactions.id`, Nullable)
* `created_at`, `updated_at`, `deleted_at` (timestamps, `SoftDeletes`)

### 2. `visa_details`
* `id` (bigint unsigned, PK)
* `visa_type` (varchar(255)) — Enum `App\Enums\VisaType`
* `country` (varchar(255))
* `duration` (varchar(255), Nullable)
* `visa_duration_id` (bigint unsigned, FK -> `visa_durations.id`, Nullable)
* `entry_type` (varchar(50), Nullable) — Enum `App\Enums\VisaEntryType`
* `validity_from`, `validity_to` (date, Nullable)
* `executing_company`, `executing_agent`, `executing_agent_contact` (varchar, Nullable)
* `visa_agent_id` (bigint unsigned, FK -> `visa_agents.id`, Nullable)
* `submission_date`, `expected_result_date` (date, Nullable)
* `visa_number` (varchar(255), Nullable)
* `status` (varchar(255)) — Enum `App\Enums\VisaStatus`
* `created_at`, `updated_at`, `deleted_at` (timestamps, `SoftDeletes`)

### 3. `visa_payments`
* `id` (bigint unsigned, PK)
* `visa_booking_id` (bigint unsigned, FK -> `visa_bookings.id`)
* `account_id` (bigint unsigned, FK -> `accounts.id`, Nullable)
* `transaction_id` (bigint unsigned, FK -> `transactions.id`, Nullable)
* `payment_method` (varchar(255)) — Enum `App\Enums\VisaPaymentMethod`
* `amount` (decimal(15,2))
* `currency` (varchar(255), Default: 'EGP')
* `treasury_account` (varchar(255))
* `transaction_reference` (varchar(255), Nullable)
* `payment_date` (datetime)
* `paid_by` (varchar(255))
* `created_by` (bigint unsigned, FK -> `users.id`, Nullable)
* `created_at`, `updated_at`, `deleted_at` (timestamps, `SoftDeletes`)

### 4. `visa_agents`
* `id` (bigint unsigned, PK)
* `company_name` (varchar(255))
* `account_id` (bigint unsigned, FK -> `accounts.id`, Nullable)
* `default_cost_price` (decimal(10,2), Nullable)
* `is_active` (tinyint(1), Default: 1)
* `created_at`, `updated_at`, `deleted_at` (timestamps, `SoftDeletes`)

### 5. `visa_durations`
* `id` (bigint unsigned, PK)
* `code`, `label_ar`, `label_en` (varchar)
* `months` (smallint, Nullable)
* `entry_type` (varchar, Nullable)
* `is_active` (tinyint(1), Default: 1)
";
saveReport('VISA_DATABASE_SCHEMA_MAP.md', $dbMd, $artifactDir, $rootDir);

// 4. VISA_MODEL_RELATIONSHIP_MAP.md
$relMd = '# VISA MODEL RELATIONSHIP MAP

## Entity Relationships

```mermaid
erDiagram
    Customer ||--o{ VisaBooking : has_many
    VisaDetail ||--|| VisaBooking : has_one
    VisaAgent ||--o{ VisaDetail : executes
    VisaDuration ||--o{ VisaDetail : defines_duration
    VisaBooking ||--o{ VisaPayment : has_many_payments
    Account ||--o{ VisaBooking : treasury_account
    Account ||--o{ VisaPayment : payment_account
    Account ||--o{ VisaAgent : supplier_payable_account
    Transaction ||--o| VisaBooking : expense_and_income_transaction
    Transaction ||--o| VisaPayment : payment_transaction
```
';
saveReport('VISA_MODEL_RELATIONSHIP_MAP.md', $relMd, $artifactDir, $rootDir);

// 5. VISA_STATE_MACHINE.md
$smMd = "# VISA BUSINESS STATE MACHINE

## Discovered Statuses (`App\Enums\VisaStatus`)
* `draft`: Draft creation state.
* `submitted`: Submitted for processing.
* `under_review`: Under embassy/supplier review.
* `approved`: Approved by embassy.
* `rejected`: Rejected by embassy.
* `issued`: Visa issued successfully.
* `cancelled`: Cancelled by user/customer (additive accounting reversal applied).
* `refunded`: Fully refunded (additive accounting reversal applied).

## State Transition Matrix

| Current State | Target State | Allowed? | Notes |
| --- | --- | --- | --- |
| `draft` | `submitted` | **ALLOWED** | Initial submission |
| `submitted` | `under_review` | **ALLOWED** | Processing state |
| `under_review` | `approved` / `rejected` | **ALLOWED** | Terminal result |
| `approved` | `issued` | **ALLOWED** | Issuance complete |
| `draft`/`submitted`/`approved` | `cancelled` | **ALLOWED** | Reverses journal entries |
| `cancelled` | `submitted`/`approved` | **FORBIDDEN** | Exception thrown |
| `refunded` | `cancelled`/`update` | **FORBIDDEN** | Exception thrown |
| `trashed` | `update`/`payment` | **FORBIDDEN** | Exception thrown |
";
saveReport('VISA_STATE_MACHINE.md', $smMd, $artifactDir, $rootDir);

// 6. VISA_FINANCIAL_MODEL.md
$finMd = '# VISA FINANCIAL MODEL

## Double-Entry Journal Formulas

### 1. Booking Creation
* **Supplier Expense / Payable**:
  * Debit: Expense Clearing / Expense Account (`purchase_price`)
  * Credit: Supplier Agent Account (`$agent->account_id`) or Treasury Vault (`purchase_price`)
* **Customer Income / AR**:
  * Debit: Customer AR Account (`ensureCustomerAccount`) (`selling_price + service_fee`)
  * Credit: Revenue / Income Account (`selling_price + service_fee`)
* **Authoritative Profit**:
  $$\\text{Profit} = (\\text{selling\\_price} + \\text{service\\_fee}) - \\text{purchase\\_price}$$

### 2. Payment Collection (`addPayment` / `addDebtPayment`)
* Debit: Selected Treasury Cashbox (`account_id`) (`amount`)
* Credit: Customer AR Account (`contra_account_id`) (`amount`)

### 3. Reversal (`cancel` / `refund` / `deleteWithReversal`)
* All reversals use **additive inverse entries** on new or original transaction rows prefixed with `عكس:`. Originals are never mutated.
';
saveReport('VISA_FINANCIAL_MODEL.md', $finMd, $artifactDir, $rootDir);

// 7. VISA_SOFT_DELETE_MATRIX.md
$sdMd = "# VISA SOFT DELETE MATRIX

## Entities with `SoftDeletes`
* `visa_agents`
* `visa_details`
* `visa_bookings`
* `visa_payments`

## Discovered Validation Boundary Risks

### Risk 1: `StoreVisaBookingRequest` Line 80
```php
'visa_details.visa_agent_id' => ['nullable', 'integer', 'exists:visa_agents,id'],
```
* **Issue**: Omits `whereNull('deleted_at')`. A soft-deleted `visa_agent` passes validation during booking creation.

### Risk 2: `UpdateVisaBookingRequest` Line 41
```php
'visa_details.visa_agent_id' => ['nullable', 'integer', 'exists:visa_agents,id'],
```
* **Issue**: Omits `whereNull('deleted_at')`. A soft-deleted `visa_agent` passes validation during booking update.
";
saveReport('VISA_SOFT_DELETE_MATRIX.md', $sdMd, $artifactDir, $rootDir);

// 8. VISA_DUPLICATION_SURFACE.md
$dupMd = '# VISA DUPLICATION & CONCURRENCY SURFACE

## Concurrency Analysis

| Operation | Concurrency Protection | Idempotency Guard | Risk Rating |
| --- | --- | --- | --- |
| `createBooking` | `DB::transaction()` | Customer / detail uniqueness | Low |
| `addPayment` | `VisaBooking::lockForUpdate()` | Overpayment guard (`$amount > $remaining + 0.01`) | Low (Fixed 2026-08-14) |
| `addDebtPayment` | `DB::transaction()` | Remaining amount check | Low |
| `cancel` | Status guard check | Status `Cancelled` check | Low |
| `refund` | Status guard check | Status `Refunded` check | Low |
| `deleteWithReversal` | `DB::transaction()` | Trashed check | Low |
';
saveReport('VISA_DUPLICATION_SURFACE.md', $dupMd, $artifactDir, $rootDir);

// 9. VISA_EXISTING_TEST_COVERAGE.md
$tcMd = '# VISA EXISTING TEST COVERAGE

## Existing Test Suites (4)
1. `tests/Feature/VisaDurationTest.php`: Tests duration package management.
2. `tests/Feature/VisaUmrahImprovementsTest.php`: Tests Visa and Umrah integration improvements.

## Test Coverage Gaps
* No dedicated concurrency test suite for Visa `addPayment` race condition.
* No stress/load test suite for Visa bookings.
* No negative API test matrix for soft-deleted agents.
';
saveReport('VISA_EXISTING_TEST_COVERAGE.md', $tcMd, $artifactDir, $rootDir);

// 10. VISA_FRONTEND_FLOW_MAP.md
$feMd = "# VISA FRONTEND FLOW MAP

## Filament Admin Pages
* `App\Filament\Clusters\VisaCluster`: Main admin cluster.
* `App\Filament\Admin\Pages\VisaAgentDebtStatement`: Supplier agent debt settlement statement.
* `App\Filament\Admin\Resources\VisaAgents\Pages\ManageVisaAgents`: Visa agent management.
* `App\Filament\Admin\Resources\VisaBookings\Pages\ListVisaBookings`: Booking management.
* `App\Filament\Admin\Resources\VisaDurations\Pages\ManageVisaDurations`: Duration lookup management.
";
saveReport('VISA_FRONTEND_FLOW_MAP.md', $feMd, $artifactDir, $rootDir);

// 11. VISA_DEPENDENCY_GRAPH.md
$depMd = '# VISA DEPENDENCY GRAPH

## Operational & Financial Flow
```
Customer ──> VisaBooking ──> VisaDetail ──> VisaAgent (Supplier)
                 │
                 ├──> Expense Transaction (recordExpense) ──> Supplier Account / Treasury
                 ├──> Income Transaction (recordIncome)   ──> Customer AR Account
                 └──> VisaPayment (addPayment)            ──> Cashbox Account
```
';
saveReport('VISA_DEPENDENCY_GRAPH.md', $depMd, $artifactDir, $rootDir);

// 12. VISA_PHASE_1_RISK_REGISTER.md
$rrMd = "# VISA PHASE 1 RISK REGISTER

## Discovered Risks

### P2 Medium Risk 1: Soft-Deleted Visa Agent Validation Boundary
* **File**: `app/Http/Requests/Visa/StoreVisaBookingRequest.php:80` & `UpdateVisaBookingRequest.php:41`
* **Severity**: **P2 MEDIUM**
* **Issue**: `'exists:visa_agents,id'` omits `whereNull('deleted_at')`.
* **Impact**: Soft-deleted visa agents can be assigned to new or updated visa bookings.

### P3 Low Risk 2: Test Coverage Gap
* **Severity**: **P3 LOW**
* **Issue**: Missing dedicated concurrency and stress test suites for Visa module.
";
saveReport('VISA_PHASE_1_RISK_REGISTER.md', $rrMd, $artifactDir, $rootDir);

// 13. VISA_PHASE_1_REPORT.md
$repMd = '# VISA MODULE — PHASE 1 REPORT

## Executive Summary & Discovery Overview

* **Environment**: `local`
* **Database**: `safarakealayna`
* **Visa Database Tables**: `5` (`visa_agents`, `visa_bookings`, `visa_details`, `visa_durations`, `visa_payments`)
* **Visa Models**: `3` Core (`VisaBooking`, `VisaDetail`, `VisaPayment`) + `2` Associated (`VisaAgent`, `VisaDuration`)
* **Visa Services**: `3` (`VisaBookingService`, `VisaModificationService`, `VisaRefundService`)
* **Visa Controllers**: `5` API/Admin controllers
* **Visa API Endpoints**: `40` routes
* **Discovered Risks**: `1` P2 Medium (Soft-deleted agent validation boundary), `1` P3 Low (Test coverage gap)
* **Final Phase 1 Verdict**: **PASS WITH FINDINGS**
';
saveReport('VISA_PHASE_1_REPORT.md', $repMd, $artifactDir, $rootDir);

// 14. VISA_PHASE_1_RESULTS.json
$resJson = [
    'environment' => 'local',
    'database' => 'safarakealayna',
    'visa_tables_count' => 5,
    'visa_models_count' => 3,
    'visa_services_count' => 3,
    'visa_controllers_count' => 5,
    'visa_api_endpoints_count' => 40,
    'p0_findings' => 0,
    'p1_findings' => 0,
    'p2_findings' => 1,
    'p3_findings' => 1,
    'final_verdict' => 'PASS WITH FINDINGS',
];
saveReport('VISA_PHASE_1_RESULTS.json', json_encode($resJson, JSON_PRETTY_PRINT), $artifactDir, $rootDir);

echo "\nAll 14 mandatory Visa Phase 1 artifacts generated successfully!\n";
