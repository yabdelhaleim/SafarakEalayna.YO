<?php

namespace App\Policies;

use App\Models\EmployeeAttendance;
use App\Models\User;

class EmployeeAttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function view(User $user, EmployeeAttendance $employeeAttendance): bool
    {
        return $user->role === 'admin';
    }

    public function create(User $user): bool
    {
        return $user->role === 'admin';
    }

    public function update(User $user, EmployeeAttendance $employeeAttendance): bool
    {
        return $user->role === 'admin';
    }

    public function delete(User $user, EmployeeAttendance $employeeAttendance): bool
    {
        return $user->role === 'admin';
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, EmployeeAttendance $employeeAttendance): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, EmployeeAttendance $employeeAttendance): bool
    {
        return false;
    }
}
