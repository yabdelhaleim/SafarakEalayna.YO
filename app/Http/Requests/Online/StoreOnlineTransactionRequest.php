<?php

namespace App\Http\Requests\Online;

use App\Enums\OnlineTransactionStatus;
use App\Models\Account;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Online\OnlineServiceType;
use App\Models\Setting\PaymentMethod;
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
            'service_type_id' => [
                'required',
                'integer',
                Rule::exists((new OnlineServiceType)->getTable(), 'id')
                    ->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at')),
            ],
            'provider_id' => [
                'nullable',
                'integer',
                Rule::exists((new OnlineServiceProvider)->getTable(), 'id')
                    ->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at')),
            ],

            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:64'],
            'customer_country' => ['nullable', 'string', 'max:120'],

            'employee_id' => ['nullable', 'integer', 'exists:employees,id'],

            'purchase_price' => ['required', 'numeric', 'min:0'],
            'selling_price' => ['required', 'numeric', 'min:0'],

            'payment_method' => ['required', 'string', Rule::exists(PaymentMethod::class, 'code')],
            'account_id' => [
                'required',
                'integer',
                Rule::exists((new Account)->getTable(), 'id')
                    ->where(fn ($q) => $q->where('is_active', true)->whereNull('deleted_at')),
            ],
            'reference_number' => ['nullable', 'string', 'max:255'],

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
        });
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        foreach (['service_type_id', 'provider_id', 'customer_id', 'employee_id', 'account_id'] as $key) {
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

        foreach (['purchase_price', 'selling_price'] as $key) {
            if (! $this->exists($key)) {
                continue;
            }
            $v = $this->input($key);
            $merge[$key] = $v === '' ? null : $v;
        }

        if ($this->has('payment_method') && $this->input('payment_method') === '') {
            $merge['payment_method'] = null;
        }

        $this->merge($merge);
    }
}
