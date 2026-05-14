<?php

namespace App\Http\Resources\Employee;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn() => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'role' => $this->user->role,
            ]),
            'salary' => $this->salary,
            'status' => $this->status,
            'personal_info' => [
                'first_name' => $this->first_name,
                'last_name' => $this->last_name,
                'full_name' => trim($this->first_name . ' ' . $this->last_name),
                'national_id' => $this->national_id,
                'date_of_birth' => $this->date_of_birth?->format('Y-m-d'),
                'gender' => $this->gender,
            ],
            'contact_info' => [
                'phone' => $this->phone,
                'email' => $this->email,
                'address' => $this->address,
                'city' => $this->city,
                'country' => $this->country,
            ],
            'employment_info' => [
                'hire_date' => $this->hire_date?->format('Y-m-d'),
                'termination_date' => $this->termination_date?->format('Y-m-d'),
                'position' => $this->position,
                'department' => $this->department,
                'job_title' => $this->job_title,
                'employment_type' => $this->employment_type,
                'employment_status' => $this->employment_status,
            ],
            'financial_info' => [
                'bank_account_number' => $this->bank_account_number,
                'bank_name' => $this->bank_name,
                'iban' => $this->iban,
            ],
            'emergency_contact' => [
                'name' => $this->emergency_contact_name,
                'phone' => $this->emergency_contact_phone,
            ],
            'performance' => [
                'rating' => $this->performance_rating,
                'contract_path' => $this->contract_path,
            ],
            'bonuses_count' => $this->whenCounted('bonuses'),
            'attendances_count' => $this->whenCounted('attendances'),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
