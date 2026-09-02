# BUS RACE CONDITION MATRIX

| Race Scenario | Tested Concurrency | Lock Mechanism | Race Outcome | Protected Status |
| --- | --- | --- | --- | --- |
| **Ticket Inventory Overbooking** | 10, 20 Workers | `lockForUpdate()` on `bus_inventories` | Zero overbooking, exact capacity allocation | **PASS** |
| **Last Ticket Lock** | 20 Workers | `lockForUpdate()` on `bus_inventories` | Exactly 1 winner, 19 clean rejections | **PASS** |
| **Same Booking Concurrent Payment** | 10 Workers | `lockForUpdate()` on `bus_bookings` | Exactly 1 full payment, 9 rejected | **PASS** |
| **Partial Payment Cap** | 10 Workers | `lockForUpdate()` on `bus_bookings` | `paid_amount` capped at `total_price` | **PASS** |
| **Simultaneous Payment + Cancel** | 2 Workers | DB Transaction isolation | Reaches valid consistent final state | **PASS** |
| **Double Cancellation** | 10 Workers | `lockForUpdate()` on `bus_bookings` | Exactly 1 cancellation & refund request created | **PASS** |
| **Supplier Debt Settlement Race** | 5 Workers | `lockForUpdate()` on `accounts` | Exactly 1 settlement, zero overpayment | **PASS** |
