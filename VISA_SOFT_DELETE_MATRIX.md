# VISA SOFT DELETE MATRIX

## Entities with `SoftDeletes`
* `visa_agents`
* `visa_details`
* `visa_bookings`
* `visa_payments`

## Discovered Validation Boundary Risks

### Risk 1: `StoreVisaBookingRequest` Line 80
```php
'visa_details.visa_agent_id' => ['nullable', 'integer', 'exists:visa_agents,id'],
```
* **Issue**: Omits `whereNull('deleted_at')`. A soft-deleted `visa_agent` passes validation during booking creation.

### Risk 2: `UpdateVisaBookingRequest` Line 41
```php
'visa_details.visa_agent_id' => ['nullable', 'integer', 'exists:visa_agents,id'],
```
* **Issue**: Omits `whereNull('deleted_at')`. A soft-deleted `visa_agent` passes validation during booking update.
