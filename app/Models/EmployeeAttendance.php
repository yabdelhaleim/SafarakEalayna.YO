<?php

namespace App\Models;

use App\Enums\AttendanceStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'employee_id',
    'attendance_date',
    'status',
    'notes',
    'created_by',
])]
class EmployeeAttendance extends Model
{
    protected function casts(): array
    {
        return [
            'attendance_date' => 'date',
            'status' => AttendanceStatus::class,
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeByEmployee($query, $employeeId)
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeByDateRange($query, $from, $to)
    {
        return $query->whereBetween('attendance_date', [$from, $to]);
    }

    public function scopePresent($query)
    {
        return $query->where('status', AttendanceStatus::Present);
    }

    public function scopeAbsent($query)
    {
        return $query->where('status', AttendanceStatus::Absent);
    }

    public function scopeLate($query)
    {
        return $query->where('status', AttendanceStatus::Late);
    }
}
