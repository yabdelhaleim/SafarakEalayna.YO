# VISA DATABASE SCHEMA MAP

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
