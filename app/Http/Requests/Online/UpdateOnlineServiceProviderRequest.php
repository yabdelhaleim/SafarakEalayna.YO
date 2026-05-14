<?php

namespace App\Http\Requests\Online;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOnlineServiceProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('online_service_provider')?->id ?? $this->route('onlineServiceProvider')?->id;

        return [
            'code' => [
                'sometimes',
                'string',
                'max:80',
                'regex:/^[a-zA-Z0-9_\-]+$/',
                Rule::unique('online_service_providers', 'code')->ignore($id),
            ],
            'name_ar' => ['sometimes', 'string', 'max:255'],
            'name_en' => ['sometimes', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:1000'],
            'description_en' => ['nullable', 'string', 'max:1000'],
            'color' => ['nullable', 'string', 'max:20'],
            'icon' => ['nullable', 'string', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:64'],
            'contact_account' => ['nullable', 'string', 'max:128'],
            'metadata' => ['nullable', 'array'],
            'default_purchase_account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'is_active' => ['nullable', 'boolean'],
            'order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code') && is_string($this->code)) {
            $normalized = strtolower(str_replace(['-', ' '], '_', trim($this->code)));
            $this->merge(['code' => preg_replace('/[^a-z0-9_]/', '', $normalized) ?? '']);
        }
    }
}
