<?php

namespace App\Http\Requests\Bus;

use App\Models\Bus\BusBooking;
use App\Rules\BusLiquidityAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class PayBusBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bank_transfer,cash_wallet,postal_transfer,office_safe,office_drawer',
            'account_id' => 'required|integer|exists:accounts,id',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Phase 6.B: the route param is now `int $busBooking` (resolved by id
            // in the controller), not a BusBooking instance. Accept both shapes.
            $raw = $this->route('busBooking') ?? $this->route('busBooking');
            $booking = $raw instanceof BusBooking
                ? $raw
                : BusBooking::find((int) $raw);

            if (! $booking instanceof BusBooking) {
                return;
            }
            $booking->loadSum('payments', 'amount');
            $paidSoFar = (float) ($booking->payments_sum_amount ?? 0);
            $remaining = max(0, (float) $booking->total_price - $paidSoFar);
            $amount = (float) $this->input('amount');

            if ($remaining <= 0 && $amount > 0) {
                $validator->errors()->add('amount', 'لا يوجد رصيد متبقٍ على هذا الحجز.');

                return;
            }
            if ($amount > $remaining + 0.000001) {
                $validator->errors()->add(
                    'amount',
                    'المبلغ يتجاوز المتبقي ('.number_format($remaining, 2).' ج.م).'
                );
            }

            // Phase 6.B fix: enforce currency match between booking + payment account.
            $accountId = (int) $this->input('account_id');
            if ($accountId > 0) {
                $account = \App\Models\Account::find($accountId);
                if ($account) {
                    $bookingCurrency = strtoupper((string) ($booking->currency ?? 'EGP'));
                    $accountCurrency = strtoupper((string) $account->currency);
                    if ($bookingCurrency !== $accountCurrency) {
                        $validator->errors()->add(
                            'account_id',
                            "الحجز بعملة {$bookingCurrency} لكن الحساب المختار بعملة {$accountCurrency}. اختر حساباً بنفس عملة الحجز."
                        );
                    }
                    if (! BusLiquidityAccount::belongsToBusModule($account)) {
                        $validator->errors()->add(
                            'account_id',
                            'يجب أن يكون الحساب تابعاً لموديول الباصات أو خزينة قسم المكتب الموحّدة.'
                        );
                    }
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'The payment amount is required.',
            'amount.numeric' => 'The amount must be a valid number.',
            'amount.min' => 'The amount must be at least 0.01.',
            'payment_method.required' => 'The payment method is required.',
            'payment_method.in' => 'The selected payment method is invalid.',
            'account_id.required' => 'The account ID is required.',
            'account_id.exists' => 'The selected account is invalid.',
            'notes.string' => 'The notes must be a valid string.',
            'notes.max' => 'The notes may not be greater than 1000 characters.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $allowed = ['amount', 'payment_method', 'account_id', 'notes'];
        $unknown = array_diff(array_keys($this->all()), $allowed);

        if (! empty($unknown)) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                array_fill_keys($unknown, 'This field is not allowed.')
            );
        }
    }
}
