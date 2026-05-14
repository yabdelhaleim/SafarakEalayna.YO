<?php

namespace App\Http\Requests\HajjUmra;

use App\Enums\HajjUmraStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateHajjUmraBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'companion_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'purchase_price' => ['sometimes', 'numeric', 'min:0'],
            'selling_price' => ['sometimes', 'numeric', 'min:0'],
            'per_person' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(array_keys(HajjUmraStatus::forDropdown()))],
            'agent_name' => ['sometimes', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
