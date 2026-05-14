<?php

namespace App\Http\Requests\Bus;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'passenger_name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'regex:/^01[0-9]{9}$/'],
            'country' => ['nullable', 'string', 'max:255'],
            'bus_name' => ['sometimes', 'required', 'string', 'max:255'],
            'ticket_count' => ['sometimes', 'required', 'integer', 'min:1'],
            'from_city' => ['sometimes', 'required', 'string', 'max:255'],
            'to_city' => ['sometimes', 'required', 'string', 'max:255'],
            'departure_date' => ['sometimes', 'required', 'date'],
            'departure_time' => ['nullable', 'date_format:H:i'],
            'return_date' => ['nullable', 'date'],
            'return_time' => ['nullable', 'date_format:H:i'],
            'purchase_price' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'selling_price' => ['sometimes', 'required', 'numeric', 'gt:0'],
            'employee_id' => ['sometimes', 'required', 'exists:users,id'],
            'payment_method' => ['sometimes', 'required', 'in:cash,bank_transfer,cash_wallet,office_safe,office_drawer'],
            'amount' => ['sometimes', 'required', 'numeric', 'min:0'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
