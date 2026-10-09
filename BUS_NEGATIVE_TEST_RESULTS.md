# BUS NEGATIVE TEST RESULTS REPORT

Summary of boundary, validation, and invalid API requests.

| Endpoint | Method | Scenario | Expected HTTP | Actual HTTP | Error Message / Rejection | Status |
| --- | --- | --- | --- | --- | --- | --- |
| `/api/v1/public/bus/inventories/available` | `GET` | Negative: Public Available Inventories Missing Parameters | `422` | `422` | The company id field is required. (and 1 more error) | **PASS** |
| `/api/v1/bus/companies` | `POST` | Negative: Create Company Missing Name | `422` | `422` | بيانات المدخلات غير صالحة. | **PASS** |
| `/api/v1/bus/companies/{id}/pay-debt` | `POST` | Negative: Pay Company Debt When No Debt Owed | `422` | `422` | لا يوجد دين مستحق لهذه الشركة. الرصيد الحالي: 0.00 | **PASS** |
| `/api/v1/bus/inventories` | `POST` | Negative: Create Cash Inventory Missing Account ID | `422` | `422` | بيانات المدخلات غير صالحة. | **PASS** |
| `/api/v1/bus/bookings` | `POST` | Negative: Overbooking Quantity Exceeds Available Tickets | `422` | `422` | لا توجد تذاكر كافية. المتاح: 13 | **PASS** |
| `/api/v1/bus/bookings/{id}/pay` | `POST` | Payment Idempotency: Repeat Payment on Fully Paid Booking | `422` | `422` | بيانات المدخلات غير صالحة. | **PASS** |
| `/api/v1/bus/companies` | `GET` | Security: Unauthenticated Access Blocked | `401` | `401` | Unauthenticated. | **PASS** |
| `/api/v1/bus/companies/{id}/pay-debt` | `POST` | Security: Non-Admin Access Blocked on Admin Route | `403` | `403` | غير مصرح لك بالوصول | **PASS** |
