<?php

namespace App\Http\Resources\Employee;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeAttendanceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_id' => $this->employee_id,
            'employee' => $this->whenLoaded('employee', fn () => [
                'id' => $this->employee->id,
                'name' => $this->employee->full_name
                    ?: trim(($this->employee->first_name ?? '').' '.($this->employee->last_name ?? ''))
                    ?: $this->employee->user?->name,
                'first_name' => $this->employee->first_name,
                'last_name' => $this->employee->last_name,
                'position' => $this->employee->position,
                'department' => $this->employee->department,
            ]),
            'attendance_date' => $this->attendance_date?->format('Y-m-d'),
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'check_in' => $this->check_in ? substr((string) $this->check_in, 0, 5) : null,
            'check_out' => $this->check_out ? substr((string) $this->check_out, 0, 5) : null,
            'status_label' => match ($this->status instanceof \BackedEnum ? $this->status->value : $this->status) {
                'present' => 'حاضر',
                'absent' => 'غائب',
                'late' => 'متأخر',
                default => (string) $this->status,
            },
            'notes' => $this->notes,
            'created_by' => $this->whenLoaded('createdBy', fn() => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ]),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
