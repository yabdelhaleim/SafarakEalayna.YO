<?php

namespace App\Http\Requests\Online;

use App\Enums\OnlineTransactionStatus;
use App\Models\Account;
use App\Models\Online\OnlineTransaction;
use App\Rules\OnlineLiquidityAccount;
use App\Support\Finance\PaymentMethodAccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOnlineTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_type_code' => ['sometimes', 'string', 'max:80'],
            'provider_code' => ['nullable', 'string', 'max:80'],

            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:64'],
            'customer_country' => ['nullable', 'string', 'max:120'],

            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],

            'purchase_price' => ['sometimes', 'numeric', 'min:0'],
            'selling_price' => ['sometimes', 'numeric', 'min:0'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],

            'payment_method' => [
                // Free-text: mirrors the Fawry `operation_type` pattern.
                // The PaymentMethodAccountType::resolve() helper maps the
                // typed code to an AccountType enum so we can still validate
                // the picked collection account is the right kind. No DB
                // lookup is required, so an empty `payment_methods` table
                // on production does not block edits.
                'sometimes',
                'string',
                'max:80',
            ],
            'account_id' => [
                'sometimes',
                'integer',
                Rule::exists((new Account)->getTable(), 'id')
                    ->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at')),
                new OnlineLiquidityAccount,
            ],
            'reference_number' => ['nullable', 'string', 'max:255'],

            'status' => ['nullable', Rule::in(array_column(OnlineTransactionStatus::cases(), 'value'))],
            'failure_reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            if (! $this->exists('payment_method') && ! $this->exists('account_id')) {
                return;
            }

            if ($validator->errors()->has('payment_method') || $validator->errors()->has('account_id')) {
                return;
            }

            $transaction = $this->route('onlineTransaction');
            if (is_numeric($transaction)) {
                $transaction = OnlineTransaction::query()->find((int) $transaction);
            }
            if (! $transaction instanceof OnlineTransaction) {
                $transaction = null;
            }

            $paymentMethod = $this->exists('payment_method')
                ? $this->input('payment_method')
                : $transaction?->payment_method;
            $accountId = $this->exists('account_id')
                ? $this->input('account_id')
                : $transaction?->account_id;

            if (! $this->exists('account_id') && $accountId) {
                (new OnlineLiquidityAccount)->validate(
                    'account_id',
                    $accountId,
                    fn (string $message) => $validator->errors()->add('account_id', $message),
                );

                if ($validator->errors()->has('account_id')) {
                    return;
                }
            }

            $expectedType = PaymentMethodAccountType::resolve($paymentMethod);
            if (! $expectedType) {
                $validator->errors()->add(
                    'payment_method',
                    'طريقة الدفع المحددة غير مرتبطة بنوع حساب تحصيل مدعوم.'
                );

                return;
            }

            $account = Account::query()->find($accountId);
            if ($account && ! PaymentMethodAccountType::matches($paymentMethod, $account->type)) {
                $validator->errors()->add(
                    'account_id',
                    'طريقة الدفع المحددة تتطلب اختيار '.$expectedType->label().'.'
                );
            }
        });
    }
}
