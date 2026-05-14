<?php

namespace App\Http\Requests\Bus;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreBusBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inventory_id' => 'required|integer|exists:bus_inventories,id',
            'customer_id' => 'nullable|integer|exists:customers,id',
            'customer_name' => 'required_without:customer_id|string|max:255',
            'customer_phone' => 'required_without:customer_id|string|max:20',
            'employee_id' => 'nullable|integer|exists:employees,id',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'inventory_id.required' => 'inventory ID مطلوب',
            'inventory_id.exists' => 'الرحلة المحددة غير صالحة',
            'customer_id.exists' => 'العميل المحدد غير صالح',
            'customer_name.required_without' => 'اسم العميل مطلوب',
            'customer_phone.required_without' => 'رقم هاتف العميل مطلوب',
            'employee_id.exists' => 'الموظف المحدد غير صالح',
            'quantity.required' => 'الكمية مطلوبة',
            'quantity.integer' => 'الكمية يجب أن تكون رقماً صحيحاً',
            'quantity.min' => 'الكمية يجب أن تكون 1 على الأقل',
            'notes.string' => 'الملاحظات يجب أن تكون نصاً صالحاً',
            'notes.max' => 'الملاحظات لا يمكن أن تزيد عن 1000 حرف',
        ];
    }

    protected function prepareForValidation(): void
    {
        // If employee_id is not provided, use the authenticated user's employee ID
        if (!$this->has('employee_id') && auth()->check()) {
            $user = auth()->user();
            if ($user->employee) {
                $this->merge([
                    'employee_id' => $user->employee->id,
                ]);
            }
        }
    }
}
