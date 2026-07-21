<?php

namespace App\Http\Requests\Flight;

use App\Models\Setting\PaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFlightBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * أكواد طرق الدفع: من جدول الإعدادات + قائمة احتياطية متوافقة مع الحجز القديم.
     *
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
            'customer_id' => 'required|integer|exists:customers,id',
            'agent_name' => 'nullable|string|max:150',
            'employee_id' => 'nullable|integer|exists:employees,id',
            'airline_name' => 'nullable|string|max:150',
            'airline' => 'nullable|string|max:150',
            'system_type' => 'nullable|string|max:50',
            // ✅ S8 FIX: PNR أصبح اختياري للسماح بإنشاء حجوزات PENDING (لتعديل الأسعار لاحقاً)
            'pnr' => 'nullable|string|max:50',
            'trip_type' => 'nullable|in:one_way,round_trip,multi_city',
            'from_airport' => 'nullable|string|max:10',
            'to_airport' => 'nullable|string|max:10',
            'from_airport_id' => 'nullable|integer|exists:airports,id',
            'to_airport_id' => 'nullable|integer|exists:airports,id',
            'departure_date' => 'nullable|date',
            'return_date' => [
                'nullable',
                Rule::requiredIf($this->trip_type === 'round_trip'),
                'date',
                'after_or_equal:departure_date',
            ],
            'return_time' => 'nullable',
            'departure_time' => 'nullable',
            'arrival_time' => 'nullable',
            'passengers_count' => 'nullable|integer|min:1',
            'trip_details' => 'nullable|string|max:2000',
            'purchase_price' => 'nullable|numeric|min:0',
            'purchase_price_egp' => 'nullable|numeric|min:0',
            'purchase_price_foreign' => 'nullable|numeric|min:0',
            'exchange_rate' => 'nullable|numeric|min:0',
            'selling_price' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|size:3',
            'account_id' => 'nullable|integer|exists:accounts,id',
            'airline_account_id' => 'nullable|integer|exists:airline_accounts,id',
            'flight_system_id' => 'nullable|integer|exists:flight_systems,id',
            'flight_carrier_id' => 'nullable|integer|exists:flight_carriers,id',
            'flight_group_id' => 'nullable|integer|exists:flight_groups,id',
            'purchase_balance_source' => 'nullable|string|in:carrier,system,group',
            'booking_channel_type' => ['nullable', 'string', \Illuminate\Validation\Rule::in(\App\Enums\BookingChannelType::validationValues())],
            'booking_channel_provider' => 'nullable|string|max:100',
            'cabin_class' => 'nullable|string|in:economy,premium_economy,business,first',
            'notes' => 'nullable|string|max:1000',
            'passengers' => 'required|array|min:1',
            'passengers.*.name' => 'nullable|string|max:200',
            'passengers.*.first_name' => 'required|string|max:100',
            'passengers.*.last_name' => 'required|string|max:100',
            'passengers.*.type' => 'nullable|string|max:20',
            'passengers.*.passenger_type' => 'nullable|string|max:20',
            'passengers.*.passport_number' => 'nullable|string|max:50',
            'passengers.*.national_id' => 'nullable|string|max:50',
            'passengers.*.nationality' => 'nullable|string|max:50',
            'passengers.*.date_of_birth' => 'nullable|date',
            'passengers.*.baggage_allowance_kg' => 'nullable|numeric|min:0',
            'segments' => 'nullable|array',
            'segments.*.airline_name' => 'nullable|string|max:150',
            'segments.*.flight_number' => 'nullable|string|max:20',
            'segments.*.from_airport' => 'nullable|string|max:10',
            'segments.*.to_airport' => 'nullable|string|max:10',
            'segments.*.departure_date' => 'nullable|date',
            'segments.*.departure_time' => 'nullable',
            'segments.*.arrival_time' => 'nullable',
            'segments.*.baggage_allowance' => 'nullable|string|max:50',
            'segments.*.flight_class' => 'nullable|string|max:30',
            'payment' => 'nullable|array',
            'payment.amount' => 'nullable|numeric|min:0.01',
            'payment.payment_method' => ['nullable', 'string', Rule::in($paymentMethods)],
            'payment.method' => ['nullable', 'string', Rule::in($paymentMethods)],
            'payment.account_id' => 'nullable|integer|exists:accounts,id',
            'payment.notes' => 'nullable|string|max:1000',
            // ✅ Bug fix: allow customer payment in a different currency than the booking currency.
            // When payment.currency differs from booking.currency, the service layer persists
            // it as original_currency/original_amount on the booking row.
            'payment.currency' => ['nullable', 'string', 'size:3'],
            'payment.original_amount' => ['nullable', 'numeric', 'min:0.0001'],
            'payment.original_currency' => ['nullable', 'string', 'size:3'],
            'original_currency' => ['nullable', 'string', 'size:3'],
            'original_amount' => ['nullable', 'numeric', 'min:0.0001'],
            'initial_payment' => 'nullable|numeric|min:0',
            'payment_method' => ['nullable', 'string', Rule::in($paymentMethods)],
        ];
    }

    /**
     * Semantic guard: original_currency must differ from booking sale currency.
     *
     * Reason: if a caller sets original_currency == currency, the field carries no
     * information (no currency conversion happened). The model's saving observer
     * nullifies it, but rejecting at validation time gives a clearer error message
     * to API/Vue callers.
     */
    public function withValidator(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        $validator->after(function ($v) {
            $bookingCurrency = $this->input('currency');
            $originalCurrency = $this->input('original_currency');
            $paymentCurrency = $this->input('payment.currency');

            $bookingCurrency = $bookingCurrency ? strtoupper((string) $bookingCurrency) : null;
            $originalCurrency = $originalCurrency ? strtoupper((string) $originalCurrency) : null;
            $paymentCurrency = $paymentCurrency ? strtoupper((string) $paymentCurrency) : null;

            if ($originalCurrency !== null && $bookingCurrency !== null
                && $originalCurrency === $bookingCurrency) {
                $v->errors()->add(
                    'original_currency',
                    'original_currency يجب أن يختلف عن currency (عملة البيع)، أو يُحذف. الحقل يُسجّل فقط عند الدفع بعملة مختلفة.'
                );
            }

            if ($paymentCurrency !== null && $bookingCurrency !== null
                && $paymentCurrency === $bookingCurrency) {
                $v->errors()->add(
                    'payment.currency',
                    'payment.currency يجب أن يختلف عن currency (عملة البيع)، أو يُحذف. الحقل يُسجّل فقط عند الدفع بعملة مختلفة.'
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $allowedTopLevel = [
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
            'return_time',
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
            'airline_account_id',
            'flight_system_id',
            'flight_carrier_id',
            'flight_group_id',
            'purchase_balance_source',
            'notes',
            'passengers',
            'segments',
            'payment',
            'initial_payment',
            'payment_method',
            'status',
            'booking_number',
            'booking_reference',
            'booking_channel_type',
            'booking_channel_provider',
            'agent_name',
            'cabin_class',
            'flight_number',
            'baggage_allowance_kg',
            'foreign_currency',
            'original_currency',
            'original_amount',
        ];

        $input = $this->all();

        foreach ([
            'return_date',
            'departure_date',
            'arrival_time',
            'departure_time',
            'return_time',
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
            'foreign_currency',
            'purchase_price_foreign',
            'exchange_rate',
            'purchase_price',
            'purchase_price_egp',
            'selling_price',
            'initial_payment',
            'payment_method',
        ] as $key) {
            if (array_key_exists($key, $input) && $input[$key] === '') {
                $input[$key] = null;
            }
        }

        if (isset($input['payment_method']) && is_string($input['payment_method'])) {
            $input['payment_method'] = strtolower(str_replace('-', '_', trim($input['payment_method'])));
        }

        if (isset($input['payment']) && is_array($input['payment'])) {
            foreach (['payment_method', 'method'] as $pk) {
                if (array_key_exists($pk, $input['payment']) && is_string($input['payment'][$pk])) {
                    $input['payment'][$pk] = strtolower(str_replace('-', '_', trim($input['payment'][$pk])));
                }
                if (array_key_exists($pk, $input['payment']) && $input['payment'][$pk] === '') {
                    $input['payment'][$pk] = null;
                }
            }
            foreach (['notes'] as $pk) {
                if (array_key_exists($pk, $input['payment']) && $input['payment'][$pk] === '') {
                    $input['payment'][$pk] = null;
                }
            }
        }

        if (isset($input['passengers']) && is_array($input['passengers'])) {
            foreach ($input['passengers'] as $i => $passenger) {
                if (! is_array($passenger)) {
                    continue;
                }
                foreach (['date_of_birth', 'passport_number', 'nationality', 'national_id'] as $pk) {
                    if (array_key_exists($pk, $passenger) && $passenger[$pk] === '') {
                        $input['passengers'][$i][$pk] = null;
                    }
                }
            }
        }

        if (isset($input['segments']) && is_array($input['segments'])) {
            foreach ($input['segments'] as $i => $seg) {
                if (! is_array($seg)) {
                    continue;
                }
                foreach ([
                    'airline_name',
                    'flight_number',
                    'from_airport',
                    'to_airport',
                    'departure_date',
                    'departure_time',
                    'arrival_time',
                    'baggage_allowance',
                    'flight_class',
                ] as $sk) {
                    if (array_key_exists($sk, $seg) && $seg[$sk] === '') {
                        $input['segments'][$i][$sk] = null;
                    }
                }
            }
        }

        // إزالة أي حقول إضافية من الواجهة/axios بدل رفض الطلب بالكامل (كانت تسبب «فشل التحقق من صحة البيانات»).
        $this->replace(array_intersect_key($input, array_flip($allowedTopLevel)));
    }
}
