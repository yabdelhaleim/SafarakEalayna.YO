# VISA PHASE 1 RISK REGISTER

## Discovered Risks

### P2 Medium Risk 1: Soft-Deleted Visa Agent Validation Boundary
* **File**: `app/Http/Requests/Visa/StoreVisaBookingRequest.php:80` & `UpdateVisaBookingRequest.php:41`
* **Severity**: **P2 MEDIUM**
* **Issue**: `'exists:visa_agents,id'` omits `whereNull('deleted_at')`.
* **Impact**: Soft-deleted visa agents can be assigned to new or updated visa bookings.

### P3 Low Risk 2: Test Coverage Gap
* **Severity**: **P3 LOW**
* **Issue**: Missing dedicated concurrency and stress test suites for Visa module.
