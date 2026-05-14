<?php

namespace App\Http\Requests\Visa;

use App\Enums\VisaEntryType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateVisaBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchase_price' => ['sometimes', 'numeric', 'min:0'],
            'selling_price' => ['sometimes', 'numeric', 'min:0'],
            'service_fee' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', Rule::in(array_keys(VisaStatus::forDropdown()))],
            'visa_number' => ['sometimes', 'nullable', 'string', 'max:100'],
            'agent_name' => ['sometimes', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'employee_id' => ['nullable', 'integer', 'exists:users,id'],

            'visa_details' => ['sometimes', 'array'],
            'visa_details.visa_type' => ['sometimes', Rule::in(array_column(VisaType::cases(), 'value'))],
            'visa_details.country' => ['sometimes', 'string', 'max:100'],
            'visa_details.duration' => ['nullable', 'string', 'max:100'],
            'visa_details.visa_duration_id' => ['nullable', 'integer', 'exists:visa_durations,id'],
            'visa_details.entry_type' => ['sometimes', Rule::in(array_column(VisaEntryType::cases(), 'value'))],
            'visa_details.validity_from' => ['nullable', 'date'],
            'visa_details.validity_to' => ['nullable', 'date'],
            'visa_details.executing_company' => ['nullable', 'string', 'max:150'],
            'visa_details.executing_agent' => ['nullable', 'string', 'max:150'],
            'visa_details.executing_agent_contact' => ['nullable', 'string', 'max:150'],
            'visa_details.visa_agent_id' => ['nullable', 'integer', 'exists:visa_agents,id'],
            'visa_details.submission_date' => ['nullable', 'date'],
            'visa_details.expected_result_date' => ['nullable', 'date'],
            'visa_details.visa_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}
