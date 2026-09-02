# BUS FUNCTIONAL MATRIX REPORT

Generated At: 2026-08-15 14:08:44 | Environment: `local`

| Endpoint | Method | Auth | Scenario | Expected HTTP | Actual HTTP | Expected DB | Expected Financial | Status |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| `/api/v1/public/bus/companies` | `GET` | `None` | Public List Companies (No filters) | `200` | `200` | No DB mutation | No financial mutation | **PASS** |
| `/api/v1/public/bus/companies` | `GET` | `None` | Public List Companies (Search Filter) | `200` | `200` | No DB mutation | No financial mutation | **PASS** |
| `/api/v1/public/bus/inventories/available` | `GET` | `None` | Public List Available Inventories (Valid company_id & travel_date) | `200` | `200` | No DB mutation | No financial mutation | **PASS** |
| `/api/v1/public/bus/inventories/available` | `GET` | `None` | Negative: Public Available Inventories Missing Parameters | `422` | `422` | No DB mutation | No financial mutation | **PASS** |
| `/api/v1/bus/companies` | `GET` | `Admin Token` | Auth List Companies | `200` | `200` | No DB mutation | No financial mutation | **PASS** |
| `/api/v1/bus/companies` | `POST` | `Admin Token` | Create Bus Company (Valid Payload) | `201` | `201` | bus_companies row created, account_id set | Supplier account created | **PASS** |
| `/api/v1/bus/companies` | `POST` | `Admin Token` | Negative: Create Company Missing Name | `422` | `422` | No DB row inserted | No financial mutation | **PASS** |
| `/api/v1/bus/companies/{id}` | `GET` | `Admin Token` | Show Bus Company Details | `200` | `200` | No DB mutation | No financial mutation | **PASS** |
| `/api/v1/bus/companies/{id}` | `PUT` | `Admin Token` | Update Bus Company Details | `200` | `200` | bus_companies updated | No unexpected financial mutation | **PASS** |
| `/api/v1/bus/companies/{id}/statement` | `GET` | `Admin Token` | Get Company Financial Statement | `200` | `200` | No DB mutation | No financial mutation | **PASS** |
| `/api/v1/bus/companies/{id}/pay-debt` | `POST` | `Admin Token` | Negative: Pay Company Debt When No Debt Owed | `422` | `422` | No DB mutation | No financial mutation | **PASS** |
| `/api/v1/bus/companies/{id}` | `DELETE` | `Admin Token` | Delete Standalone Bus Company | `200` | `200` | deleted_at set on bus_companies | No financial mutation | **PASS** |
| `/api/v1/bus/inventories` | `POST` | `Admin Token` | Create Deferred Inventory via API | `201` | `201` | bus_inventories inserted | Supplier debt recorded | **PASS** |
| `/api/v1/bus/inventories` | `POST` | `Admin Token` | Create Cash Inventory via API | `201` | `201` | bus_inventories inserted | Vault balance decreased by total cost (500 EGP) | **PASS** |
| `/api/v1/bus/inventories` | `POST` | `Admin Token` | Negative: Create Cash Inventory Missing Account ID | `422` | `422` | No DB row inserted | No financial mutation | **PASS** |
| `/api/v1/bus/inventories/{id}` | `PUT` | `Admin Token` | Update Inventory Selling Price | `200` | `200` | bus_inventories updated | Future bookings will use updated price | **PASS** |
| `/api/v1/bus/bookings` | `POST` | `Admin Token` | Create Booking Mode A (explicit inventory_id) | `201` | `201` | bus_bookings inserted, available_tickets decremented | AR sale and company cost journal posted | **PASS** |
| `/api/v1/bus/bookings` | `POST` | `Admin Token` | Create Booking Mode B (auto-create inventory & customer) | `201` | `201` | bus_bookings & bus_inventories inserted | Financial postings created | **PASS** |
| `/api/v1/bus/bookings` | `POST` | `Admin Token` | Negative: Overbooking Quantity Exceeds Available Tickets | `422` | `422` | No DB mutation | No financial mutation | **PASS** |
| `/api/v1/bus/bookings/{id}/pay` | `POST` | `Admin Token` | Pay Bus Booking Full Amount via API | `200` | `200` | bus_payments inserted | Vault balance increased by 220 EGP | **PASS** |
| `/api/v1/bus/bookings/{id}/pay` | `POST` | `Admin Token` | Payment Idempotency: Repeat Payment on Fully Paid Booking | `422` | `422` | No additional bus_payments row | No duplicate financial transfer | **PASS** |
| `/api/v1/bus/bookings/{id}/cancel` | `POST` | `Admin Token` | Cancel Paid Bus Booking with Penalties via API | `200` | `200` | bus_bookings updated, tickets incremented | AR and company cost reversed | **PASS** |
| `/api/v1/bus/refunds/treasuries` | `GET` | `Admin Token` | Get Refund Treasury Options | `200` | `200` | No DB mutation | No financial mutation | **PASS** |
| `/api/v1/bus/customers` | `GET` | `Admin Token` | List Bus Customers | `200` | `200` | No DB mutation | No financial mutation | **PASS** |
| `/api/v1/bus/dashboard` | `GET` | `Admin Token` | Bus Dashboard Overview & Reconciliation | `200` | `200` | No DB mutation | No financial mutation | **PASS** |
| `/api/v1/bus/treasury/overview` | `GET` | `Admin Token` | Bus Treasury Overview | `200` | `200` | No DB mutation | No financial mutation | **PASS** |
| `/api/v1/bus/companies` | `GET` | `Unauthenticated` | Security: Unauthenticated Access Blocked | `401` | `401` | No DB mutation | No financial mutation | **PASS** |
| `/api/v1/bus/companies/{id}/pay-debt` | `POST` | `Normal User Token` | Security: Non-Admin Access Blocked on Admin Route | `403` | `403` | No DB mutation | No financial mutation | **PASS** |
