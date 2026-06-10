<?php

namespace App\Http\Requests\Bus;

use Illuminate\Foundation\Http\FormRequest;

class CancelBusBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_penalty' => 'required|numeric|min:0',
            'office_penalty' => 'required|numeric|min:0',
            'account_id' => 'nullable|integer|exists:accounts,id',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'company_penalty.required' => 'خصم شركة الباص مطلوب.',
            'company_penalty.numeric' => 'خصم شركة الباص يجب أن يكون رقماً.',
            'company_penalty.min' => 'خصم شركة الباص لا يمكن أن يكون سالباً.',
            'office_penalty.required' => 'عمولة الإلغاء مطلوبة.',
            'office_penalty.numeric' => 'عمولة الإلغاء يجب أن تكون رقماً.',
            'office_penalty.min' => 'عمولة الإلغاء لا يمكن أن تكون سالبة.',
            'account_id.exists' => 'حساب الصرف المحدد غير صالح.',
            'notes.max' => 'الملاحظات لا يمكن أن تتجاوز 1000 حرف.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'company_penalty' => $this->input('company_penalty', 0),
            'office_penalty' => $this->input('office_penalty', 0),
        ]);
    }
}
