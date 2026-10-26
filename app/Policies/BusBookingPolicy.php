<?php

namespace App\Policies;

use App\Models\Bus\BusBooking;
use App\Models\User;
use App\Support\UserPermissions;

/**
 * Authorization policy for BusBooking.
 *
 * Rules enforced here:
 *   - Admin/owner users (role in {admin, owner}) → can pay any booking.
 *   - Cashier flow: the user who created this booking (created_by matches current
 *     user's id) → can pay their own booking.
 *   - Cashier flow: an Employee linked to this User may pay their assigned
 *     booking (employee_id matches current user's employee.id).
 *   - Module permissions: users with manage_bus permission → can pay bookings.
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
     */
    public function pay(User $user, BusBooking $booking): bool
    {
        // Admin/owner can pay any booking — oversight / emergency correction flow.
        if (in_array($user->role, ['admin', 'owner'], true)) {
            return true;
        }

        // Cashier flow: the user who created this booking can record payment.
        if ($booking->created_by && (int) $booking->created_by === (int) $user->id) {
            return true;
        }

        // Cashier flow: an Employee linked to this User may pay their own booking.
        if ($booking->employee_id && $user->employee && (int) $user->employee->id === (int) $booking->employee_id) {
            return true;
        }

        // Module permissions: users with manage_bus permission can pay bookings.
        if (in_array(UserPermissions::MANAGE_BUS, UserPermissions::effectiveFor($user), true)) {
            return true;
        }

        // All other authenticated users are forbidden — this is the IDOR fix.
        return false;
    }

    /**
     * Determine whether the user can cancel/refund the booking.
     */
    public function cancel(User $user, BusBooking $booking): bool
    {
        // Admin/owner can cancel any booking.
        if (in_array($user->role, ['admin', 'owner'], true)) {
            return true;
        }

        // Cashier flow: the user who created this booking can cancel/refund it.
        if ($booking->created_by && (int) $booking->created_by === (int) $user->id) {
            return true;
        }

        // Cashier flow: an Employee linked to this User may cancel/refund their own booking.
        if ($booking->employee_id && $user->employee && (int) $user->employee->id === (int) $booking->employee_id) {
            return true;
        }

        // Module permissions: users with manage_refunds or manage_bus can cancel/refund bookings.
        $effective = UserPermissions::effectiveFor($user);
        if (in_array(UserPermissions::MANAGE_REFUNDS, $effective, true) ||
            in_array(UserPermissions::MANAGE_BUS, $effective, true)) {
            return true;
        }

        // All other authenticated users are forbidden.
        return false;
    }
}
