<?php

namespace App\Http\Requests\Visa;

use App\Enums\VisaEntryType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVisaBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $bookingCurrency = $this->normalizeCurrency($this->input('currency'));

            foreach ([
                'account_id',
                'initial_payment.account_id',
            ] as $field) {
                $accountId = (int) $this->input($field);
                if ($accountId <= 0) {
                    continue;
                }

                $account = Account::find($accountId);
                if (! $account) {
                    continue;
                }

                $accountCurrency = $this->normalizeCurrency($account->currency);
                if ($bookingCurrency !== $accountCurrency) {
                    $validator->errors()->add(
                        $field,
                        "الحجز بعملة {$bookingCurrency} لكن الحساب المختار بعملة {$accountCurrency}. اختر حساباً بنفس عملة الحجز."
                    );
                }
            }
        });
    }

    protected function normalizeCurrency($currency): string
    {
        $normalized = strtoupper(trim((string) ($currency ?: 'EGP')));

        return $normalized !== '' ? $normalized : 'EGP';
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

            'visa_details.visa_type' => ['required', Rule::in(array_column(VisaType::cases(), 'value'))],
            'visa_details.country' => ['required', 'string', 'max:100'],
            'visa_details.duration' => ['nullable', 'string', 'max:100'],
            'visa_details.visa_duration_id' => ['nullable', 'integer', 'exists:visa_durations,id'],
            'visa_details.entry_type' => ['nullable', Rule::in(array_column(VisaEntryType::cases(), 'value'))],
            'visa_details.validity_from' => ['nullable', 'date'],
            'visa_details.validity_to' => ['nullable', 'date'],
            'visa_details.executing_company' => ['nullable', 'string', 'max:150'],
            'visa_details.executing_agent' => ['nullable', 'string', 'max:150'],
            'visa_details.executing_agent_contact' => ['nullable', 'string', 'max:150'],
            'visa_details.visa_agent_id' => ['nullable', 'integer', 'exists:visa_agents,id'],
            'visa_details.submission_date' => ['nullable', 'date'],
            'visa_details.expected_result_date' => ['nullable', 'date'],
            'visa_details.visa_number' => ['nullable', 'string', 'max:100'],

            'purchase_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'service_fee' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'max:3'],

            'status' => ['nullable', Rule::in(array_keys(VisaStatus::forDropdown()))],
            'agent_name' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],

            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],

            'initial_payment.amount' => ['nullable', 'numeric', 'min:0'],
            'initial_payment.payment_method' => ['nullable', 'string', 'max:50'],
            'initial_payment.account_id' => ['nullable', 'integer', 'exists:accounts,id'],
            'initial_payment.payment_date' => ['nullable', 'date'],
            'initial_payment.reference' => ['nullable', 'string', 'max:100'],
            'initial_payment.paid_by' => ['nullable', 'string', 'max:150'],
        ];
    }
}
