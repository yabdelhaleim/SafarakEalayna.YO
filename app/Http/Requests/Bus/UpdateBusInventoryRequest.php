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
            'selling_price' => 'sometimes|numeric|min:0.01',
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
    }
}
