# BUS DATABASE MAP

Database: safarakealayna
Driver: mysql

## Table: `bus_companies`

| Column | Type | Nullable | Key | Default | Extra | Comment |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | NO | `PRI` | NULL | `auto_increment` |  |
| `name` | `varchar(100)` | NO | `` | NULL | `` |  |
| `phone` | `varchar(20)` | YES | `` | NULL | `` |  |
| `account_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `address` | `text` | YES | `` | NULL | `` |  |
| `is_active` | `tinyint(1)` | NO | `MUL` | `1` | `` |  |
| `notes` | `text` | YES | `` | NULL | `` |  |
| `created_by` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `created_at` | `timestamp` | YES | `` | NULL | `` |  |
| `updated_at` | `timestamp` | YES | `` | NULL | `` |  |
| `deleted_at` | `timestamp` | YES | `MUL` | NULL | `` |  |

### Indexes for `bus_companies`

| Key Name | Column Name | Non Unique | Index Type |
| --- | --- | --- | --- |
| `PRIMARY` | `id` | 0 | `BTREE` |
| `bus_companies_is_active_index` | `is_active` | 1 | `BTREE` |
| `bus_companies_created_by_index` | `created_by` | 1 | `BTREE` |
| `bus_companies_account_id_index` | `account_id` | 1 | `BTREE` |
| `bus_companies_deleted_at_index` | `deleted_at` | 1 | `BTREE` |

---

## Table: `bus_inventories`

| Column | Type | Nullable | Key | Default | Extra | Comment |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | NO | `PRI` | NULL | `auto_increment` |  |
| `company_id` | `bigint unsigned` | NO | `MUL` | NULL | `` |  |
| `route` | `varchar(200)` | NO | `` | NULL | `` |  |
| `travel_date` | `date` | NO | `MUL` | NULL | `` |  |
| `departure_time` | `time` | YES | `` | NULL | `` |  |
| `total_tickets` | `int` | NO | `` | NULL | `` |  |
| `available_tickets` | `int` | NO | `MUL` | NULL | `` |  |
| `cost_per_ticket` | `decimal(12,2)` | NO | `` | NULL | `` |  |
| `selling_price` | `decimal(12,2)` | NO | `` | NULL | `` |  |
| `currency` | `varchar(3)` | NO | `MUL` | `EGP` | `` |  |
| `exchange_rate_to_egp` | `decimal(12,6)` | NO | `` | `1.000000` | `` |  |
| `payment_type` | `enum('cash','deferred')` | NO | `MUL` | NULL | `` |  |
| `total_cost` | `decimal(12,2)` | NO | `` | NULL | `` |  |
| `amount_paid` | `decimal(12,2)` | NO | `` | `0.00` | `` |  |
| `remaining_debt` | `decimal(12,2)` | NO | `MUL` | NULL | `` |  |
| `account_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `transaction_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `notes` | `text` | YES | `` | NULL | `` |  |
| `is_auto_created` | `tinyint(1)` | NO | `` | `0` | `` |  |
| `created_by` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `created_at` | `timestamp` | YES | `MUL` | NULL | `` |  |
| `updated_at` | `timestamp` | YES | `` | NULL | `` |  |
| `deleted_at` | `timestamp` | YES | `MUL` | NULL | `` |  |

### Indexes for `bus_inventories`

| Key Name | Column Name | Non Unique | Index Type |
| --- | --- | --- | --- |
| `PRIMARY` | `id` | 0 | `BTREE` |
| `bus_inventories_transaction_id_foreign` | `transaction_id` | 1 | `BTREE` |
| `bus_inventories_created_by_foreign` | `created_by` | 1 | `BTREE` |
| `bus_inventories_company_id_index` | `company_id` | 1 | `BTREE` |
| `bus_inventories_travel_date_index` | `travel_date` | 1 | `BTREE` |
| `bus_inventories_available_tickets_index` | `available_tickets` | 1 | `BTREE` |
| `bus_inventories_remaining_debt_index` | `remaining_debt` | 1 | `BTREE` |
| `bus_inventories_company_id_travel_date_index` | `company_id` | 1 | `BTREE` |
| `bus_inventories_company_id_travel_date_index` | `travel_date` | 1 | `BTREE` |
| `bus_inventories_account_id_index` | `account_id` | 1 | `BTREE` |
| `bus_inventories_payment_type_index` | `payment_type` | 1 | `BTREE` |
| `bus_inventories_created_at_index` | `created_at` | 1 | `BTREE` |
| `bus_inventories_deleted_at_index` | `deleted_at` | 1 | `BTREE` |
| `bus_inventories_currency_index` | `currency` | 1 | `BTREE` |

---

## Table: `bus_bookings`

| Column | Type | Nullable | Key | Default | Extra | Comment |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | NO | `PRI` | NULL | `auto_increment` |  |
| `inventory_id` | `bigint unsigned` | NO | `MUL` | NULL | `` |  |
| `customer_id` | `bigint unsigned` | NO | `MUL` | NULL | `` |  |
| `employee_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `quantity` | `int` | NO | `` | NULL | `` |  |
| `unit_price` | `decimal(12,2)` | NO | `` | NULL | `` |  |
| `total_price` | `decimal(12,2)` | NO | `` | NULL | `` |  |
| `paid_amount` | `decimal(10,2)` | NO | `` | `0.00` | `` |  |
| `payment_status` | `enum('pending','partial','paid','overdue')` | NO | `MUL` | `pending` | `` |  |
| `profit` | `decimal(12,2)` | NO | `` | NULL | `` |  |
| `status` | `enum('pending','paid','cancelled','refunded','partially_refunded')` | NO | `MUL` | `pending` | `` |  |
| `account_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `transaction_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `currency` | `varchar(3)` | NO | `MUL` | `EGP` | `` |  |
| `exchange_rate_to_egp` | `decimal(12,6)` | NO | `` | `1.000000` | `` |  |
| `notes` | `text` | YES | `` | NULL | `` |  |
| `created_by` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `created_at` | `timestamp` | YES | `MUL` | NULL | `` |  |
| `updated_at` | `timestamp` | YES | `` | NULL | `` |  |
| `deleted_at` | `timestamp` | YES | `MUL` | NULL | `` |  |

