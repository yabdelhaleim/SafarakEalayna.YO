# VISA DEPENDENCY GRAPH

## Operational & Financial Flow
```
Customer ──> VisaBooking ──> VisaDetail ──> VisaAgent (Supplier)
                 │
                 ├──> Expense Transaction (recordExpense) ──> Supplier Account / Treasury
                 ├──> Income Transaction (recordIncome)   ──> Customer AR Account
                 └──> VisaPayment (addPayment)            ──> Cashbox Account
```
