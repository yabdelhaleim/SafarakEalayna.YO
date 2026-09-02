# VISA API CATALOG

## API Endpoint Matrix

| Method | Endpoint | Controller Action | Auth | Role | Purpose | Financial Mutation |
| --- | --- | --- | --- | --- | --- | --- |
| `GET` | `/api/v1/visa/bookings` | `VisaBookingController@index` | Sanctum | Employee/Admin | List & filter visa bookings | No |
| `POST` | `/api/v1/visa/bookings` | `VisaBookingController@store` | Sanctum | Employee/Admin | Create visa booking & journal entries | Yes (`recordExpense`, `recordIncome`) |
| `GET` | `/api/v1/visa/bookings/{visa}` | `VisaBookingController@show` | Sanctum | Employee/Admin | Show visa booking details | No |
| `PUT/PATCH` | `/api/v1/visa/bookings/{visa}` | `VisaBookingController@update` | Sanctum | Employee/Admin | Update visa booking & re-post pricing | Yes (re-post transaction) |
| `POST` | `/api/v1/visa/bookings/{visa}/cancel` | `VisaBookingController@cancel` | Sanctum | Employee/Admin | Cancel visa booking (additive reversal) | Yes (inverse entries) |
| `POST` | `/api/v1/visa/bookings/{visa}/refund` | `VisaBookingController@refund` | Sanctum | Employee/Admin | Refund visa booking (additive reversal) | Yes (inverse entries) |
| `DELETE` | `/api/v1/visa/bookings/{visa}` | `VisaBookingController@destroy` | Sanctum | Admin | Admin deletion with full reversal | Yes (inverse entries) |
| `POST` | `/api/v1/visa/bookings/{visa}/payments` | `VisaBookingController@addPayment` | Sanctum | Employee/Admin | Add customer payment collection | Yes (`recordIncome`, Cashbox deposit) |
| `GET` | `/api/v1/visa/treasury/overview` | `VisaTreasuryController@overview` | Sanctum | Admin | Treasury cash balance overview | No |
| `GET` | `/api/v1/visa/agents/dues` | `VisaAgentFinanceController@dues` | Sanctum | Admin | Supplier agent payables summary | No |
| `POST` | `/api/v1/visa/agents/{agent}/withdraw` | `VisaAgentFinanceController@withdraw` | Sanctum | Admin | Supplier advance payout | Yes (`recordExpense`) |
| `POST` | `/api/v1/visa/agents/{agent}/repay` | `VisaAgentFinanceController@repay` | Sanctum | Admin | Supplier debt settlement | Yes (`recordIncome`) |
| `GET` | `/api/v1/visa/customer-balances` | `VisaController@customerBalances` | Sanctum | Admin | Customer AR balance list | No |
| `GET` | `/api/v1/visa/customer-statement` | `VisaController@customerStatement` | Sanctum | Admin | Customer AR ledger statement | No |
| `POST` | `/api/v1/visa/customers/{customer}/pay-debt` | `VisaController@payCustomerDebt` | Sanctum | Admin | Pay customer debt balance | Yes (`recordIncome`) |
