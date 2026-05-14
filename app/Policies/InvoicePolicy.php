<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['admin', 'owner']);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return in_array($user->role, ['admin', 'owner']);
    }

    public function create(User $user): bool
    {
        return in_array($user->role, ['admin', 'owner']);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return in_array($user->role, ['admin', 'owner']);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return in_array($user->role, ['admin', 'owner']);
    }

    public function restore(User $user, Invoice $invoice): bool
    {
        return in_array($user->role, ['admin', 'owner']);
    }

    public function forceDelete(User $user, Invoice $invoice): bool
    {
        return in_array($user->role, ['admin', 'owner']);
    }
}
