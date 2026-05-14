<?php

namespace App\Http\Requests\Employee;

use Illuminate\Foundation\Http\FormRequest;

class StoreEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'national_id' => 'nullable|string|max:20|unique:employees,national_id',
            'position' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'salary' => 'required|numeric|min:0',
            'hire_date' => 'nullable|date',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'اسم الموظف مطلوب',
            'phone.string' => 'رقم الهاتف يجب أن يكون نصاً',
            'national_id.unique' => 'رقم الهوية مستخدم بالفعل',
            'salary.required' => 'الراتب مطلوب',
            'salary.numeric' => 'الراتب يجب أن يكون رقماً',
            'salary.min' => 'الراتب يجب أن يكون رقماً موجباً',
        ];
    }
}
