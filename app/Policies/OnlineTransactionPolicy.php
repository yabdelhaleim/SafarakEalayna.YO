<?php

namespace App\Policies;

use App\Models\Online\OnlineTransaction;
use App\Models\User;

/**
 * Authorization policy for OnlineTransaction — SEC-3 IDOR hardening.
 *
 * Defect SEC-3 (Online Audit 2026-08-21):
 *   `GET /api/v1/online/transactions/{id}` (and the parallel PATCH /
 *   DELETE) accepted ANY authenticated user with `manage_online`
 *   permission, regardless of which employee created the row. A standard
 *   cross-resource IDOR — any cashier could read or modify any other
 *   cashier's sales, including the ones tied to a different AR mirror
 *   and a different vault balance.
 *
 * Rules enforced here:
 *   - Admin/owner users (role in {admin, owner}) → full access to every
 *     transaction (oversight / emergency correction flow).
 *   - The transaction's owning employee (employee_id matches current
 *     user's employee.id) → can view + modify + delete their own sales
 *     (legitimate cashier flow).
 *   - Anyone else → 403 Forbidden.
 *
 * Why this is a policy and not a route middleware:
 *   The route-level `permission:manage_online` middleware (SEC-1) gates
 *   the URL by capability. The policy gates the *resource* by ownership.
 *   They are complementary layers: middleware says "do you have the right
 *   tool", policy says "do you have the right to act on THIS row".
 *
 * Mirrors the established `BusBookingPolicy::pay` and
 * `FlightBookingPolicy::pay/cancel` patterns (Phase 9 / 2026-08-15).
 *
 * @see \App\Http\Controllers\Api\V1\Online\OnlineTransactionController::show
 * @see \App\Http\Controllers\Api\V1\Online\OnlineTransactionController::update
 * @see \App\Http\Controllers\Api\V1\Online\OnlineTransactionController::destroy
 */
class OnlineTransactionPolicy
{
    /**
     * Determine whether the user can view the transaction.
     *
     * Returns true if EITHER:
     *   (a) The user holds an admin/owner role, OR
     *   (b) The transaction was created by the user's linked Employee
     *       (employee_id matches user->employee->id).
     */
    public function view(User $user, OnlineTransaction $tx): bool
    {
        return $this->isOwnerOrAdmin($user, $tx);
    }

    /**
     * Determine whether the user can update (PATCH) the transaction.
     *
     * Same rule as view(): admins own everything; otherwise the
     * transaction's owning employee is the only legitimate updater.
     */
    public function update(User $user, OnlineTransaction $tx): bool
    {
        return $this->isOwnerOrAdmin($user, $tx);
    }

    /**
     * Determine whether the user can delete the transaction.
     *
     * Same rule as view()/update(). The route layer already gates DELETE
     * behind `role:admin` (see routes/api.php:446), so in practice only
     * admins reach this method. We keep the policy call as defense in
     * depth — if the route middleware is ever loosened, the policy still
     * enforces ownership-based access.
     */
    public function delete(User $user, OnlineTransaction $tx): bool
    {
        return $this->isOwnerOrAdmin($user, $tx);
    }

    /**
     * Shared owner/admin check.
     *
     * @return bool true = allowed, false = 403 Forbidden.
     */
    private function isOwnerOrAdmin(User $user, OnlineTransaction $tx): bool
    {
        // Admin/owner oversight + emergency correction path.
        if (in_array($user->role, ['admin', 'owner'], true)) {
            return true;
        }

        // Cashier flow: the Employee linked to this User may view / edit /
        // delete their own transaction. The employee_id is set by
        // `OnlineTransactionService::create()` from the auth user.
        if ($tx->employee_id && $user->employee && $user->employee->id === $tx->employee_id) {
            return true;
        }

        // All other authenticated users are forbidden — this is the IDOR fix.
        return false;
    }
}