### Indexes for `bus_bookings`

| Key Name | Column Name | Non Unique | Index Type |
| --- | --- | --- | --- |
| `PRIMARY` | `id` | 0 | `BTREE` |
| `bus_bookings_transaction_id_foreign` | `transaction_id` | 1 | `BTREE` |
| `bus_bookings_created_by_foreign` | `created_by` | 1 | `BTREE` |
| `bus_bookings_inventory_id_index` | `inventory_id` | 1 | `BTREE` |
| `bus_bookings_customer_id_index` | `customer_id` | 1 | `BTREE` |
| `bus_bookings_employee_id_index` | `employee_id` | 1 | `BTREE` |
| `bus_bookings_status_index` | `status` | 1 | `BTREE` |
| `bus_bookings_inventory_id_status_index` | `inventory_id` | 1 | `BTREE` |
| `bus_bookings_inventory_id_status_index` | `status` | 1 | `BTREE` |
| `bus_bookings_created_at_index` | `created_at` | 1 | `BTREE` |
| `bus_bookings_account_id_index` | `account_id` | 1 | `BTREE` |
| `bus_bookings_payment_status_index` | `payment_status` | 1 | `BTREE` |
| `bus_bookings_deleted_at_index` | `deleted_at` | 1 | `BTREE` |
| `bus_bookings_currency_index` | `currency` | 1 | `BTREE` |

---

## Table: `bus_payments`

| Column | Type | Nullable | Key | Default | Extra | Comment |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | NO | `PRI` | NULL | `auto_increment` |  |
| `booking_id` | `bigint unsigned` | NO | `MUL` | NULL | `` |  |
| `amount` | `decimal(12,2)` | NO | `` | NULL | `` |  |
| `payment_method` | `enum('cash','bank_transfer','cash_wallet','postal_transfer','office_safe','office_drawer')` | NO | `MUL` | `cash` | `` |  |
| `account_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `transaction_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `currency` | `varchar(3)` | NO | `MUL` | `EGP` | `` |  |
| `exchange_rate_to_egp` | `decimal(12,6)` | NO | `` | `1.000000` | `` |  |
| `notes` | `text` | YES | `` | NULL | `` |  |
| `created_by` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `created_at` | `timestamp` | YES | `MUL` | NULL | `` |  |
| `updated_at` | `timestamp` | YES | `` | NULL | `` |  |
| `deleted_at` | `timestamp` | YES | `` | NULL | `` |  |

### Indexes for `bus_payments`

