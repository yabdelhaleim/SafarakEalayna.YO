<?php

namespace App\Http\Requests\Flight;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreAviationBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'booking_reference' => 'required|string|unique:flight_bookings,booking_reference',
            'agent_name' => 'required|string',
            'customer.phone' => 'required|string',
            'customer.full_name' => 'required|string|min:5',
            'customer.national_id' => 'nullable|string|size:14',
            'customer.customer_tier' => 'nullable|in:STANDARD,PREMIUM',
            'pricing.currency' => 'required|in:EGP,KWD,SAR,USD,EUR,AED',
            'pricing.purchase_price' => 'required_if:pricing.currency,EGP|numeric|min:0',
            'pricing.selling_price' => 'required_if:pricing.currency,EGP|numeric|min:0',
            'pricing.amount_in_foreign_currency' => 'required_unless:pricing.currency,EGP|numeric|min:0',
            'pricing.exchange_rate_used' => 'required_unless:pricing.currency,EGP|numeric|min:0',
            'pricing.selling_price_egp' => 'required_unless:pricing.currency,EGP|numeric|min:0',
            'flight.origin' => 'required|string|size:3',
            'flight.destination' => 'required|string|size:3',
            'flight.departure_date' => 'required|date|after_or_equal:today',
            'flight.departure_time' => 'required|string',
            'flight.trip_type' => 'required|in:ONE_WAY,ROUND_TRIP',
            'flight.airline' => 'required|string',
            'booking_channel.type' => 'required|in:SYSTEM,SIGN,GROUP',
            'booking_channel.provider' => 'required|string',
            'passengers' => 'required|array|min:1',
            'passengers.*.first_name' => 'required|string',
            'passengers.*.last_name' => 'required|string',
            'passengers.*.date_of_birth' => 'required|date|before:today',
            'payment.payment_method' => 'nullable|string',
            'payment.amount' => 'nullable|numeric|min:0',
            'payment.treasury_account' => 'nullable|string',
            'payment.account_id' => 'nullable|integer|exists:accounts,id',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $amount = (float) ($this->input('payment.amount', 0) ?? 0);
            if ($amount <= 0) {
                return;
            }
            $acc = $this->input('payment.account_id');
            $tro = $this->input('payment.treasury_account');
            if (($acc === null || $acc === '') && ($tro === null || $tro === '')) {
                $v->errors()->add(
                    'payment.account_id',
                    'عند إدخال مبلغ دفع يجب تحديد payment.account_id أو payment.treasury_account.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'booking_reference.unique' => 'رقم المرجع مسجل مسبقاً في النظام',
            'customer.full_name.min' => 'اسم العميل يجب أن لا يقل عن 5 أحرف',
            'flight.origin.size' => 'كود المطار يجب أن يكون 3 أحرف',
            'flight.destination.size' => 'كود المطار يجب أن يكون 3 أحرف',
            'flight.departure_date.after_or_equal' => 'تاريخ السفر لا يمكن أن يكون في الماضي',
            'pricing.purchase_price.required_if' => 'سعر الشراء مطلوب عند الحجز بالجنيه المصري',
            'passengers.*.date_of_birth.before' => 'تاريخ الميلاد غير منطقي',
        ];
    }
}
