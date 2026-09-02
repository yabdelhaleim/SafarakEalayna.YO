# BUS FINANCIAL OPERATION MATRIX

Trace of all financial mutations triggered via API endpoints.

| Operation | API Endpoint | Debit Account | Credit Account | Ledger Entries Recorded | Financial Invariants Verified |
| --- | --- | --- | --- | --- | --- |
| Create Cash Inventory | `POST /api/v1/bus/inventories` | Contra Expense Clearing | Vault Cash Account | Yes | Total cost debited up-front |
| Create Booking | `POST /api/v1/bus/bookings` | Customer AR Account | Income & Expense Contra | Yes | total_price = price * qty, profit calculated |
| Pay Booking | `POST /api/v1/bus/bookings/{id}/pay` | Vault Cash Account | Customer AR Account | Yes | paid_amount updated, status = paid |
| Cancel Booking | `POST /api/v1/bus/bookings/{id}/cancel` | Income & Supplier Contra | Customer AR Account | Yes | Penalties deducted, AR reversed |
| Pay Supplier Debt | `POST /api/v1/bus/companies/{id}/pay-debt` | Supplier Payable Account | Vault Cash Account | Yes | Supplier debt reduced |
