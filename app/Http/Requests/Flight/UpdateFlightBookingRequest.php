<?php

namespace App\Http\Requests\Flight;

use App\Models\Setting\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFlightBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    protected function allowedPaymentMethodCodes(): array
    {
        try {
            $fromDb = PaymentMethod::query()
                ->where('is_active', true)
                ->pluck('code')
                ->map(fn ($c) => strtolower((string) $c))
                ->all();
        } catch (\Throwable) {
            $fromDb = [];
        }

        $fallback = [
            'cash',
            'bank_transfer',
            'cash_wallet',
            'postal_transfer',
            'office_safe',
            'office_drawer',
            'mixed',
            'vodafone_cash',
            'instapay',
        ];

        return array_values(array_unique(array_merge($fromDb, $fallback)));
    }

    public function rules(): array
    {
        $paymentMethods = $this->allowedPaymentMethodCodes();

        return [
            'customer_id' => 'sometimes|integer|exists:customers,id',
            'employee_id' => 'nullable|integer|exists:employees,id',
            'airline_name' => 'sometimes|nullable|string|max:150',
            'airline' => 'sometimes|nullable|string|max:150',
            'system_type' => 'sometimes|nullable|string|max:50',
            'pnr' => 'sometimes|required|string|max:50',
            'trip_type' => 'sometimes|nullable|in:one_way,round_trip,multi_city',
            'from_airport' => 'sometimes|nullable|string|max:10',
            'to_airport' => 'sometimes|nullable|string|max:10',
            'from_airport_id' => 'sometimes|nullable|integer|exists:airports,id',
            'to_airport_id' => 'sometimes|nullable|integer|exists:airports,id',
            'departure_date' => 'sometimes|nullable|date',
            'return_date' => 'sometimes|nullable|date|after_or_equal:departure_date',
            'departure_time' => 'sometimes|nullable',
            'arrival_time' => 'sometimes|nullable',
            'passengers_count' => 'sometimes|nullable|integer|min:1',
            'trip_details' => 'sometimes|nullable|string|max:2000',
            'purchase_price' => 'sometimes|nullable|numeric|min:0',
            'purchase_price_egp' => 'sometimes|nullable|numeric|min:0',
            'purchase_price_foreign' => 'sometimes|nullable|numeric|min:0',
            'exchange_rate' => 'sometimes|nullable|numeric|min:0',
            'selling_price' => 'sometimes|nullable|numeric|min:0',
            'currency' => 'sometimes|nullable|string|size:3',
            'account_id' => 'sometimes|nullable|integer|exists:accounts,id',
            'flight_system_id' => 'sometimes|nullable|integer|exists:flight_systems,id',
            'flight_carrier_id' => 'sometimes|nullable|integer|exists:flight_carriers,id',
            'flight_group_id' => 'sometimes|nullable|integer|exists:flight_groups,id',
            'purchase_balance_source' => 'sometimes|nullable|string|in:carrier,system,group',
            'baggage_allowance_kg' => 'sometimes|nullable|numeric|min:0',
            'notes' => 'sometimes|nullable|string|max:1000',
            'agent_name' => 'sometimes|nullable|string|max:150',
            'passengers' => 'sometimes|required|array|min:1',
            'passengers.*.name' => 'nullable|string|max:200',
            'passengers.*.first_name' => 'required_with:passengers|string|max:100',
            'passengers.*.last_name' => 'required_with:passengers|string|max:100',
            'passengers.*.type' => 'nullable|string|max:20',
            'passengers.*.passenger_type' => 'nullable|string|max:20',
            'passengers.*.passport_number' => 'nullable|string|max:50',
            'passengers.*.national_id' => 'nullable|string|max:50',
            'passengers.*.nationality' => 'nullable|string|max:50',
            'passengers.*.date_of_birth' => 'nullable|date',
            'passengers.*.baggage_allowance_kg' => 'nullable|numeric|min:0',
            'segments' => 'sometimes|nullable|array',
            'segments.*.airline_name' => 'nullable|string|max:150',
            'segments.*.flight_number' => 'nullable|string|max:20',
            'segments.*.from_airport' => 'nullable|string|max:10',
            'segments.*.to_airport' => 'nullable|string|max:10',
            'segments.*.departure_date' => 'nullable|date',
            'segments.*.departure_time' => 'nullable',
            'segments.*.arrival_time' => 'nullable',
            'segments.*.baggage_allowance' => 'nullable|string|max:50',
            'segments.*.flight_class' => 'nullable|string|max:30',
            'payment_method' => ['sometimes', 'nullable', 'string', Rule::in($paymentMethods)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $allowed = [
            'customer_id',
            'employee_id',
            'airline_name',
            'airline',
            'system_type',
            'pnr',
            'trip_type',
            'from_airport',
            'to_airport',
            'from_airport_id',
            'to_airport_id',
            'departure_date',
            'return_date',
            'departure_time',
            'arrival_time',
            'passengers_count',
            'trip_details',
            'purchase_price',
            'purchase_price_egp',
            'purchase_price_foreign',
            'exchange_rate',
            'selling_price',
            'currency',
            'account_id',
            'flight_system_id',
            'flight_carrier_id',
            'flight_group_id',
            'purchase_balance_source',
            'baggage_allowance_kg',
            'notes',
            'agent_name',
            'passengers',
            'segments',
            'payment_method',
        ];

        $input = $this->all();
        foreach ([
            'return_date',
            'departure_date',
            'arrival_time',
            'departure_time',
            'pnr',
            'trip_type',
            'trip_details',
            'notes',
            'airline_name',
            'airline',
            'system_type',
            'from_airport',
            'to_airport',
            'currency',
            'purchase_price_foreign',
            'exchange_rate',
            'purchase_price',
            'purchase_price_egp',
            'selling_price',
            'agent_name',
        ] as $key) {
            if (array_key_exists($key, $input) && $input[$key] === '') {
                $input[$key] = null;
            }
        }

        if (isset($input['passengers']) && is_array($input['passengers'])) {
            foreach ($input['passengers'] as $i => $passenger) {
                if (! is_array($passenger)) {
                    continue;
                }
                foreach (['date_of_birth', 'national_id', 'passport_number'] as $pk) {
                    if (array_key_exists($pk, $passenger) && $passenger[$pk] === '') {
                        $input['passengers'][$i][$pk] = null;
                    }
                }
            }
        }

        $this->replace(array_intersect_key($input, array_flip($allowed)));
    }
}
