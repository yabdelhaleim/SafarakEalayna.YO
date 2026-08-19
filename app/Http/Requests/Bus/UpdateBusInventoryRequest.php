<?php

namespace App\Http\Requests\Bus;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class UpdateBusInventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'route' => 'sometimes|string|max:200',
            'travel_date' => 'sometimes|date',
            'departure_time' => 'sometimes|nullable|date_format:H:i',
            // Step 3 fix: cross-field — new selling_price must be >= existing cost_per_ticket.
            // The bound BusInventory is loaded from the route param {busInventory}.
            'selling_price' => [
                'sometimes',
                'numeric',
                'min:0.01',
                'gte:cost_per_ticket', // compares against existing cost_per_ticket on the bound model
            ],
            'notes' => 'sometimes|nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'route.string' => 'The route must be a valid string.',
            'route.max' => 'The route may not be greater than 200 characters.',
            'travel_date.date' => 'The travel date must be a valid date.',
            'departure_time.date_format' => 'The departure time must be in HH:MM format.',
            'selling_price.numeric' => 'The selling price must be a number.',
            'selling_price.min' => 'The selling price must be at least 0.01.',
            'selling_price.gte' => 'The new selling price must be greater than or equal to the existing cost per ticket (no loss-making trips).',
            'notes.string' => 'The notes must be a valid string.',
            'notes.max' => 'The notes may not be greater than 1000 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $allowed = [
            'route',
            'travel_date',
            'departure_time',
            'selling_price',
            'notes',
        ];
        $unknown = array_diff(array_keys($this->all()), $allowed);

        if (! empty($unknown)) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                array_fill_keys($unknown, 'This field is not allowed.')
            );
        }

        // Step 3 fix: inject the existing inventory's cost_per_ticket into the
        // request data so Laravel's `gte:cost_per_ticket` can compare the new
        // selling_price against the bound model without a custom Rule object.
        $inventory = $this->route('busInventory');
        if ($inventory && method_exists($inventory, 'getAttribute')) {
            $this->merge([
                'cost_per_ticket' => (float) $inventory->cost_per_ticket,
            ]);
        }
    }
}
