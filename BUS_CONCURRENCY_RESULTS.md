# BUS CONCURRENCY RESULTS REPORT

Summary of all parallel concurrency test executions.

| Scenario | Target Entity | Parallel Workers | Successes | Rejections | Expected Behavior | Status | Evidence |
| --- | --- | --- | --- | --- | --- | --- | --- |
| Inventory Ticket Race (10 Workers / 10 Tickets) | BusInventory #70 | 10 | 10 | 0 | Exactly 10 successful bookings, 0 tickets remaining, no overbooking | **PASS** | Bookings created: 10, Avail tickets: 0 |
| Inventory Overbooking Race (20 Workers / 10 Tickets) | BusInventory #71 | 20 | 10 | 10 | Exactly 10 successful bookings, 10 clean rejections, available_tickets = 0 | **PASS** | Bookings created: 10, Avail tickets: 0 |
| Last-Ticket Race (20 Workers / 1 Ticket) | BusInventory #72 | 20 | 1 | 19 | Exactly 1 successful booking for the last ticket, 19 rejections, available_tickets = 0 | **PASS** | Bookings created: 1, Avail tickets: 0 |
| Same Booking Concurrent Full Payment (10 Workers / 1000 EGP) | BusBooking #463 | 10 | 1 | 9 | Exactly 1 payment succeeds, 9 rejections, paid_amount = 1000, status = paid | **PASS** | Payment rows created: 1, Total Paid Sum: 1000 EGP |
| Partial Payment Race (10 Workers x 200 EGP on 1000 EGP Booking) | BusBooking #464 | 10 | 5 | 5 | SUM(bus_payments.amount) <= 1000.00 EGP, paid_amount never exceeds total_price | **PASS** | Total Paid Sum: 1000 EGP, Payments Count: 5 |
| Simultaneous Payment + Cancellation Race | BusBooking #465 | 2 | 2 | 0 | System reaches a consistent final state (Paid, Cancelled, or Refunded) with zero orphan entries | **PASS** | Final Booking Status: refunded |
| Double Cancellation Race (10 Workers on 1 Paid Booking) | BusBooking #466 | 10 | 1 | 9 | Exactly 1 cancellation succeeds, tickets restored (+1) exactly once, exactly 1 refund request created | **PASS** | Tickets Before: 4, After: 5, Refund Requests: 1 |
| Supplier Debt Concurrent Settlement (5 Workers / 500 EGP Debt) | BusCompany #62 | 5 | 1 | 4 | Exactly 1 supplier debt payment succeeds, debt balance becomes 0.00, no overpayment | **PASS** | Supplier Account Balance: 0.00 EGP, Payments: 1 |
| Identical Concurrent Booking Creation (10 Workers) | BusInventory #78 | 10 | 10 | 0 | Each valid parallel request creates a distinct booking and decrements inventory cleanly | **PASS** | Bookings Created: 10, Tickets Remaining: 40 |
| Financial Ledger & Double-Entry Invariants Check | Chart of Accounts & Ledger | 1 | 0 | 0 | Total Debits = Total Credits, 0 Overpaid Bookings, 0 Negative Inventories | **PASS** | Debits: 115130, Credits: 115130, Variance: 0 |
