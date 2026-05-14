<?php

namespace App\Http\Requests\Online;

use App\Models\Online\OnlineServiceType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOnlineServiceTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:80',
                'regex:/^[a-zA-Z0-9_\-]+$/',
                Rule::unique('online_service_types', 'code'),
            ],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['required', 'string', 'max:255'],
            'description_ar' => ['nullable', 'string', 'max:1000'],
            'description_en' => ['nullable', 'string', 'max:1000'],
            'color' => ['nullable', 'string', 'max:20'],
            'icon' => ['nullable', 'string', 'max:255'],
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
