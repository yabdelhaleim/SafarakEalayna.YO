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
            'customer.national_id' => ['nullable', 'string', 'max:20'],
            'customer.travel_country' => ['nullable', 'string', 'max:100'],
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

    /**
     * FIX (GAP #HJ-8, fixed 2026-07-16):
     *   Arabic error messages for enum validation. Without these, Laravel
     *   returns English errors like:
     *     "invalid_xyz" is not a valid backing value for enum App\Enums\HajjUmraStatus
     *   which is confusing for Arabic-speaking admins.
     */
    public function messages(): array
    {
        $statusValues = implode('، ', array_keys(HajjUmraStatus::forDropdown()));
        $passengerCategories = 'adult، child_with_bed، child_no_bed، infant';

        return [
            'status.Illuminate\Validation\Rules\In' => "قيمة الحالة غير صحيحة. القيم المسموحة: {$statusValues}.",
            'status.in'                              => "قيمة الحالة غير صحيحة. القيم المسموحة: {$statusValues}.",
            'status.string'                           => 'قيمة الحالة يجب أن تكون نصاً.',

            'program_id.required'  => 'البرنامج مطلوب.',
            'program_id.integer'   => 'معرّف البرنامج يجب أن يكون رقماً صحيحاً.',
            'program_id.exists'    => 'البرنامج غير موجود.',

            'account_id.required'  => 'حساب الخزينة مطلوب.',
            'account_id.integer'   => 'معرّف حساب الخزينة يجب أن يكون رقماً صحيحاً.',
            'account_id.exists'    => 'حساب الخزينة غير موجود.',

            'purchase_price.required' => 'سعر الشراء (التكلفة) مطلوب.',
            'purchase_price.numeric'  => 'سعر الشراء يجب أن يكون رقماً.',
            'purchase_price.min'      => 'سعر الشراء يجب أن يكون >= 0.',

            'selling_price.required'  => 'سعر البيع مطلوب.',
            'selling_price.numeric'   => 'سعر البيع يجب أن يكون رقماً.',
            'selling_price.min'       => 'سعر البيع يجب أن يكون >= 0.',

            'currency.max' => 'العملة يجب ألا تتجاوز 3 أحرف.',

            'passengers.*.category.in' => "فئة الراكب غير صحيحة. القيم المسموحة: {$passengerCategories}.",

            'initial_payment.amount.numeric' => 'مبلغ الدفعة المبدئية يجب أن يكون رقماً.',
            'initial_payment.amount.min'     => 'مبلغ الدفعة المبدئية يجب أن يكون >= 0.',
        ];
    }
}
