<?php

namespace App\Policies;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class AuditLogPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'owner']);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, AuditLog $auditLog): bool
    {
        return in_array($user->role, ['admin', 'owner']);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'owner']);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, AuditLog $auditLog): bool
    {
        return in_array($user->role, ['admin', 'owner']);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, AuditLog $auditLog): bool
    {
        return in_array($user->role, ['admin', 'owner']);
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, AuditLog $auditLog): bool
    {
        return in_array($user->role, ['admin', 'owner']);
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, AuditLog $auditLog): bool
    {
        return in_array($user->role, ['admin', 'owner']);
    }

    /**
     * عرض سجل التدقيق
     */
    public function viewAuditLog(User $user, AuditLog $log, int $targetUserId): bool
    {
        // المالك يشوف كل حاجة
        if ($user->role === 'owner') {
            return true;
        }

        // الموظف يشوف سجله بس
        return $user->id === $targetUserId;
    }
}
