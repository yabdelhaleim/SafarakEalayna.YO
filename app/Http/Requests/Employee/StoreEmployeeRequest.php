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
            'email' => 'nullable|email|max:255|unique:employees,email',
            'phone' => 'nullable|string|max:20',
            'national_id' => 'nullable|string|max:20|unique:employees,national_id',
            'address' => 'nullable|string|max:500',
            'position' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'salary' => 'required|numeric|min:0|max:1000000',
            'hire_date' => 'nullable|date',
            'is_active' => 'nullable|boolean',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'اسم الموظف مطلوب',
            'email.email' => 'البريد الإلكتروني غير صالح',
            'email.unique' => 'البريد الإلكتروني مستخدم بالفعل',
            'phone.string' => 'رقم الهاتف يجب أن يكون نصاً',
            'national_id.unique' => 'رقم الهوية مستخدم بالفعل',
            'salary.required' => 'الراتب مطلوب',
            'salary.numeric' => 'الراتب يجب أن يكون رقماً',
            'salary.min' => 'الراتب يجب أن يكون رقماً موجباً',
            'salary.max' => 'الراتب يتجاوز الحد الأقصى المسموح به',
        ];
    }
}
