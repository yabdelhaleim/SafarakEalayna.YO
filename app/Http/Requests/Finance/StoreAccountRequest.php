<?php

namespace App\Http\Requests\Finance;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(AccountType::class)],
            'balance' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'module_type' => ['nullable', 'string', 'in:general,office,flights,tourism,hajj_umra,visas,bus,fawry,online,wallet_transfer'],
            'owner_type' => ['nullable', 'string', 'in:owner,office'],
            'wallet_provider' => [
                Rule::requiredIf(fn () => $this->input('type') === AccountType::Wallet->value),
                'nullable',
                Rule::enum(WalletProvider::class),
            ],
            'wallet_number' => [
                Rule::requiredIf(fn () => $this->input('type') === AccountType::Wallet->value),
                'nullable',
                'string',
                'max:100',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'اسم الحساب مطلوب',
            'type.required' => 'نوع الحساب مطلوب',
            'balance.required' => 'الرصيد مطلوب',
            'balance.numeric' => 'الرصيد يجب أن يكون رقماً',
            'currency.required' => 'العملة مطلوبة',
            'wallet_provider.required' => 'نوع المحفظة مطلوب لحساب محفظة',
            'wallet_number.required' => 'رقم المحفظة مطلوب لحساب محفظة',
        ];
    }
}
