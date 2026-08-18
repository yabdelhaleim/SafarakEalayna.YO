<?php

namespace App\Policies;

use App\Models\Flight\FlightBooking;
use App\Models\User;

/**
 * Authorization policy for FlightBooking — Phase 2 (B-1) authorization hardening.
 *
 * Defect B-1 (Tourism Full Audit 2026-08-18):
 *   Flight payment endpoint accepted payments from ANY authenticated user with
 *   `manage_flights` permission, regardless of which customer owned the booking.
 *   This was a classic IDOR — anyone could pay any customer's flight booking.
 *
 * Rules enforced here:
 *   - Admin/owner users (role in {admin, owner}) → can pay any booking.
 *   - The booking's owning employee (employee_id matches current user's
 *     employee.id) → can pay their own booking (legitimate cashier flow).
 *   - Anyone else → 403 Forbidden.
 *
 * The customer_id on the payment is ALWAYS derived from the booking via
 * route-model binding (FlightBooking $flightBooking) — it is NEVER accepted
 * from the request payload. See StoreFlightPaymentRequest::prepareForValidation()
 * for the whitelist that enforces this.
 *
 * Other destructive operations (cancel, delete) remain gated by the project's
 * standard permission middleware (`manage_flights`). This policy intentionally
 * focuses on the B-1 fix (payment authorization) to keep the blast radius small.
 *
 * @see \App\Http\Controllers\Api\V1\Flight\FlightController::addPayment
 * @see \App\Http\Requests\Flight\StoreFlightPaymentRequest
 */
class FlightBookingPolicy
{
    /**
     * Determine whether the user can record a payment against the booking.
     *
     * Returns true if EITHER:
     *   (a) The user holds an admin/owner role, OR
     *   (b) The booking was created by the user's linked Employee record
     *       (employee_id matches user->employee->id).
     *
     * @param  FlightBooking  $booking  The FlightBooking from route-model binding.
     * @return bool                      true = allowed, false = 403 Forbidden.
     */
    public function pay(User $user, FlightBooking $booking): bool
    {
        // Admin/owner oversight + emergency correction path.
        if (in_array($user->role, ['admin', 'owner'], true)) {
            return true;
        }

        // Cashier flow: the Employee linked to this User may pay their own booking.
        // The booking's employee_id is set by createBooking() from the auth user.
        if ($booking->employee_id && $user->employee && $user->employee->id === $booking->employee_id) {
            return true;
        }

        // All other authenticated users are forbidden — this is the IDOR fix.
        return false;
    }

    /**
     * Determine whether the user can cancel the booking.
     *
     * Mirrors the pay() rule so the same employee who created the booking is
     * the one who can cancel it (consistent with the no-edit contract —
     * cancellation is the supported correction path).
     */
    public function cancel(User $user, FlightBooking $booking): bool
    {
        if (in_array($user->role, ['admin', 'owner'], true)) {
            return true;
        }

        if ($booking->employee_id && $user->employee && $user->employee->id === $booking->employee_id) {
            return true;
        }

        return false;
    }
}