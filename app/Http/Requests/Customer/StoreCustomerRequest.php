<?php

namespace App\Http\Requests\Customer;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'full_name' => 'required|string|max:100',
            'phone' => 'required|string|max:20|unique:customers,phone',
            'national_id' => 'nullable|string|max:20|unique:customers,national_id',
            'passport_number' => 'nullable|string|max:20',
            'passport_expiry' => 'nullable|date',
            'date_of_birth' => 'nullable|date',
            'city' => 'nullable|string|max:100',
            'affiliation' => 'nullable|string|max:100',
            'customer_tier' => 'nullable|in:STANDARD,PREMIUM',
            'notes' => 'nullable|string|max:1000',
            'type' => 'nullable|string|max:50',
            'whatsapp_number' => 'nullable|string|max:50',
            'travel_country' => 'nullable|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'full_name.required' => 'الاسم مطلوب',
            'full_name.string' => 'الاسم يجب أن يكون نصاً',
            'full_name.max' => 'الاسم يجب ألا يتجاوز 100 حرف',
            'phone.required' => 'رقم الهاتف مطلوب',
            'phone.string' => 'رقم الهاتف يجب أن يكون نصاً',
            'phone.max' => 'رقم الهاتف يجب ألا يتجاوز 20 حرف',
            'phone.unique' => 'رقم الهاتف مستخدم بالفعل',
            'national_id.unique' => 'رقم الهوية مستخدم بالفعل',
            'customer_tier.in' => 'فئة العميل يجب أن تكون: regular, silver, gold, platinum',
        ];
    }
}
