<?php

namespace App\Http\Requests\Finance;

use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Support\Finance\AccountModuleDivision;
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
            'to_account_id' => [
                'required_without:to_account_name',
                'nullable',
                'different:from_account_id',
                Rule::exists('accounts', 'id'),
            ],
            'to_account_name' => 'required_without:to_account_id|nullable|string|max:255',
            'amount' => 'required|numeric|min:0.01',
            'converted_amount' => 'nullable|numeric|min:0.01',
            'exchange_rate' => 'nullable|numeric|min:0.000001',
            'module' => ['nullable', 'string', Rule::enum(TransactionModule::class)],
            'type' => ['nullable', 'string', Rule::enum(TransactionType::class)],
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
            if (! $from) {
                return;
            }

            $to = null;
            if ($this->filled('to_account_id')) {
                $to = Account::query()->find($this->input('to_account_id'));
            } elseif ($this->filled('to_account_name')) {
                $module = $this->input('module') ?? 'general';
                $moduleType = AccountModuleDivision::resolveModuleTypeKey(null, $module);
                $to = Account::query()
                    ->where('type', 'expense')
                    ->where('name', trim($this->input('to_account_name')))
                    ->where('module_type', $moduleType)
                    ->first();
            }

            $isExpense = ($this->input('type') === 'expense' || ($to && ($to->type?->value === 'expense' || $to->type === 'expense')) || !$to);

            $fromType = $from->type?->value ?? $from->type;
            if (! in_array($fromType, AccountModuleDivision::LIQUIDITY_TYPES, true)) {
                $validator->errors()->add(
                    'from_account_id',
                    'يُسمح بالسحب من حسابات السيولة فقط (خزينة، بنك، محفظة).'
                );
            }
            if (! $from->is_active) {
                $validator->errors()->add('from_account_id', 'الحساب غير نشط ولا يمكن استخدامه.');
            }

            if ($to) {
                $toType = $to->type?->value ?? $to->type;
                $allowedTypes = $isExpense ? ['expense'] : AccountModuleDivision::LIQUIDITY_TYPES;

                if (! in_array($toType, $allowedTypes, true)) {
                    $validator->errors()->add(
                        'to_account_id',
                        $isExpense
                            ? 'يُسمح بالتحويل لحساب تصنيف مصروف صالح.'
                            : 'يُسمح بالتحويل لحسابات السيولة.'
                    );
                }
                if (! $to->is_active) {
                    $validator->errors()->add('to_account_id', 'الحساب غير نشط ولا يمكن استخدامه.');
                }
            }

            if (! $this->filled('type') && ($to && $to->type?->value === 'expense')) {
                $this->merge(['type' => TransactionType::Expense->value]);
            }

            $same = true;
            if ($to) {
                $same = strtoupper((string) $from->currency) === strtoupper((string) $to->currency);
            }
            if (! $same && ! $this->filled('converted_amount')) {
                $validator->errors()->add(
                    'converted_amount',
                    'مطلوب عند اختلاف العملة بين الحسابين: أدخل المبلغ بعملة الحساب المستلم.'
                );
            }
        });
    }
}
