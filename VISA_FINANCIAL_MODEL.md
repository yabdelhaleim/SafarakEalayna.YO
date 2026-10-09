# VISA FINANCIAL MODEL

## Double-Entry Journal Formulas

### 1. Booking Creation
* **Supplier Expense / Payable**:
  * Debit: Expense Clearing / Expense Account (`purchase_price`)
  * Credit: Supplier Agent Account (`$agent->account_id`) or Treasury Vault (`purchase_price`)
* **Customer Income / AR**:
  * Debit: Customer AR Account (`ensureCustomerAccount`) (`selling_price + service_fee`)
  * Credit: Revenue / Income Account (`selling_price + service_fee`)
* **Authoritative Profit**:
  $$\text{Profit} = (\text{selling\_price} + \text{service\_fee}) - \text{purchase\_price}$$

### 2. Payment Collection (`addPayment` / `addDebtPayment`)
* Debit: Selected Treasury Cashbox (`account_id`) (`amount`)
* Credit: Customer AR Account (`contra_account_id`) (`amount`)

### 3. Reversal (`cancel` / `refund` / `deleteWithReversal`)
* All reversals use **additive inverse entries** on new or original transaction rows prefixed with `عكس:`. Originals are never mutated.
