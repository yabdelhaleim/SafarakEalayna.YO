<?php

namespace App\Http\Requests\Bus;

use App\Rules\BusLiquidityAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreBusInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => 'required|integer|exists:bus_companies,id',
            'route' => 'required|string|max:200',
            'travel_date' => 'required|date|after_or_equal:today',
            'departure_time' => 'nullable|date_format:H:i',
            'total_tickets' => 'required|integer|min:1',
            'cost_per_ticket' => 'required|numeric|min:0.01',
            'selling_price' => 'required|numeric|min:0.01',
            'payment_type' => 'required|in:cash,deferred',
            'account_id' => ['required_if:payment_type,cash', 'integer', 'exists:accounts,id', new BusLiquidityAccount],
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'company_id.required' => 'The company ID is required.',
            'company_id.exists' => 'The selected company is invalid.',
            'route.required' => 'The route is required.',
            'route.string' => 'The route must be a valid string.',
            'route.max' => 'The route may not be greater than 200 characters.',
            'travel_date.required' => 'The travel date is required.',
            'travel_date.date' => 'The travel date must be a valid date.',
            'travel_date.after_or_equal' => 'The travel date cannot be in the past.',
            'departure_time.date_format' => 'The departure time must be in HH:MM format.',
            'total_tickets.required' => 'The total tickets is required.',
            'total_tickets.integer' => 'The total tickets must be an integer.',
            'total_tickets.min' => 'The total tickets must be at least 1.',
            'cost_per_ticket.required' => 'The cost per ticket is required.',
            'cost_per_ticket.numeric' => 'The cost per ticket must be a number.',
            'cost_per_ticket.min' => 'The cost per ticket must be at least 0.01.',
            'selling_price.required' => 'The selling price is required.',
            'selling_price.numeric' => 'The selling price must be a number.',
            'selling_price.min' => 'The selling price must be at least 0.01.',
            'payment_type.required' => 'The payment type is required.',
            'payment_type.in' => 'The payment type must be cash or deferred.',
            'account_id.required_if' => 'The account is required for cash payments.',
            'account_id.exists' => 'The selected account is invalid.',
            'notes.string' => 'The notes must be a valid string.',
            'notes.max' => 'The notes may not be greater than 1000 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $allowed = [
            'company_id',
            'route',
            'travel_date',
            'departure_time',
            'total_tickets',
            'cost_per_ticket',
            'selling_price',
            'payment_type',
            'account_id',
            'notes',
        ];
        $unknown = array_diff(array_keys($this->all()), $allowed);

        if (! empty($unknown)) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                array_fill_keys($unknown, 'This field is not allowed.')
            );
        }
    }
}
