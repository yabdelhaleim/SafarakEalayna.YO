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
        $rules = [
            'full_name' => 'required|string|max:100',
            'phone' => 'required|string|max:20|unique:customers,phone',
            'national_id' => 'nullable|string|max:20|unique:customers,national_id',
            'passport_number' => 'nullable|string|max:20',
            'passport_expiry' => 'nullable|date',
            'date_of_birth' => 'nullable|date|before_or_equal:today',
            'city' => 'nullable|string|max:100',
            'affiliation' => 'nullable|string|max:100',
            'customer_tier' => 'nullable|in:STANDARD,PREMIUM,VIP,AGENT',
            'notes' => 'nullable|string|max:1000',
            'type' => 'nullable|string|max:50',
            'whatsapp_number' => 'nullable|string|max:50',
            'travel_country' => 'nullable|string|max:100',
        ];

        if (! $this->isCompanyCustomer()) {
            $rules['national_id'] = 'required|string|max:20|unique:customers,national_id';
            $rules['travel_country'] = 'required|string|max:100';
        }

        return $rules;
    }

    protected function isCompanyCustomer(): bool
    {
        return in_array($this->input('type'), ['counter', 'company'], true);
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
            'date_of_birth.before_or_equal' => 'تاريخ الميلاد لا يمكن أن يكون في المستقبل',
            'passport_number.max' => 'رقم الجواز يجب ألا يتجاوز 20 حرفاً',
            'customer_tier.in' => 'فئة العميل يجب أن تكون: STANDARD, PREMIUM, VIP, AGENT',
        ];
    }
}
