<?php

namespace App\Policies;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\AccountModuleDivision;
use App\Support\UserPermissions;

class TransactionPolicy
{
    /**
     * Check if user has finance management capability.
     */
    protected function hasFinanceAccess(User $user): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->isAdmin() || in_array($user->role, ['admin', 'owner'], true)) {
            return true;
        }

        $permissions = is_array($user->permissions)
            ? $user->permissions
            : json_decode((string) $user->permissions, true) ?? [];

        return in_array(UserPermissions::MANAGE_FINANCE, $permissions, true);
    }

    /**
     * Validate that the transaction is strictly a manual operating expense.
     * Rule: related_type is NULL + from_account is liquidity + to_account is expense + not already reversed.
     */
    public function isManualOperatingExpense(Transaction $transaction): bool
    {
        // 1. Must NOT be linked to any external booking, customer, flight, bus, etc.
        if ($transaction->related_type !== null || $transaction->related_id !== null) {
            return false;
        }

        // 2. Must not be already reversed
        if (str_starts_with((string) $transaction->notes, 'عكس:') || str_starts_with((string) $transaction->notes, 'عكس ')) {
            return false;
        }

        // 3. From account must be a liquidity account
        $fromAccount = $transaction->fromAccount;
        if (! $fromAccount) {
            return false;
        }
        $fromType = $fromAccount->type instanceof AccountType ? $fromAccount->type->value : (string) $fromAccount->type;
        if (! in_array($fromType, AccountModuleDivision::LIQUIDITY_TYPES, true)) {
            return false;
        }

        // 4. Must be an expense transaction or a transfer to an expense account
        $rawType = $transaction->type instanceof TransactionType ? $transaction->type->value : (string) $transaction->type;
        if ($rawType === TransactionType::Expense->value) {
            return true;
        }

        if ($rawType === TransactionType::Transfer->value) {
            $toAccount = $transaction->toAccount;
            if (! $toAccount) {
                return false;
            }
            $toType = $toAccount->type instanceof AccountType ? $toAccount->type->value : (string) $toAccount->type;

            return $toType === AccountType::Expense->value;
        }

        return false;
    }

    /**
     * Determine whether the user can view the transaction.
     */
    public function view(User $user, Transaction $transaction): bool
    {
        if ($this->hasFinanceAccess($user)) {
            return true;
        }

        return (int) $transaction->created_by === (int) $user->id;
    }

    /**
     * Determine whether the user can create transactions.
     */
    public function create(User $user): bool
    {
        return $this->hasFinanceAccess($user);
    }

    /**
     * Determine whether the user can update the transaction.
     */
    public function update(User $user, Transaction $transaction): bool
    {
        if (! $this->hasFinanceAccess($user)) {
            return false;
        }

        return $this->isManualOperatingExpense($transaction);
    }

    /**
     * Determine whether the user can delete (void/reverse) the transaction.
     */
    public function delete(User $user, Transaction $transaction): bool
    {
        if (! $this->hasFinanceAccess($user)) {
            return false;
        }

        return $this->isManualOperatingExpense($transaction);
    }
}
