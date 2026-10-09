# VISA MODULE — READ-ONLY ACCOUNTING ANALYSIS & REMEDIATION DESIGN
**Date:** 2026-08-15  
**Environment:** `APP_ENV=stress` | `DB_DATABASE=safarak_stress`  
**Author:** Antigravity AI  

---

## 1. OBJECTIVE & SCOPE

This document provides the definitive accounting analysis for the Visa module in SafarakEalayna, detailing the exact financial lifecycle, account mapping, transaction typing, double-entry ledger impact, and comparison with Flight and Hajj/Umrah modules prior to applying code fixes for **VISA-D01** (Class-A) and **VISA-D02** (Class-B), and adding **Payment Idempotency**.

---

## 2. ACCOUNTING LIFECYCLE TRACE

### 2.1 Booking Creation (`VisaBookingService::create`)
When a Visa booking is created with:
- `purchase_price`: Cost of visa from agent/supplier
- `selling_price`: Base selling price to customer
- `service_fee`: Service fee charged to customer
- `total_due` = `selling_price + service_fee`
- `profit` = `(selling_price + service_fee) - purchase_price`

**Transactions Created:**
1. **Expense (Cost AP):**
   - Method: `TransactionService::recordExpense()`
   - Type: `Expense` (or Transfer from Expense Account / Clearing)
   - Account: Agent Account (`visa_agents.account_id`) or Visa Vault (`visas` module vault)
   - Amount: `purchase_price`
   - Ledger Entry: Debit Cost Clearing Account, Credit Supplier/Vault Account
2. **Income (Sale AR):**
   - Method: `TransactionService::recordIncome()`
   - Type: `Income`
   - Account: Customer Ledger Account (`customers.account_id` via `ensureCustomerAccount()`)
   - Amount: `selling_price + service_fee`
   - Ledger Entry: Debit Customer AR Account (receivable created), Credit Revenue Clearing Account

### 2.2 Customer Payment (`VisaBookingService::addPayment`) — The D01 Flaw vs Fix
- **Old (Defective) Path:**
  - Called `TransactionService::recordIncome()` with `related_type=VisaBooking` and `related_id=$booking->id`.
  - Triggered `TransactionService::recordJournalTransfer` duplicate-income guard:
    `Duplicate income transaction blocked for App\Models\VisaBooking#ID. Each booking can have only ONE ACTIVE income transaction (the sale).`
  - Result: HTTP 422 on all payment attempts.
- **New (Remediated) Path:**
  - Customer payment is a **Collection / Transfer**, NOT a new Sale / Revenue event.
  - Method: `TransactionService::recordJournalTransfer()`
  - Type: `TransactionType::Transfer`
  - `from_account_id`: Customer AR Account (`$customerAccount->id`) — Customer debt decreases (Credit AR)
  - `to_account_id`: Receiving Treasury / Cashbox Account (`$accountId`) — Cash increases (Debit Cash)
  - `related_type`: `VisaBooking::class`, `related_id`: `$booking->id`
  - `allow_from_negative`: `true`

### 2.3 Customer Debt Payment (`VisaBookingService::addDebtPayment`)
- Filament statement path for agent/customer debt settlement.
- Uses `recordJournalTransfer` / transfer to settle customer receivables into selected cashbox.

### 2.4 Cancellation (`VisaRefundService::cancel`)
- Status set to `Cancelled`.
- `TransactionService::reverseTransaction()` invoked on active sale and expense transactions.
- Reversal is **Additive**: Appends inverse `AccountEntry` rows (`notes` starting with `عكس `) to cancel out GL contributions without deleting original transaction rows.

### 2.5 Refund (`VisaRefundService::refund`)
- Status set to `Refunded`.
- Reverses existing income/expense transactions additively.

### 2.6 Delete with Reversal (`VisaRefundService::deleteWithReversal`)
- Under `ModelDeletionGuard::run()`, additively reverses all transactions and soft-deletes booking.

---

## 3. CROSS-MODULE COMPARISON

| Feature / Pattern | Flight Module | Hajj & Umrah Module | Visa Module (Old) | Visa Module (Remediated) |
|---|---|---|---|---|
| Sale Revenue Post | `recordIncome` (AR) | `recordIncome` (AR) | `recordIncome` (AR) | `recordIncome` (AR) |
| Customer Collection | `recordJournalTransfer` (Transfer) | `recordJournalTransfer` (Transfer) | `recordIncome` (❌ Broken) | `recordJournalTransfer` (Transfer) ✅ |
| Idempotency Key | `flight_payments.idempotency_key` | `hajj_umra_payments.idempotency_key` | None (❌ Missing) | `visa_payments.idempotency_key` (`vp_idem_uniq`) ✅ |
| Service Price Guard | `create()` & `update()` validate `>= 0` | `create()` & `update()` validate `>= 0` | None (❌ Missing) | `create()` & `update()` throw `InvalidArgumentException` if `< 0` ✅ |

---

## 4. IDEMPOTENCY SPECIFICATION

- **Storage:** `visa_payments.idempotency_key` (VARCHAR 100, nullable).
- **Index:** `UNIQUE (visa_booking_id, idempotency_key)` (`vp_idem_uniq`).
- **Layers:**
  1. **Layer 1 (Pre-check):** In `DB::transaction()` under `VisaBooking::lockForUpdate()`, query existing payment for `(visa_booking_id, idempotency_key)`. If found, set `$payment->idempotent_replay = true` and return immediately without financial mutation.
  2. **Layer 2 (DB Backstop):** Unique index blocks race conditions with SQLSTATE 23000 / MySQL 1062. Handled via `isDuplicateKeyError()` catching and returning the committed winning row.
  3. **HTTP Semantics:**
     - First request: HTTP 201 Created, `idempotent_replay: false`.
     - Replay request: HTTP 200 OK, `idempotent_replay: true`.
