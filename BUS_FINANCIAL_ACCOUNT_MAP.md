# BUS FINANCIAL ACCOUNT MAP

This document maps the exact financial account structure, division rules, liquidity rules, and double-entry ledger contracts for the **Bus Module**.

## 1. Division & Module Classification Contract

* **Division**: `office` (The Bus Module belongs to the **Office** division alongside Fawry, Online Payments, and Wallet Transfers).
* **Module Key**: `bus` (`TransactionModule::Bus->value`)
* **Base Operating Currency**: `EGP` (All ledger accounts and financial reports normalize to EGP).

---

## 2. Enums and Status Values Map

| Entity | Field | Allowed Values | Meaning / Application Rule | Source |
| --- | --- | --- | --- | --- |
| `bus_bookings` | `status` | `pending`, `paid`, `cancelled`, `refunded`, `partially_refunded` | Booking lifecycle status. Checked in cancellation, payment, refund workflows. | `App\Enums\BusBookingStatus` |
| `bus_bookings` | `payment_status` | `pending`, `partial`, `paid`, `overdue` | Payment progress status. Automatically updated on payment collection. | `App\Enums\BusPaymentStatus` |
| `bus_inventories` | `payment_type` | `cash`, `deferred` | Supplier inventory purchase model. `cash` pays supplier immediately; `deferred` records company debt. | `App\Enums\BusInventoryPaymentType` |
| `bus_payments` | `payment_method` | `cash`, `bank_transfer`, `cash_wallet`, `postal_transfer`, `office_safe`, `office_drawer` | Customer payment channel. Validated prior to database persistence. | `BusBookingService::payBooking` |
| `bus_company_payments` | `status` | `pending`, `paid` | Status of supplier debt settlement payment. | `App\Enums\BusCompanyPaymentStatus` |
| `bus_refund_requests` | `status` | `pending`, `processed`, `rejected` | Refund request workflow state. | `BusRefundService` |
| `bus_refund_requests` | `destination` | `agency_treasury`, `customer_wallet`, `bank_account` | Destination of refunded funds. | `BusRefundService` |

---

## 3. Account Types & Classification

| Account Category | AccountType Enum | Allowed Divisions (`module_type`) | Purpose / Usage in Bus Module |
| --- | --- | --- | --- |
| **Supplier Payable** | `supplier` | N/A | Dedicated ledger account for each `BusCompany` (`bus_companies.account_id`). Holds supplier debt when inventories are purchased on credit (`deferred`). |
| **Customer AR (Receivable)** | `customer` | N/A | Dedicated ledger account for each `Customer` (`customers.account_id`). Created dynamically in booking currency; holds customer debt for unpaid/partially paid bookings. |
| **Liquidity (Vault / Safe)** | `cashbox` | `office` | Office division vault/drawer/safe account used to collect customer payments or pay supplier cash inventories. Must have `is_module_vault = true` or `module_type = 'office'`. |
| **Liquidity (Bank Account)** | `bank` | `office` | Bank account used for bank transfer payments or supplier debt settlement. |
| **Liquidity (Wallet)** | `wallet` | `office` | Electronic wallet account used for digital customer payments. |
| **Expense Clearing Contra** | `expense` | N/A | Contra expense clearing account (`LedgerClearingAccounts::expenseContraIdForModule('bus')`). Offsets inventory cost on sale to avoid profit inflation. |

---

## 4. Liquidity Account Validation Rules (`BusLiquidityAccount`)

To ensure strict separation of funds and prevent invalid accounting postings, all cash/bank payment methods in the Bus Module validate that the target `account_id` meets these strict rules:
1. Account must exist and have `is_active = true`.
2. Account `type` must be a valid liquidity type (`cashbox`, `wallet`, `bank`).
3. Account `module_type` MUST be set to division `'office'` (or `module = 'bus'`). Liquidity accounts under `'tourism'` division are strictly rejected.
4. Fallback mechanism: If `account_id` is omitted, the system resolves `Account::getModuleVault('bus')` which locates the designated office vault.

---

## 5. Active Database Accounts Distribution Summary

| AccountType (`type`) | Division (`module_type`) | Active Count | Total Balance (EGP) |
| --- | --- | --- | --- |
| `customer` | `bus` | 431 | 8,950.00 |
| `cashbox` | `office` | 6 | 1,139,566.00 |
| `owner` | `online` | 2 | -50.00 |
| `customer` | `online` | 1 | 0.00 |
| `owner` | `flights` | 2 | 0.00 |
| `owner` | `fawry` | 2 | 0.00 |
| `supplier` | `visas` | 2 | -8,000.00 |
| `owner` | `visas` | 2 | -550,750.00 |
| `wallet` | `office` | 6 | 35,026.25 |
| `customer` | `wallet_transfer` | 5 | -700.00 |
| `bank` | `office` | 3 | 63,000.00 |
| `owner` | `wallet_transfer` | 2 | -577.00 |
| `supplier` | `bus` | 37 | -6,450.00 |
| `owner` | `bus` | 2 | 61,230.00 |
