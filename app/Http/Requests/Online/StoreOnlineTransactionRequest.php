<?php

namespace App\Http\Requests\Online;

use App\Enums\OnlineTransactionStatus;
use App\Models\Account;
use App\Rules\OnlineLiquidityAccount;
use App\Support\Finance\PaymentMethodAccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOnlineTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'service_type_code' => ['required', 'string', 'max:80'],
            'provider_code' => ['nullable', 'string', 'max:80'],

            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:64'],
            'customer_country' => ['nullable', 'string', 'max:120'],

            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],

            'purchase_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],
            'amount_paid' => ['nullable', 'numeric', 'min:0'],

            'payment_method' => [
                // Free-text: matches the Fawry `operation_type` pattern.
                // The text is what we store on the transaction AND in the
                // ledger description. The PaymentMethodAccountType::resolve()
                // helper (run inside withValidator, below) maps the typed
                // code to an AccountType enum so we can still validate the
                // picked collection account is the right kind. No DB lookup
                // is required at request-validation time, so empty
                // `payment_methods` rows on production are no longer a
                // blocker.
                'required',
                'string',
                'max:80',
            ],
            'account_id' => [
                'required',
                'integer',
                Rule::exists((new Account)->getTable(), 'id')
                    ->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at')),
                new OnlineLiquidityAccount,
            ],
            'reference_number' => ['nullable', 'string', 'max:255'],

            // SEC-4 idempotency. Whitelisted so it flows through
            // `$request->validated()` into the service layer. The actual
            // replay detection happens in OnlineTransactionService::create()
            // — this rule just gates the value shape.
            'idempotency_key' => ['nullable', 'string', 'max:100'],

            'status' => ['nullable', Rule::in(array_column(OnlineTransactionStatus::cases(), 'value'))],
            'failure_reason' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $customerId = $this->input('customer_id');
            $nameRaw = $this->input('customer_name');
            $name = is_string($nameRaw) ? trim($nameRaw) : '';

            if (! $customerId && $name === '') {
                $validator->errors()->add(
                    'customer_name',
                    'يجب اختيار عميل مسجل أو إدخال اسم العميل.'
                );
            }

            // 🛡️ Phase 11 — Walk-in (customer_id == null) requires a phone.
            //
            // The `customers.phone` column is NOT NULL at the DB level (see
            // `2026_04_26_211146_create_customers_table.php`) but the
            // validators in this request were permissive (`nullable|max:64`).
            // The `OnlineTransactionService::ensureCustomerIsLinked()` code
            // path auto-creates a Customer when no customer_id is supplied,
            // and would propagate `phone: null` to the DB — producing a raw
            // SQLSTATE[23000] error. Mirror the existing `customer_name`
            // rule (just above) and require a phone for walk-in flows *only*
            // so we don't reject registered customers that happen to have no
            // phone on file.
            $phoneRaw = $this->input('customer_phone');
            $phone = is_string($phoneRaw) ? trim($phoneRaw) : '';
            if (! $customerId && $phone === '') {
                $validator->errors()->add(
                    'customer_phone',
                    'يجب إدخال رقم هاتف العميل عند إنشاء معاملة لعميل غير مسجل.'
                );
            }

            if ($validator->errors()->has('payment_method') || $validator->errors()->has('account_id')) {
                return;
            }

            $expectedType = PaymentMethodAccountType::resolve($this->input('payment_method'));
            if (! $expectedType) {
                $validator->errors()->add(
                    'payment_method',
                    'طريقة الدفع المحددة غير مرتبطة بنوع حساب تحصيل مدعوم.'
                );

                return;
            }

            $account = Account::query()->find($this->input('account_id'));
            if ($account && ! PaymentMethodAccountType::matches($this->input('payment_method'), $account->type)) {
                $validator->errors()->add(
                    'account_id',
                    'طريقة الدفع المحددة تتطلب اختيار '.$expectedType->label().'.'
                );
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['customer_id', 'employee_id', 'account_id'] as $key) {
            if (! $this->exists($key)) {
                continue;
            }
            $v = $this->input($key);
            if ($v === '' || $v === null) {
                $merge[$key] = null;
            } elseif (is_numeric($v)) {
                $merge[$key] = (int) $v;
            }
        }

        foreach (['purchase_price', 'selling_price', 'amount_paid'] as $key) {
            if (! $this->exists($key)) {
                continue;
            }
            $v = $this->input($key);
            $merge[$key] = $v === '' ? null : $v;
        }

        if ($this->has('payment_method') && $this->input('payment_method') === '') {
            $merge['payment_method'] = null;
        }

        // Trim and normalize the free-text *_code fields. Empty strings
        // become null so the validation rules can flag them as required.
        foreach (['service_type_code', 'provider_code'] as $key) {
            if (! $this->exists($key)) {
                continue;
            }
            $v = $this->input($key);
            if (is_string($v)) {
                $trimmed = trim($v);
                $merge[$key] = $trimmed === '' ? null : $trimmed;
            }
        }

        $this->merge($merge);
    }
}
