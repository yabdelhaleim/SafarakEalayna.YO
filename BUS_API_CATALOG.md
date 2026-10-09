# BUS API CATALOG

This catalog details all public and authenticated endpoints available in the Bus Module.

---

## 1. Public Endpoints

### 1.1 List Public Bus Companies
* **METHOD**: `GET`
* **URL**: `/api/v1/public/bus/companies`
* **AUTHENTICATION**: None
* **AUTHORIZATION**: None
* **REQUEST**: Query parameters (filters: `search`, `is_active`)
* **VALIDATION**: None
* **EXPECTED RESPONSE**: `200 OK` JSON Array of active bus companies using `PublicBusCompanyResource`.
* **SIDE EFFECTS**: None
* **DATABASE CHANGES**: None
* **FINANCIAL EFFECTS**: None

### 1.2 List Public Available Inventories
* **METHOD**: `GET`
* **URL**: `/api/v1/public/bus/inventories/available`
* **AUTHENTICATION**: None
* **AUTHORIZATION**: None
* **REQUEST**: Query parameters (`company_id`, `travel_date`, `route_from`, `route_to`)
* **VALIDATION**: None
* **EXPECTED RESPONSE**: `200 OK` JSON Array of available inventories (`available_tickets > 0`).
* **SIDE EFFECTS**: None
* **DATABASE CHANGES**: None
* **FINANCIAL EFFECTS**: None

---

## 2. Bus Companies Management (`/api/v1/bus/companies`)

### 2.1 List Companies
* **METHOD**: `GET`
* **URL**: `/api/v1/bus/companies`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)
* **AUTHORIZATION**: Active user
* **REQUEST**: Query parameters (`search`, `is_active`, `per_page`, `page`)
* **EXPECTED RESPONSE**: `200 OK` Paginated JSON list of `BusCompanyResource`.

### 2.2 Create Company
* **METHOD**: `POST`
* **URL**: `/api/v1/bus/companies`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)
* **AUTHORIZATION**: Active user
* **REQUIRED FIELDS**: `name`
* **OPTIONAL FIELDS**: `phone`, `address`, `is_active`, `notes`
* **VALIDATION**: `name` (required, string, max:100), `phone` (max:20), `address` (max:500), `is_active` (boolean), `notes` (max:1000)
* **EXPECTED RESPONSE**: `201 Created`
* **SIDE EFFECTS**: Creates dedicated supplier ledger `Account` (`AccountType::SupplierPayable`).
* **DATABASE CHANGES**: Inserts row into `bus_companies` and `accounts`.
* **FINANCIAL EFFECTS**: Registers new supplier payable account in Chart of Accounts.

### 2.3 Show Company
* **METHOD**: `GET`
* **URL**: `/api/v1/bus/companies/{company}`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)
* **AUTHORIZATION**: Active user

### 2.4 Update Company
* **METHOD**: `PUT` / `PATCH`
* **URL**: `/api/v1/bus/companies/{company}`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)
* **AUTHORIZATION**: Active user

### 2.5 Delete Company
* **METHOD**: `DELETE`
* **URL**: `/api/v1/bus/companies/{company}`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)
* **AUTHORIZATION**: Active user
* **SIDE EFFECTS**: Soft deletes `bus_companies` record.

### 2.6 Get Company Statement
* **METHOD**: `GET`
* **URL**: `/api/v1/bus/companies/{company}/statement`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)
* **AUTHORIZATION**: Active user

### 2.7 Pay Company Debt
* **METHOD**: `POST`
* **URL**: `/api/v1/bus/companies/{company}/pay-debt`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)
* **AUTHORIZATION**: Admin (`admin` middleware)
* **REQUIRED FIELDS**: `amount`, `account_id`
* **OPTIONAL FIELDS**: `notes`
* **VALIDATION**: `amount` (numeric, min:0.01), `account_id` (must be liquidity account)
* **EXPECTED RESPONSE**: `200 OK`
* **SIDE EFFECTS**: Records debt settlement payment to bus company.
* **DATABASE CHANGES**: Inserts `bus_company_payments`, `account_entries`, `treasury_transactions`.
* **FINANCIAL EFFECTS**: Decreases vault cash account and decreases company supplier payable account.

---

## 3. Bus Inventories Management (`/api/v1/bus/inventories`)

### 3.1 List Inventories
* **METHOD**: `GET`
* **URL**: `/api/v1/bus/inventories`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)

### 3.2 List Available Inventories
* **METHOD**: `GET`
* **URL**: `/api/v1/bus/inventories/available`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)

### 3.3 Create Inventory
* **METHOD**: `POST`
* **URL**: `/api/v1/bus/inventories`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)
* **REQUIRED FIELDS**: `company_id`, `route`, `travel_date`, `total_tickets`, `cost_per_ticket`, `selling_price`, `payment_type`
* **OPTIONAL FIELDS**: `departure_time`, `account_id` (required if `payment_type = cash`), `notes`
* **VALIDATION**:
  - `cost_per_ticket` >= 0.01
  - `selling_price` >= 0.01
  - `payment_type` in (`cash`, `deferred`)
  - `account_id` validated via `BusLiquidityAccount` if cash
* **EXPECTED RESPONSE**: `201 Created`
* **SIDE EFFECTS**:
  - If cash: pays supplier immediately via cash/vault account.
  - If deferred: creates debt entry for supplier.
* **DATABASE CHANGES**: Inserts `bus_inventories`, journal entries.
* **FINANCIAL EFFECTS**: Updates supplier payable or liquidity account.

### 3.4 Show Inventory
* **METHOD**: `GET`
* **URL**: `/api/v1/bus/inventories/{busInventory}`

