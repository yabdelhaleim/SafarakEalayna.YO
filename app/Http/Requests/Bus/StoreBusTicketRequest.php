<?php

namespace App\Http\Requests\Bus;

use Illuminate\Foundation\Http\FormRequest;

class StoreBusTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'passenger_name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'regex:/^01[0-9]{9}$/'],
            'country' => ['nullable', 'string', 'max:255'],
            'bus_name' => ['required', 'string', 'max:255'],
            'ticket_count' => ['required', 'integer', 'min:1'],
            'from_city' => ['required', 'string', 'max:255'],
            'to_city' => ['required', 'string', 'max:255'],
            'departure_date' => ['required', 'date'],
            'departure_time' => ['nullable', 'date_format:H:i'],
            'return_date' => ['nullable', 'date', 'after_or_equal:departure_date'],
            'return_time' => ['nullable', 'date_format:H:i'],
            'purchase_price' => ['required', 'numeric', 'gt:0'],
            'selling_price' => ['required', 'numeric', 'gt:0'],
            'employee_id' => ['required', 'exists:users,id'],
            'payment_method' => ['required', 'in:cash,bank_transfer,cash_wallet,office_safe,office_drawer'],
            'amount' => ['required', 'numeric', 'min:0'],
            'reference_number' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
