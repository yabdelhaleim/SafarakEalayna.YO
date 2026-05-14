<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class EmployeePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function view(User $user, Employee $employee): bool
    {
        return $user->role === 'admin';
    }

    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function update(User $user, Employee $employee): bool
    {
        return $user->role === 'admin';
    }

    public function delete(User $user, Employee $employee): bool
    {
        return $user->role === 'admin';
    }

    public function restore(User $user, Employee $employee): bool
    {
        return $user->role === 'admin';
    }

    public function forceDelete(User $user, Employee $employee): bool
    {
        return $user->role === 'admin';
    }
}
