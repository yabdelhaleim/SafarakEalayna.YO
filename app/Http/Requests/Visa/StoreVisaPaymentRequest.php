<?php

namespace App\Http\Requests\Visa;

use App\Models\Account;
use App\Models\VisaBooking;
use Illuminate\Foundation\Http\FormRequest;

class StoreVisaPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $rawBooking = $this->route('visa');
            $booking = $rawBooking instanceof VisaBooking
                ? $rawBooking
                : VisaBooking::find((int) $rawBooking);

            if (! $booking instanceof VisaBooking) {
                return;
            }

            $bookingCurrency = $this->normalizeCurrency($booking->currency);
            $accountId = (int) $this->input('account_id');
            $account = Account::find($accountId);

            if ($account) {
                $accountCurrency = $this->normalizeCurrency($account->currency);
                if ($bookingCurrency !== $accountCurrency) {
                    $validator->errors()->add(
                        'account_id',
                        "الحجز بعملة {$bookingCurrency} لكن الحساب المختار بعملة {$accountCurrency}. اختر حساباً بنفس عملة الحجز."
                    );
                }
            }

            $paymentCurrency = $this->input('currency');
            if ($paymentCurrency !== null && $this->normalizeCurrency($paymentCurrency) !== $bookingCurrency) {
                $validator->errors()->add(
                    'currency',
                    "الدفعة يجب أن تكون بعملة الحجز ({$bookingCurrency})."
                );
            }
        });
    }

    protected function normalizeCurrency($currency): string
    {
        $normalized = strtoupper(trim((string) ($currency ?: 'EGP')));

        return $normalized !== '' ? $normalized : 'EGP';
    }

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['required', 'string', 'max:50'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'payment_date' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_by' => ['nullable', 'string', 'max:150'],
            'currency' => ['nullable', 'string', 'max:3'],
        ];
    }
}