### 3.5 Update Inventory
* **METHOD**: `PUT` / `PATCH`
* **URL**: `/api/v1/bus/inventories/{busInventory}`

### 3.6 Delete Inventory
* **METHOD**: `DELETE`
* **URL**: `/api/v1/bus/inventories/{busInventory}`

### 3.7 Pay Inventory Debt
* **METHOD**: `POST`
* **URL**: `/api/v1/bus/inventories/{busInventory}/pay-debt`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)
* **AUTHORIZATION**: Admin (`admin` middleware)

---

## 4. Bus Bookings Management (`/api/v1/bus/bookings`)

### 4.1 List Bookings
* **METHOD**: `GET`
* **URL**: `/api/v1/bus/bookings`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)

### 4.2 Get Booking Stats
* **METHOD**: `GET`
* **URL**: `/api/v1/bus/bookings/stats`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)

### 4.3 Create Booking
* **METHOD**: `POST`
* **URL**: `/api/v1/bus/bookings`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)
* **REQUIRED FIELDS**:
  - `quantity` (integer >= 1)
  - Either `inventory_id` OR (`company_id` + `route` + `selling_price`)
  - Either `customer_id` OR (`customer_name` + `customer_phone`)
* **OPTIONAL FIELDS**: `cost_price`, `travel_date`, `departure_time`, `employee_id`, `notes`
* **VALIDATION**: Checks ticket availability in `bus_inventories` using `lockForUpdate()`.
* **EXPECTED RESPONSE**: `201 Created` JSON `BusBookingResource`
* **SIDE EFFECTS**:
  - Decrements `available_tickets` by `quantity`.
  - Creates Customer AR account if missing.
  - Posts company cost to expense clearing.
  - Posts customer AR sale.
* **DATABASE CHANGES**: Inserts `bus_bookings`, updates `bus_inventories`, inserts `account_entries`.
* **FINANCIAL EFFECTS**:
  - Increases Customer AR balance by `total_price`.
  - Increases Company Payable balance by `total_cost`.
  - Records profit `(selling_price - cost_price) * quantity`.

### 4.4 Show Booking
* **METHOD**: `GET`
* **URL**: `/api/v1/bus/bookings/{busBooking}`

### 4.5 Pay Booking
* **METHOD**: `POST`
* **URL**: `/api/v1/bus/bookings/{busBooking}/pay`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)
* **REQUIRED FIELDS**: `amount`, `payment_method`, `account_id`
* **OPTIONAL FIELDS**: `notes`
* **VALIDATION**:
  - `amount` > 0 and <= remaining balance
  - `payment_method` in (`cash`, `bank_transfer`, `cash_wallet`, `postal_transfer`, `office_safe`, `office_drawer`)
  - `account_id` must be liquidity account
* **EXPECTED RESPONSE**: `200 OK`
* **SIDE EFFECTS**: Recalculates `paid_amount`, `payment_status`, and `status`. Transfers funds from Customer AR account to liquidity vault.
* **DATABASE CHANGES**: Inserts `bus_payments`, updates `bus_bookings`, inserts `account_entries`, `treasury_transactions`.
* **FINANCIAL EFFECTS**: Decreases Customer AR, increases Cash/Vault balance.

### 4.6 Cancel Booking
* **METHOD**: `POST` / `PATCH`
* **URL**: `/api/v1/bus/bookings/{busBooking}/cancel`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)
* **AUTHORIZATION**: Admin (`admin` middleware)
* **REQUIRED FIELDS**: `company_penalty`, `office_penalty`
* **OPTIONAL FIELDS**: `account_id` (required if `refund_amount > 0`), `notes`
* **VALIDATION**:
  - Penalties <= `total_price` and <= `total_paid` (if paid)
* **EXPECTED RESPONSE**: `200 OK` Returns `BusRefundRequest`
* **SIDE EFFECTS**:
  - Increments `available_tickets` by `quantity`.
  - Reverses company cost & customer AR debt.
  - Generates cash refund if `refund_amount > 0`.
* **DATABASE CHANGES**: Updates `bus_bookings.status = cancelled`, `bus_inventories`, inserts `bus_refund_requests`, `account_entries`.
* **FINANCIAL EFFECTS**: Reverses income, clearing, supplier payable, and AR balances.

### 4.7 Delete Booking
* **METHOD**: `DELETE`
* **URL**: `/api/v1/bus/bookings/{busBooking}`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)

---

## 5. Bus Refunds Management (`/api/v1/bus/refunds`)

### 5.1 Get Treasury Options for Refund
* **METHOD**: `GET`
* **URL**: `/api/v1/bus/refunds/treasuries`

### 5.2 Create Refund Request
* **METHOD**: `POST`
* **URL**: `/api/v1/bus/refunds`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)
* **AUTHORIZATION**: Admin (`admin` middleware)

### 5.3 Show Refund Request
* **METHOD**: `GET`
* **URL**: `/api/v1/bus/refunds/{id}`

### 5.4 Process Refund Request
* **METHOD**: `POST`
* **URL**: `/api/v1/bus/refunds/{id}/process`
* **AUTHENTICATION**: Sanctum (`auth:sanctum`)
* **AUTHORIZATION**: Admin (`admin` middleware)

---

## 6. Dashboard, Treasury & Customers

### 6.1 Bus Dashboard Overview
* **METHOD**: `GET`
* **URL**: `/api/v1/bus/dashboard`

### 6.2 Bus Treasury Overview
* **METHOD**: `GET`
* **URL**: `/api/v1/bus/treasury/overview`

### 6.3 Account Bus Transactions
* **METHOD**: `GET`
* **URL**: `/api/v1/bus/treasury/accounts/{account}/bus-transactions`

### 6.4 Bus Customers List
* **METHOD**: `GET`
* **URL**: `/api/v1/bus/customers`
