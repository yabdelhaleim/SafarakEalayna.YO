<?php

namespace App\Policies;

use App\Models\Bus\BusBooking;
use App\Models\User;

/**
 * Authorization policy for BusBooking.
 *
 * Phase 9 — Bus Module authorization hardening (see audit report §16):
 * The `pay` operation moves money between the customer's AR account and an
 * Office-division liquidity account. Without this policy, ANY authenticated
 * user could record a payment against ANY booking, even one owned by a
 * different employee — a classic IDOR risk.
 *
 * Rules enforced here:
 *   - Admin/owner users (role in {admin, owner}) → can pay any booking.
 *   - The booking's owning employee (employee_id matches current user's
 *     employee.id) → can pay their own booking (legitimate cashier flow).
 *   - Anyone else → 403 Forbidden.
 *
 * Other destructive operations (cancel, delete, refund-process) are gated by
 * `Route::middleware('admin')` in routes/api.php. This policy intentionally
 * does NOT cover those — they remain admin-only.
 *
 * @see \App\Http\Controllers\Api\V1\Bus\BusBookingController::pay
 */
class BusBookingPolicy
{
    /**
     * Determine whether the user can record a payment against the booking.
     *
     * Returns true if EITHER:
     *   (a) The user holds an admin/owner role, OR
     *   (b) The booking was created by the user's linked Employee record.
     */
    public function pay(User $user, BusBooking $booking): bool
    {
        // Admin/owner can pay any booking — oversight / emergency correction flow.
        if (in_array($user->role, ['admin', 'owner'], true)) {
            return true;
        }

        // Cashier flow: an Employee linked to this User may pay their own booking.
        // The booking's employee_id is set by createBooking from the auth user.
        if ($booking->employee_id && $user->employee && $user->employee->id === $booking->employee_id) {
            return true;
        }

        // All other authenticated users are forbidden — this is the IDOR fix.
        return false;
    }
}
