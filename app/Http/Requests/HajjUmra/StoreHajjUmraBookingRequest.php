<?php

namespace App\Http\Requests\HajjUmra;

use App\Enums\HajjUmraStatus;
use App\Rules\HajjUmraLiquidityAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreHajjUmraBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer.full_name' => ['required_without:customer_id', 'nullable', 'string', 'max:255'],
            'customer.phone' => ['required_without:customer_id', 'nullable', 'string', 'max:30'],
            'customer.passport_number' => ['nullable', 'string', 'max:50'],
            'customer.passport_expiry' => ['nullable', 'date'],
            'customer.date_of_birth' => ['nullable', 'date'],
            'customer.city' => ['nullable', 'string', 'max:100'],
            'customer.affiliation' => ['nullable', 'string', 'max:100'],
            'customer.notes' => ['nullable', 'string', 'max:500'],

            'companion_customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'program_id' => ['required', 'integer', 'exists:programs,id'],

            'purchase_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:3'],
            'per_person' => ['nullable', 'boolean'],

            'status' => ['nullable', Rule::in(array_keys(HajjUmraStatus::forDropdown()))],
            'agent_name' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'account_id' => ['required', 'integer', 'exists:accounts,id', new HajjUmraLiquidityAccount],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],

            'initial_payment.amount' => ['nullable', 'numeric', 'min:0'],
            'initial_payment.payment_method' => ['nullable', 'string', 'max:50'],
            'initial_payment.account_id' => ['nullable', 'integer', 'exists:accounts,id', new HajjUmraLiquidityAccount],
            'initial_payment.payment_date' => ['nullable', 'date'],
            'initial_payment.reference' => ['nullable', 'string', 'max:100'],
            'initial_payment.paid_by' => ['nullable', 'string', 'max:150'],

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