| Key Name | Column Name | Non Unique | Index Type |
| --- | --- | --- | --- |
| `PRIMARY` | `id` | 0 | `BTREE` |
| `bus_payments_created_by_foreign` | `created_by` | 1 | `BTREE` |
| `bus_payments_booking_id_index` | `booking_id` | 1 | `BTREE` |
| `bus_payments_payment_method_index` | `payment_method` | 1 | `BTREE` |
| `bus_payments_created_at_index` | `created_at` | 1 | `BTREE` |
| `bus_payments_account_id_index` | `account_id` | 1 | `BTREE` |
| `bus_payments_transaction_id_index` | `transaction_id` | 1 | `BTREE` |
| `bus_payments_currency_index` | `currency` | 1 | `BTREE` |

---

## Table: `bus_company_payments`

| Column | Type | Nullable | Key | Default | Extra | Comment |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | NO | `PRI` | NULL | `auto_increment` |  |
| `company_id` | `bigint unsigned` | NO | `MUL` | NULL | `` |  |
| `inventory_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `amount` | `decimal(12,2)` | NO | `` | NULL | `` |  |
| `account_id` | `bigint unsigned` | NO | `MUL` | NULL | `` |  |
| `transaction_id` | `bigint unsigned` | NO | `MUL` | NULL | `` |  |
| `status` | `enum('paid','pending','cancelled')` | NO | `MUL` | NULL | `` |  |
| `notes` | `text` | YES | `` | NULL | `` |  |
| `created_by` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `created_at` | `timestamp` | YES | `MUL` | NULL | `` |  |
| `updated_at` | `timestamp` | YES | `` | NULL | `` |  |
| `deleted_at` | `timestamp` | YES | `` | NULL | `` |  |

### Indexes for `bus_company_payments`

| Key Name | Column Name | Non Unique | Index Type |
| --- | --- | --- | --- |
| `PRIMARY` | `id` | 0 | `BTREE` |
| `bus_company_payments_created_by_foreign` | `created_by` | 1 | `BTREE` |
| `bus_company_payments_company_id_index` | `company_id` | 1 | `BTREE` |
| `bus_company_payments_inventory_id_index` | `inventory_id` | 1 | `BTREE` |
| `bus_company_payments_status_index` | `status` | 1 | `BTREE` |
| `bus_company_payments_company_id_status_index` | `company_id` | 1 | `BTREE` |
| `bus_company_payments_company_id_status_index` | `status` | 1 | `BTREE` |
| `bus_company_payments_account_id_index` | `account_id` | 1 | `BTREE` |
| `bus_company_payments_transaction_id_index` | `transaction_id` | 1 | `BTREE` |
| `bus_company_payments_created_at_index` | `created_at` | 1 | `BTREE` |

---

## Table: `bus_refund_requests`

| Column | Type | Nullable | Key | Default | Extra | Comment |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | NO | `PRI` | NULL | `auto_increment` |  |
| `bus_booking_id` | `bigint unsigned` | NO | `MUL` | NULL | `` |  |
| `company_id` | `bigint unsigned` | NO | `MUL` | NULL | `` |  |
| `refund_type` | `varchar(255)` | NO | `` | NULL | `` |  |
| `original_currency` | `varchar(3)` | NO | `` | NULL | `` |  |
| `original_amount` | `decimal(15,2)` | NO | `` | NULL | `` |  |
| `cancellation_fee` | `decimal(15,2)` | NO | `` | `0.00` | `` |  |
| `company_penalty` | `decimal(15,2)` | NO | `` | `0.00` | `` |  |
| `office_penalty` | `decimal(15,2)` | NO | `` | `0.00` | `` |  |
| `total_paid` | `decimal(15,2)` | NO | `` | `0.00` | `` |  |
| `refund_amount` | `decimal(15,2)` | NO | `` | NULL | `` |  |
| `refund_currency` | `varchar(3)` | NO | `` | NULL | `` |  |
| `refund_exchange_rate` | `decimal(15,6)` | NO | `` | `1.000000` | `` |  |
| `base_currency_refund` | `decimal(15,2)` | NO | `` | `0.00` | `` |  |
| `destination` | `varchar(255)` | NO | `` | NULL | `` |  |
| `treasury_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `account_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `transaction_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `status` | `varchar(255)` | NO | `MUL` | `pending` | `` |  |
| `notes` | `text` | YES | `` | NULL | `` |  |
| `processed_at` | `timestamp` | YES | `` | NULL | `` |  |
| `created_by` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `created_at` | `timestamp` | YES | `MUL` | NULL | `` |  |
| `updated_at` | `timestamp` | YES | `` | NULL | `` |  |
| `deleted_at` | `timestamp` | YES | `` | NULL | `` |  |

