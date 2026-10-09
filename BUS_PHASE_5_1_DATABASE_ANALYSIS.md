# BUS PHASE 5.1 DATABASE QUERY ANALYSIS

Diagnosis of slow SQL queries observed under stress testing:

| Operation | Query / Table | Exec Time (approx) | Executions | Index Status | Scan Type |
| --- | --- | --- | --- | --- | --- |
| Authenticated Bus Company Index (`BusCompanyController@index`) | `SELECT COALESCE(SUM(CASE WHEN accounts.balance < 0 THEN ABS(accounts.balance) ELSE 0 END), 0) AS total_payable... FROM bus_companies JOIN accounts ON bus_companies.account_id = accounts.id` | 180 ms | 50 | Partial (`PRIMARY` on accounts, full scan on bus_companies) | Full Join Scan |
| Inventory Available Check & Lock (`BusBookingService@createBooking`) | `SELECT * FROM bus_inventories WHERE id = ? FOR UPDATE` | 120 ms (excl lock wait time) | 200 | Yes (`PRIMARY` on bus_inventories) | Single Row Lock |
| Booking Lock & Payment Verification (`BusBookingService@payBooking`) | `SELECT * FROM bus_bookings WHERE id = ? FOR UPDATE` | 95 ms (excl lock wait time) | 70 | Yes (`PRIMARY` on bus_bookings) | Single Row Lock |
