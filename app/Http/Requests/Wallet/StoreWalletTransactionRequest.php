<?php

namespace App\Http\Requests\Wallet;

use App\Enums\WalletTransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreWalletTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'wallet_type_id'    => 'required|integer|exists:wallet_types,id',
            'customer_id'       => 'nullable|integer|exists:customers,id',
            'customer_name'     => 'required|string|max:200',
            'wallet_number'     => 'required|string|max:30',
            'type'              => ['required', new Enum(WalletTransactionType::class)],
            'amount'            => 'required|numeric|min:0.01',
            'service_fee'       => 'nullable|numeric|min:0',
            'wallet_account_id' => 'required|integer|exists:accounts,id',
            'cash_account_id'   => 'required|integer|exists:accounts,id',
            'employee_id'       => 'nullable|integer|exists:employees,id',
            'notes'             => 'nullable|string|max:1000',
        ];
    }

    public function messages(): array
    {
        return [
            'wallet_type_id.required'    => 'نوع المحفظة مطلوب.',
            'wallet_type_id.exists'      => 'نوع المحفظة غير موجود.',
            'customer_name.required'     => 'اسم العميل مطلوب.',
            'wallet_number.required'     => 'رقم المحفظة (الهاتف) مطلوب.',
            'type.required'              => 'نوع العملية مطلوب (إرسال أو استقبال).',
            'amount.required'            => 'المبلغ مطلوب.',
            'amount.min'                 => 'المبلغ يجب أن يكون أكبر من صفر.',
            'service_fee.min'            => 'قيمة الخدمة لا يمكن أن تكون سالبة.',
            'wallet_account_id.required' => 'حساب المحفظة مطلوب.',
            'wallet_account_id.exists'   => 'حساب المحفظة غير موجود.',
            'cash_account_id.required'   => 'الحساب النقدي مطلوب.',
            'cash_account_id.exists'     => 'الحساب النقدي غير موجود.',
        ];
    }
}