### Indexes for `bus_refund_requests`

| Key Name | Column Name | Non Unique | Index Type |
| --- | --- | --- | --- |
| `PRIMARY` | `id` | 0 | `BTREE` |
| `bus_refund_requests_treasury_id_foreign` | `treasury_id` | 1 | `BTREE` |
| `bus_refund_requests_created_by_foreign` | `created_by` | 1 | `BTREE` |
| `bus_refund_requests_bus_booking_id_index` | `bus_booking_id` | 1 | `BTREE` |
| `bus_refund_requests_company_id_index` | `company_id` | 1 | `BTREE` |
| `bus_refund_requests_status_index` | `status` | 1 | `BTREE` |
| `bus_refund_requests_created_at_index` | `created_at` | 1 | `BTREE` |
| `bus_refund_requests_account_id_foreign` | `account_id` | 1 | `BTREE` |
| `bus_refund_requests_transaction_id_foreign` | `transaction_id` | 1 | `BTREE` |

---

## Table: `customers`

| Column | Type | Nullable | Key | Default | Extra | Comment |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | NO | `PRI` | NULL | `auto_increment` |  |
| `account_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `full_name` | `varchar(255)` | NO | `MUL` | NULL | `` |  |
| `phone` | `varchar(255)` | NO | `MUL` | NULL | `` |  |
| `email` | `varchar(255)` | YES | `MUL` | NULL | `` |  |
| `national_id` | `varchar(14)` | YES | `MUL` | NULL | `` |  |
| `passport_number` | `varchar(255)` | YES | `MUL` | NULL | `` |  |
| `passport_expiry` | `date` | YES | `` | NULL | `` |  |
| `date_of_birth` | `date` | YES | `` | NULL | `` |  |
| `city` | `varchar(255)` | YES | `MUL` | NULL | `` |  |
| `affiliation` | `varchar(255)` | YES | `` | NULL | `` |  |
| `whatsapp_number` | `varchar(255)` | YES | `` | NULL | `` |  |
| `travel_country` | `varchar(255)` | YES | `` | NULL | `` |  |
| `type` | `varchar(50)` | NO | `MUL` | `individual` | `` |  |
| `module_type` | `varchar(50)` | YES | `` | NULL | `` |  |
| `customer_tier` | `varchar(255)` | NO | `` | `STANDARD` | `` |  |
| `notes` | `text` | YES | `` | NULL | `` |  |
| `created_at` | `timestamp` | YES | `` | NULL | `` |  |
| `updated_at` | `timestamp` | YES | `` | NULL | `` |  |
| `deleted_at` | `timestamp` | YES | `` | NULL | `` |  |
| `created_by` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `name` | `varchar(255)` | YES | `` | NULL | `` |  |
| `nationality` | `enum('EG','SA','AE','KW','QA','BH','OM','JO','OTHER')` | NO | `` | `EG` | `` |  |
| `gender` | `enum('male','female')` | YES | `` | NULL | `` |  |
| `address` | `text` | YES | `` | NULL | `` |  |
| `status` | `enum('active','blocked','vip')` | NO | `MUL` | `active` | `` |  |
| `total_spent` | `decimal(12,2)` | NO | `` | `0.00` | `` |  |
| `bookings_count` | `int` | NO | `` | `0` | `` |  |

### Indexes for `customers`

| Key Name | Column Name | Non Unique | Index Type |
| --- | --- | --- | --- |
| `PRIMARY` | `id` | 0 | `BTREE` |
| `customers_phone_national_id_unique` | `phone` | 0 | `BTREE` |
| `customers_phone_national_id_unique` | `national_id` | 0 | `BTREE` |
| `customers_full_name_index` | `full_name` | 1 | `BTREE` |
| `customers_phone_index` | `phone` | 1 | `BTREE` |
| `customers_national_id_index` | `national_id` | 1 | `BTREE` |
| `customers_passport_number_index` | `passport_number` | 1 | `BTREE` |
| `customers_created_by_foreign` | `created_by` | 1 | `BTREE` |
| `customers_type_index` | `type` | 1 | `BTREE` |
| `customers_email_index` | `email` | 1 | `BTREE` |
| `customers_status_index` | `status` | 1 | `BTREE` |
| `customers_account_id_index` | `account_id` | 1 | `BTREE` |
| `customers_city_index` | `city` | 1 | `BTREE` |

---

## Table: `accounts`

| Column | Type | Nullable | Key | Default | Extra | Comment |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | NO | `PRI` | NULL | `auto_increment` |  |
| `name` | `varchar(255)` | NO | `` | NULL | `` |  |
| `type` | `enum('bank','cashbox','customer','owner','supplier','wallet','expense','liability','revenue')` | NO | `MUL` | NULL | `` |  |
| `treasury_type` | `varchar(255)` | YES | `` | NULL | `` |  |
| `bank_name` | `varchar(255)` | YES | `` | NULL | `` |  |
| `account_number` | `varchar(255)` | YES | `` | NULL | `` |  |
| `branch_name` | `varchar(255)` | YES | `` | NULL | `` |  |
| `currency` | `varchar(3)` | NO | `MUL` | `EGP` | `` |  |
| `balance` | `decimal(15,2)` | NO | `` | `0.00` | `` |  |
| `is_active` | `tinyint(1)` | NO | `MUL` | `1` | `` |  |
| `owner_type` | `enum('owner','office')` | NO | `` | `owner` | `` |  |
| `module_type` | `varchar(50)` | NO | `MUL` | `tourism` | `` |  |
| `module` | `varchar(255)` | YES | `` | NULL | `` |  |
| `is_module_vault` | `tinyint(1)` | NO | `` | `0` | `` |  |
| `created_by` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `notes` | `text` | YES | `` | NULL | `` |  |
| `wallet_provider` | `varchar(40)` | YES | `` | NULL | `` |  |
| `wallet_number` | `varchar(100)` | YES | `` | NULL | `` |  |
| `created_at` | `timestamp` | YES | `` | NULL | `` |  |
| `updated_at` | `timestamp` | YES | `` | NULL | `` |  |
| `deleted_at` | `timestamp` | YES | `` | NULL | `` |  |

### Indexes for `accounts`

| Key Name | Column Name | Non Unique | Index Type |
| --- | --- | --- | --- |
| `PRIMARY` | `id` | 0 | `BTREE` |
| `accounts_created_by_foreign` | `created_by` | 1 | `BTREE` |
| `accounts_type_owner_type_index` | `type` | 1 | `BTREE` |
| `accounts_type_owner_type_index` | `owner_type` | 1 | `BTREE` |
| `accounts_currency_index` | `currency` | 1 | `BTREE` |
| `accounts_module_type_index` | `module_type` | 1 | `BTREE` |
| `accounts_type_currency_treasury_type_index` | `type` | 1 | `BTREE` |
| `accounts_type_currency_treasury_type_index` | `currency` | 1 | `BTREE` |
| `accounts_type_currency_treasury_type_index` | `treasury_type` | 1 | `BTREE` |
| `accounts_type_wallet_provider_index` | `type` | 1 | `BTREE` |
| `accounts_type_wallet_provider_index` | `wallet_provider` | 1 | `BTREE` |
| `accounts_module_type_is_module_vault_index` | `module_type` | 1 | `BTREE` |
| `accounts_module_type_is_module_vault_index` | `is_module_vault` | 1 | `BTREE` |
| `accounts_is_active_index` | `is_active` | 1 | `BTREE` |
| `idx_accounts_module_vault_active` | `module_type` | 1 | `BTREE` |
| `idx_accounts_module_vault_active` | `is_module_vault` | 1 | `BTREE` |
| `idx_accounts_module_vault_active` | `is_active` | 1 | `BTREE` |

---

## Table: `account_entries`

| Column | Type | Nullable | Key | Default | Extra | Comment |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | NO | `PRI` | NULL | `auto_increment` |  |
| `account_id` | `bigint unsigned` | NO | `MUL` | NULL | `` |  |
| `transaction_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `debit` | `decimal(15,2)` | NO | `` | `0.00` | `` |  |
| `credit` | `decimal(15,2)` | NO | `` | `0.00` | `` |  |
| `balance_after` | `decimal(15,2)` | NO | `` | NULL | `` |  |
| `notes` | `text` | YES | `` | NULL | `` |  |
| `created_at` | `timestamp` | YES | `` | NULL | `` |  |
| `updated_at` | `timestamp` | YES | `` | NULL | `` |  |

