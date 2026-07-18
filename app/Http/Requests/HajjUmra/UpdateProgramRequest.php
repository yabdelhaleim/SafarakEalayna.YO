<?php

namespace App\Http\Requests\HajjUmra;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('booking_status') === 'active') {
            $this->merge(['booking_status' => 'open']);
        }

        if ($this->input('program_type') === 'umrah') {
            $this->merge(['program_type' => 'umra']);
        }

        if ($type = $this->input('accommodation_type')) {
            $this->merge(['accommodation_type' => strtoupper((string) $type)]);
        }
    }

    public function rules(): array
    {
        return [
            'program_name' => ['sometimes', 'required', 'string', 'max:150'],
            'program_type' => ['sometimes', 'required', Rule::in(['hajj', 'umra'])],
            'season' => ['nullable', 'string', 'max:50'],
            'total_nights' => ['sometimes', 'required', 'integer', 'min:1'],
            'accommodation_type' => ['nullable', 'string', 'max:50'],
            'accommodation_type_id' => ['nullable', 'integer', 'exists:accommodation_types,id'],
            'mecca_hotel_name' => ['nullable', 'string', 'max:150'],
            'mecca_hotel_id' => ['nullable', 'integer', 'exists:hotels,id'],
            'mecca_nights' => ['nullable', 'integer', 'min:0'],
            'medina_hotel_name' => ['nullable', 'string', 'max:150'],
            'medina_hotel_id' => ['nullable', 'integer', 'exists:hotels,id'],
            'medina_nights' => ['nullable', 'integer', 'min:0'],
            'departure_date' => ['sometimes', 'nullable', 'date'],
            'return_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:departure_date'],
            // FIX (GAP #HJ-1, fixed 2026-07-16):
            //   Use `sometimes` so PATCH users don't have to re-send airline/
            //   departure_point when only updating unrelated fields. The DB
            //   NOT NULL constraint is honored because existing rows already
            //   have these values; new POST (StoreProgramRequest) uses
            //   `required` which is the correct rule for create.
            'airline' => ['sometimes', 'nullable', 'string', 'max:100'],
            'departure_point' => ['sometimes', 'nullable', 'string', 'max:100'],
            'trip_supervisor' => ['sometimes', 'nullable', 'string', 'max:150'],
            'trip_supervisor_id' => ['sometimes', 'nullable', 'integer', 'exists:trip_supervisors,id'],
            'executing_company' => ['sometimes', 'nullable', 'string', 'max:150'],
            'executing_company_id' => ['sometimes', 'nullable', 'integer', 'exists:hajj_umra_executing_companies,id'],
            'booking_status' => ['nullable', Rule::in(['open', 'closed', 'success', 'cancelled'])],
            'program_price_tier' => ['nullable', 'string', 'max:50'],
            'default_purchase_price' => ['nullable', 'numeric', 'min:0'],
            'default_selling_price' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
