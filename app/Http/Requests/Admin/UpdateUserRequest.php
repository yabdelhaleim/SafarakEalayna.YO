<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->route('id');

        return [
            'name' => 'sometimes|string|max:100',
            'email' => 'sometimes|email|unique:users,email,'.$userId,
            'role' => 'sometimes|in:admin,employee',
            'salary' => 'nullable|numeric|min:0',
            'status' => 'nullable|in:active,inactive',
        ];
    }

    public function messages(): array
    {
        return [
            'name.string' => 'الاسم يجب أن يكون نصاً',
            'email.email' => 'صيغة البريد الإلكتروني غير صالحة',
            'email.unique' => 'البريد الإلكتروني مستخدم بالفعل',
            'role.in' => 'القيمة المحددة للدور غير صالحة',
        ];
    }
}