### Indexes for `account_entries`

| Key Name | Column Name | Non Unique | Index Type |
| --- | --- | --- | --- |
| `PRIMARY` | `id` | 0 | `BTREE` |
| `account_entries_transaction_id_foreign` | `transaction_id` | 1 | `BTREE` |
| `account_entries_account_id_transaction_id_index` | `account_id` | 1 | `BTREE` |
| `account_entries_account_id_transaction_id_index` | `transaction_id` | 1 | `BTREE` |

---

## Table: `treasury_transactions`

| Column | Type | Nullable | Key | Default | Extra | Comment |
| --- | --- | --- | --- | --- | --- | --- |
| `id` | `bigint unsigned` | NO | `PRI` | NULL | `auto_increment` |  |
| `transaction_type` | `varchar(32)` | YES | `` | NULL | `` |  |
| `account_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `from_treasury` | `varchar(255)` | YES | `MUL` | NULL | `` |  |
| `to_treasury` | `varchar(255)` | YES | `MUL` | NULL | `` |  |
| `amount` | `decimal(15,2)` | NO | `` | NULL | `` |  |
| `balance_before` | `decimal(15,2)` | YES | `` | NULL | `` |  |
| `balance_after` | `decimal(15,2)` | YES | `` | NULL | `` |  |
| `currency` | `varchar(3)` | NO | `` | `EGP` | `` |  |
| `reason` | `varchar(255)` | NO | `` | NULL | `` |  |
| `flight_booking_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `hajj_umra_booking_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `visa_booking_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `bus_booking_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `agent_name` | `varchar(255)` | NO | `` | NULL | `` |  |
| `reference_number` | `varchar(255)` | YES | `` | NULL | `` |  |
| `ledger_transaction_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `created_at` | `timestamp` | YES | `MUL` | NULL | `` |  |
| `updated_at` | `timestamp` | YES | `` | NULL | `` |  |
| `treasury_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `refund_request_id` | `bigint unsigned` | YES | `MUL` | NULL | `` |  |
| `type` | `varchar(255)` | YES | `` | NULL | `` |  |
| `exchange_rate` | `decimal(15,6)` | YES | `` | NULL | `` |  |
| `base_amount` | `decimal(15,2)` | YES | `` | NULL | `` |  |
| `description` | `text` | YES | `` | NULL | `` |  |

### Indexes for `treasury_transactions`

| Key Name | Column Name | Non Unique | Index Type |
| --- | --- | --- | --- |
| `PRIMARY` | `id` | 0 | `BTREE` |
| `treasury_transactions_flight_booking_id_foreign` | `flight_booking_id` | 1 | `BTREE` |
| `treasury_transactions_hajj_umra_booking_id_foreign` | `hajj_umra_booking_id` | 1 | `BTREE` |
| `treasury_transactions_visa_booking_id_foreign` | `visa_booking_id` | 1 | `BTREE` |
| `treasury_transactions_from_treasury_index` | `from_treasury` | 1 | `BTREE` |
| `treasury_transactions_to_treasury_index` | `to_treasury` | 1 | `BTREE` |
| `treasury_transactions_created_at_index` | `created_at` | 1 | `BTREE` |
| `treasury_transactions_account_id_foreign` | `account_id` | 1 | `BTREE` |
| `treasury_transactions_ledger_transaction_id_foreign` | `ledger_transaction_id` | 1 | `BTREE` |
| `treasury_transactions_treasury_id_foreign` | `treasury_id` | 1 | `BTREE` |
| `treasury_transactions_refund_request_id_foreign` | `refund_request_id` | 1 | `BTREE` |
| `treasury_transactions_bus_booking_id_foreign` | `bus_booking_id` | 1 | `BTREE` |

---

## Foreign Keys Map

| Table | Column | Constraint | Referenced Table | Referenced Column |
| --- | --- | --- | --- | --- |
| `account_entries` | `account_id` | `account_entries_account_id_foreign` | `accounts` | `id` |
| `account_entries` | `transaction_id` | `account_entries_transaction_id_foreign` | `transactions` | `id` |
| `treasury_transactions` | `account_id` | `treasury_transactions_account_id_foreign` | `accounts` | `id` |
| `treasury_transactions` | `bus_booking_id` | `treasury_transactions_bus_booking_id_foreign` | `bus_bookings` | `id` |
| `treasury_transactions` | `flight_booking_id` | `treasury_transactions_flight_booking_id_foreign` | `flight_bookings` | `id` |
| `treasury_transactions` | `hajj_umra_booking_id` | `treasury_transactions_hajj_umra_booking_id_foreign` | `hajj_umra_bookings` | `id` |
| `treasury_transactions` | `ledger_transaction_id` | `treasury_transactions_ledger_transaction_id_foreign` | `transactions` | `id` |
| `treasury_transactions` | `refund_request_id` | `treasury_transactions_refund_request_id_foreign` | `refund_requests` | `id` |
| `treasury_transactions` | `treasury_id` | `treasury_transactions_treasury_id_foreign` | `treasuries` | `id` |
| `treasury_transactions` | `visa_booking_id` | `treasury_transactions_visa_booking_id_foreign` | `visa_bookings` | `id` |
| `bus_companies` | `account_id` | `bus_companies_account_id_foreign` | `accounts` | `id` |
| `bus_companies` | `created_by` | `bus_companies_created_by_foreign` | `users` | `id` |
| `accounts` | `created_by` | `accounts_created_by_foreign` | `users` | `id` |
| `bus_company_payments` | `account_id` | `bus_company_payments_account_id_foreign` | `accounts` | `id` |
| `bus_company_payments` | `company_id` | `bus_company_payments_company_id_foreign` | `bus_companies` | `id` |
| `bus_company_payments` | `created_by` | `bus_company_payments_created_by_foreign` | `users` | `id` |
| `bus_company_payments` | `inventory_id` | `bus_company_payments_inventory_id_foreign` | `bus_inventories` | `id` |
| `bus_company_payments` | `transaction_id` | `bus_company_payments_transaction_id_foreign` | `transactions` | `id` |
| `bus_refund_requests` | `account_id` | `bus_refund_requests_account_id_foreign` | `accounts` | `id` |
| `bus_refund_requests` | `bus_booking_id` | `bus_refund_requests_bus_booking_id_foreign` | `bus_bookings` | `id` |
| `bus_refund_requests` | `company_id` | `bus_refund_requests_company_id_foreign` | `bus_companies` | `id` |
| `bus_refund_requests` | `created_by` | `bus_refund_requests_created_by_foreign` | `users` | `id` |
| `bus_refund_requests` | `transaction_id` | `bus_refund_requests_transaction_id_foreign` | `transactions` | `id` |
| `bus_refund_requests` | `treasury_id` | `bus_refund_requests_treasury_id_foreign` | `treasuries` | `id` |
| `bus_inventories` | `account_id` | `bus_inventories_account_id_foreign` | `accounts` | `id` |
| `bus_inventories` | `company_id` | `bus_inventories_company_id_foreign` | `bus_companies` | `id` |
| `bus_inventories` | `created_by` | `bus_inventories_created_by_foreign` | `users` | `id` |
| `bus_inventories` | `transaction_id` | `bus_inventories_transaction_id_foreign` | `transactions` | `id` |
| `bus_bookings` | `account_id` | `bus_bookings_account_id_foreign` | `accounts` | `id` |
| `bus_bookings` | `created_by` | `bus_bookings_created_by_foreign` | `users` | `id` |
| `bus_bookings` | `customer_id` | `bus_bookings_customer_id_foreign` | `customers` | `id` |
| `bus_bookings` | `employee_id` | `bus_bookings_employee_id_foreign` | `employees` | `id` |
| `bus_bookings` | `inventory_id` | `bus_bookings_inventory_id_foreign` | `bus_inventories` | `id` |
| `bus_bookings` | `transaction_id` | `bus_bookings_transaction_id_foreign` | `transactions` | `id` |
| `bus_payments` | `account_id` | `bus_payments_account_id_foreign` | `accounts` | `id` |
| `bus_payments` | `booking_id` | `bus_payments_booking_id_foreign` | `bus_bookings` | `id` |
| `bus_payments` | `created_by` | `bus_payments_created_by_foreign` | `users` | `id` |
| `bus_payments` | `transaction_id` | `bus_payments_transaction_id_foreign` | `transactions` | `id` |
| `customers` | `account_id` | `customers_account_id_foreign` | `accounts` | `id` |
| `customers` | `created_by` | `customers_created_by_foreign` | `users` | `id` |
