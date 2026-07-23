<?php

namespace App\Http\Requests\Online;

use App\Enums\CustomerType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateOnlineCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'email', 'max:255'],
            'national_id' => ['nullable', 'string', 'max:64'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'type' => ['nullable', Rule::in([CustomerType::Individual->value, CustomerType::Company->value])],
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'اسم العميل مطلوب.',
            'full_name.max' => 'اسم العميل طويل جداً (الحد الأقصى 255 حرف).',
            'phone.max' => 'رقم التليفون طويل جداً.',
            'email.email' => 'البريد الإلكتروني غير صحيح.',
            'type.in' => 'نوع العميل غير صحيح.',
        ];
    }
}
