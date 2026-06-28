<?php

namespace App\Http\Requests\Setting;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePrintSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name_ar' => ['nullable', 'string', 'max:200'],
            'company_name_en' => ['nullable', 'string', 'max:200'],
            'logo_path' => ['nullable', 'string', 'max:1000'],
            'logo' => ['nullable', 'file', 'image', 'mimes:png,jpg,jpeg,svg', 'max:2048'],
            'address' => ['nullable', 'string', 'max:1000'],
            'phones' => ['nullable', 'string', 'max:1000'],
            'finance_label' => ['nullable', 'string', 'max:200'],
            'show_amount_due' => ['nullable', 'boolean'],
            'modules' => ['nullable', 'array'],
            'modules.*' => ['nullable', 'array'],
            'modules.*.ticket' => ['nullable', 'boolean'],
            'modules.*.invoice' => ['nullable', 'boolean'],
            'base_capital' => ['nullable', 'numeric', 'min:0'],
            'office_base_capital' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
