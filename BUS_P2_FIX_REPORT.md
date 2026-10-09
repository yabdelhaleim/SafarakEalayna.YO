# BUS MODULE — P2 FIX REPORT

## 1. Original Vulnerability Summary
`StoreBusInventoryRequest` validated `company_id` using `'required|exists:bus_companies,id'`. This allowed soft-deleted bus companies to pass validation and return HTTP 201 during inventory creation.

## 2. Exact Code Changes Applied
* **File Changed**: [`app/Http/Requests/Bus/StoreBusInventoryRequest.php`](file:///c:/travile/SafarakEalayna/app/Http/Requests/Bus/StoreBusInventoryRequest.php#L18)
* **Rule Updated**:
```php
- 'company_id' => 'required|integer|exists:bus_companies,id',
+ 'company_id' => ['required', 'integer', Rule::exists('bus_companies', 'id')->whereNull('deleted_at')],
```
* **Update Path Inspection**: `UpdateBusInventoryRequest` does not permit `company_id` modifications during update. No update path vulnerability exists.

## 3. Targeted Regression Results
* **Active Company Inventory Creation**: HTTP 201 (PASS)
* **Soft-Deleted Company Inventory Creation**: HTTP 422 (REJECTED - FIXED)
* **Nonexistent Company Inventory Creation**: HTTP 422 (REJECTED)
* **Public Available Inventories**: Soft-deleted company inventories filtered (PASS)
* **Financial Variance**: `0.00 EGP` (100% Reconciled)
* **Targeted Concurrency**: 20 Workers / 10 Tickets -> 0 Overbooking, 0 Deadlocks (PASS)

---

## 4. Final Audit Verdict

Final Status: **FIXED — REGRESSION PASS**
