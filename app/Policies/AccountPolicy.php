<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    /**
     * مستخدم نشط يصل إلى لوحة Filament (مدير أو موظف) يمكنه إدارة سجلات الحسابات المحاسبية.
     */
    protected function canUseFilamentAccounts(User $user): bool
    {
        return (bool) $user->is_active && ($user->isAdmin() || $user->isEmployee());
    }

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $this->canUseFilamentAccounts($user);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Account $account): bool
    {
        return $this->canUseFilamentAccounts($user);
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $this->canUseFilamentAccounts($user);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Account $account): bool
    {
        return $this->canUseFilamentAccounts($user);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Account $account): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Account $account): bool
    {
        return $user->isAdmin();
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Account $account): bool
    {
        return $user->isAdmin();
    }

    /**
     * عرض خزينة المالك
     */
    public function viewOwnerTreasury(User $user): bool
    {
        return $user->role === 'owner';
    }

    /**
     * عرض خزينة المكتب
     */
    public function viewOfficeTreasury(User $user): bool
    {
        return $user->role === 'owner';
    }
}
