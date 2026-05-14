<?php

namespace App\Http\Requests\Finance;

use App\Enums\AccountType;
use App\Enums\WalletProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', Rule::enum(AccountType::class)],
            'balance' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'module_type' => ['nullable', 'string', 'in:tourism,office'],
            'owner_type' => ['nullable', 'string', 'in:owner,office'],
            'wallet_provider' => ['nullable', Rule::enum(WalletProvider::class)],
            'wallet_number' => ['nullable', 'string', 'max:100'],
        ];
    }
}
