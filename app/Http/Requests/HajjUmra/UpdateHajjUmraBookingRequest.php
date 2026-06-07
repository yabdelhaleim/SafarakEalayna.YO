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

            'supplier_id' => ['nullable', 'integer', 'exists:umrah_suppliers,id'],
            'companion_purchase_price' => ['nullable', 'numeric', 'min:0'],
            'companion_selling_price' => ['nullable', 'numeric', 'min:0'],
            'accommodation_choice' => ['nullable', 'string', 'max:50'],
            'accommodation_extra_charge' => ['nullable', 'numeric', 'min:0'],
            'passengers' => ['nullable', 'array'],
            'passengers.*.category' => ['required_with:passengers', 'string', 'in:adult,child_with_bed,child_no_bed,infant'],
            'passengers.*.count' => ['required_with:passengers', 'integer', 'min:0'],
            'passengers.*.unit_price' => ['required_with:passengers', 'numeric', 'min:0'],
            'passengers.*.subtotal' => ['required_with:passengers', 'numeric', 'min:0'],
        ];
    }
}
