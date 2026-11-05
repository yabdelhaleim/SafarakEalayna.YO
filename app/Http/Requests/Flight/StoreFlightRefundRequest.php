<?php

namespace App\Http\Requests\Flight;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreFlightRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'airline_penalty' => 'required|numeric|min:0',
            'office_penalty' => 'required|numeric|min:0',
            'account_id' => 'nullable|integer|exists:accounts,id',
            'notes' => 'nullable|string|max:1000',
            'refund_case' => 'nullable|string|in:customer_voluntary,airline_cancelled',
            'destination' => 'nullable|string|in:cash,customer_credit,company_offset',
        ];
    }

    public function messages(): array
    {
        return [
            'airline_penalty.required' => 'خصم الطيران مطلوب.',
            'airline_penalty.numeric' => 'خصم الطيران يجب أن يكون رقماً.',
            'airline_penalty.min' => 'خصم الطيران لا يمكن أن يكون سالباً.',
            'office_penalty.required' => 'عمولة الإلغاء مطلوبة.',
            'office_penalty.numeric' => 'عمولة الإلغاء يجب أن تكون رقماً.',
            'office_penalty.min' => 'عمولة الإلغاء لا يمكن أن تكون سالبة.',
            'account_id.required' => 'حساب الصرف مطلوب عند وجود مبلغ مرتجع.',
            'account_id.exists' => 'حساب الصرف المحدد غير صالح.',
            'notes.string' => 'الملاحظات يجب أن تكون نصاً صالحاً.',
            'notes.max' => 'الملاحظات لا يمكن أن تتجاوز 1000 حرف.',
            'refund_case.in' => 'نوع الإلغاء غير صالح.',
            'destination.in' => 'طريقة تسوية الاسترداد غير صالحة.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $allowed = [
            'airline_penalty',
            'office_penalty',
            'account_id',
            'notes',
            'refund_case',
            'destination',
        ];

        $unknown = array_diff(array_keys($this->all()), $allowed);

        if (!empty($unknown)) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                array_fill_keys($unknown, 'This field is not allowed.')
            );
        }
    }
}
