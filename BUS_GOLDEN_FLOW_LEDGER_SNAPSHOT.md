# BUS GOLDEN FLOW LEDGER SNAPSHOT

Generated At: 2026-08-15 14:06:41
Environment: `local` | Database: `safarakealayna`

## Golden Lifecycle Flow Snapshots

| Step | Vault Balance | Companies | Inventories | Bookings | Payments | Supplier Payments | Refunds | Bus Transactions |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 0. Baseline Initial State | 936,700.00 EGP | 38 | 47 | 428 | 24 | 1 | 7 | 901 |
| 1. Create Bus Company | 936,700.00 EGP | 39 | 47 | 428 | 24 | 1 | 7 | 901 |
| 2. Create Deferred Inventory | 936,700.00 EGP | 39 | 48 | 428 | 24 | 1 | 7 | 901 |
| 3. Create Customer | 936,700.00 EGP | 39 | 48 | 428 | 24 | 1 | 7 | 901 |
| 4. Create Bus Booking | 936,700.00 EGP | 39 | 48 | 429 | 24 | 1 | 7 | 903 |
| 5. Pay Bus Booking | 937,000.00 EGP | 39 | 48 | 429 | 25 | 1 | 7 | 904 |
| 6. Pay Company Debt | 936,800.00 EGP | 39 | 48 | 429 | 25 | 2 | 7 | 905 |
| 7. Cancel & Refund Booking | 936,830.00 EGP | 39 | 48 | 430 | 26 | 2 | 8 | 910 |

---

## Verified Golden Flow Entities Manifest

```json
{
    "golden_run_id": "GOLDEN_FLOW_1786802801",
    "environment": "local",
    "database": "safarakealayna",
    "verified_entities": [
        {
            "entity": "BusCompany",
            "id": 52,
            "account_id": 660,
            "name": "GOLDEN_Bus_Lines_1786802801"
        },
        {
            "entity": "BusInventory",
            "id": 52,
            "route": "Cairo - Alexandria Express",
            "total_tickets": 20
        },
        {
            "entity": "Customer",
            "id": 491,
            "name": "Golden Passenger 652"
        },
        {
            "entity": "BusBooking",
            "id": 430,
            "total_price": "300.00",
            "profit": "100.00"
        },
        {
            "entity": "BusPayment",
            "id": 25,
            "amount": 300
        },
        {
            "entity": "BusCompanyPayment",
            "id": 2,
            "amount": 200
        },
        {
            "entity": "BusRefundRequest",
            "id": 9,
            "refund_amount": "120.00"
        }
    ]
}
```
