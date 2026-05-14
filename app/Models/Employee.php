<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'user_id',
    'salary',
    'status',
    'first_name',
    'last_name',
    'full_name',
    'national_id',
    'nationality',
    'date_of_birth',
    'gender',
    'phone',
    'email',
    'address',
    'city',
    'country',
    'hire_date',
    'termination_date',
    'position',
    'department',
    'job_title',
    'employment_type',
    'employment_status',
    'bank_account_number',
    'bank_name',
    'iban',
    'emergency_contact_name',
    'emergency_contact_phone',
    'performance_rating',
    'contract_path',
])]
class Employee extends Model
{
    protected function casts(): array
    {
        return [
            'salary' => 'decimal:2',
            'date_of_birth' => 'date',
            'hire_date' => 'date',
            'termination_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bonuses()
    {
        return $this->hasMany(\App\Models\Employee\EmployeeBonus::class);
    }

    public function attendances()
    {
        return $this->hasMany(EmployeeAttendance::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
