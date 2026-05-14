<?php

namespace App\Http\Requests\Finance;

use App\Enums\TransactionModule;
use App\Models\Account;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreTransferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_account_id' => 'required|exists:accounts,id',
            'to_account_id' => 'required|exists:accounts,id|different:from_account_id',
            'amount' => 'required|numeric|min:0.01',
            'converted_amount' => 'nullable|numeric|min:0.01',
            'exchange_rate' => 'nullable|numeric|min:0.000001',
            'module' => ['nullable', 'string', Rule::enum(TransactionModule::class)],
            'notes' => 'nullable|string|max:1000',
            'attachment' => 'nullable|file|mimes:jpeg,png,jpg,pdf|max:5120',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $from = Account::query()->find($this->input('from_account_id'));
            $to = Account::query()->find($this->input('to_account_id'));
            if (! $from || ! $to) {
                return;
            }

            $same = strtoupper((string) $from->currency) === strtoupper((string) $to->currency);
            if (! $same && ! $this->filled('converted_amount')) {
                $validator->errors()->add(
                    'converted_amount',
                    'مطلوب عند اختلاف العملة بين الحسابين: أدخل المبلغ بعملة الحساب المستلم (مثال: 100 د.ك في خزنة الدينار عند الدفع 17,500 ج.م من خزنة الجنيه).'
                );
            }
        });
    }
}
