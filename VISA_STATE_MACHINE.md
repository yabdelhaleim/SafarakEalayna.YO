# VISA BUSINESS STATE MACHINE

## Discovered Statuses (`App\Enums\VisaStatus`)
* `draft`: Draft creation state.
* `submitted`: Submitted for processing.
* `under_review`: Under embassy/supplier review.
* `approved`: Approved by embassy.
* `rejected`: Rejected by embassy.
* `issued`: Visa issued successfully.
* `cancelled`: Cancelled by user/customer (additive accounting reversal applied).
* `refunded`: Fully refunded (additive accounting reversal applied).

## State Transition Matrix

| Current State | Target State | Allowed? | Notes |
| --- | --- | --- | --- |
| `draft` | `submitted` | **ALLOWED** | Initial submission |
| `submitted` | `under_review` | **ALLOWED** | Processing state |
| `under_review` | `approved` / `rejected` | **ALLOWED** | Terminal result |
| `approved` | `issued` | **ALLOWED** | Issuance complete |
| `draft`/`submitted`/`approved` | `cancelled` | **ALLOWED** | Reverses journal entries |
| `cancelled` | `submitted`/`approved` | **FORBIDDEN** | Exception thrown |
| `refunded` | `cancelled`/`update` | **FORBIDDEN** | Exception thrown |
| `trashed` | `update`/`payment` | **FORBIDDEN** | Exception thrown |
