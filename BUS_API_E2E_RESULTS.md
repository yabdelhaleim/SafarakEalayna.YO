# BUS API E2E RESULTS REPORT

Summary of all successful positive functional API workflows.

| Endpoint | Method | Scenario | HTTP Status | Business Result | DB Verification | Status |
| --- | --- | --- | --- | --- | --- | --- |
| `/api/v1/public/bus/companies` | `GET` | Public List Companies (No filters) | `200` | Returns list of active public companies | No DB mutation | **PASS** |
| `/api/v1/public/bus/companies` | `GET` | Public List Companies (Search Filter) | `200` | Returns filtered public companies | No DB mutation | **PASS** |
| `/api/v1/public/bus/inventories/available` | `GET` | Public List Available Inventories (Valid company_id & travel_date) | `200` | Returns available inventories with tickets > 0 | No DB mutation | **PASS** |
| `/api/v1/bus/companies` | `GET` | Auth List Companies | `200` | Returns paginated list of bus companies with stats | No DB mutation | **PASS** |
| `/api/v1/bus/companies` | `POST` | Create Bus Company (Valid Payload) | `201` | Creates company and links supplier account in Chart of Accounts | bus_companies row created, account_id set | **PASS** |
| `/api/v1/bus/companies/{id}` | `GET` | Show Bus Company Details | `200` | Returns company model with supplier account | No DB mutation | **PASS** |
| `/api/v1/bus/companies/{id}` | `PUT` | Update Bus Company Details | `200` | Company fields updated, supplier account remains linked | bus_companies updated | **PASS** |
| `/api/v1/bus/companies/{id}/statement` | `GET` | Get Company Financial Statement | `200` | Returns list of company account transactions | No DB mutation | **PASS** |
| `/api/v1/bus/companies/{id}` | `DELETE` | Delete Standalone Bus Company | `200` | Company soft-deleted | deleted_at set on bus_companies | **PASS** |
| `/api/v1/bus/inventories` | `POST` | Create Deferred Inventory via API | `201` | Creates inventory allocation on credit | bus_inventories inserted | **PASS** |
| `/api/v1/bus/inventories` | `POST` | Create Cash Inventory via API | `201` | Creates cash inventory and pays total cost upfront from vault | bus_inventories inserted | **PASS** |
| `/api/v1/bus/inventories/{id}` | `PUT` | Update Inventory Selling Price | `200` | Inventory selling price updated | bus_inventories updated | **PASS** |
| `/api/v1/bus/bookings` | `POST` | Create Booking Mode A (explicit inventory_id) | `201` | Booking created, 2 tickets locked (13 left), status pending, AR sale recorded | bus_bookings inserted, available_tickets decremented | **PASS** |
| `/api/v1/bus/bookings` | `POST` | Create Booking Mode B (auto-create inventory & customer) | `201` | Auto-creates inventory and customer, records booking and financial postings | bus_bookings & bus_inventories inserted | **PASS** |
| `/api/v1/bus/bookings/{id}/pay` | `POST` | Pay Bus Booking Full Amount via API | `200` | Booking paid_amount updated to 220, payment_status=paid, status=paid, cash transferred to vault | bus_payments inserted | **PASS** |
| `/api/v1/bus/bookings/{id}/cancel` | `POST` | Cancel Paid Bus Booking with Penalties via API | `200` | Booking status updated to cancelled/refunded, seat restored to inventory, refund request created for 190 EGP | bus_bookings updated, tickets incremented | **PASS** |
| `/api/v1/bus/refunds/treasuries` | `GET` | Get Refund Treasury Options | `200` | Returns list of valid treasuries for cash refund payouts | No DB mutation | **PASS** |
| `/api/v1/bus/customers` | `GET` | List Bus Customers | `200` | Returns list of customers linked to bus bookings | No DB mutation | **PASS** |
| `/api/v1/bus/dashboard` | `GET` | Bus Dashboard Overview & Reconciliation | `200` | Returns module KPIs matching DB calculations | No DB mutation | **PASS** |
| `/api/v1/bus/treasury/overview` | `GET` | Bus Treasury Overview | `200` | Returns overview of bus liquidity accounts and total balances | No DB mutation | **PASS** |
