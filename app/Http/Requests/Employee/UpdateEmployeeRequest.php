<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $employeeId = $this->route('id') ?? $this->route('employee');

        return [
            'full_name' => 'sometimes|string|max:255',
            'email' => 'nullable|email|max:255|unique:employees,email,'.$employeeId,
            'phone' => 'nullable|string|max:20',
            'national_id' => 'nullable|string|max:20|unique:employees,national_id,'.$employeeId,
            'address' => 'nullable|string|max:500',
            'position' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'salary' => 'nullable|numeric|min:0|max:1000000',
            'hire_date' => 'nullable|date',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'email.email' => 'البريد الإلكتروني غير صالح',
            'email.unique' => 'البريد الإلكتروني مستخدم بالفعل',
            'salary.max' => 'الراتب يتجاوز الحد الأقصى المسموح به',
            'national_id.unique' => 'رقم الهوية مستخدم بالفعل',
        ];
    }
}
