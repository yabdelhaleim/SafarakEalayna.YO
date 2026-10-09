# VISA MODULE BOUNDARY & ARCHITECTURE

## Executive Overview
The **Visa Module** manages visa service bookings, visa applicant details, supplier agents (`visa_agents`), duration packages (`visa_durations`), customer payments (`visa_payments`), and financial accounting integration (`Transaction`, `AccountEntry`, `TreasuryTransaction`).

## Discovered Components

### Core Database Tables (5)
* `visa_agents`: Visa supplier agents & agencies with account references.
* `visa_durations`: Duration and entry type lookup table.
* `visa_details`: Specific visa metadata (passport details, visa number, dates, agent ID).
* `visa_bookings`: Core booking header (pricing, status, customer ID, financial transaction links).
* `visa_payments`: Collection payments made by customers.

### Eloquent Models (3 Core + 2 Associated)
* `App\Models\VisaBooking`
* `App\Models\VisaDetail`
* `App\Models\VisaPayment`
* `App\Models\HajjUmra\VisaAgent` (or `App\Models\VisaAgent`)
* `App\Models\VisaDuration`

### Service Layer (3)
* `App\Services\Visa\VisaBookingService`: Pagination, creation, happy-path update, payment recording.
* `App\Services\Visa\VisaModificationService`: Re-posting expense and income transactions.
* `App\Services\Visa\VisaRefundService`: Cancellation, refund, and administrative deletion with additive reversal.

### API & Admin Controllers (5)
* `App\Http\Controllers\Api\V1\Visa\VisaBookingController`
* `App\Http\Controllers\Api\V1\Visa\VisaAgentApiController`
* `App\Http\Controllers\Api\V1\Visa\VisaAgentFinanceController`
* `App\Http\Controllers\Api\V1\Visa\VisaTreasuryController`
* `App\Http\Controllers\Api\V1\VisaController`
