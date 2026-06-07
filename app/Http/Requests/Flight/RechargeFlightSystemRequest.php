<?php

namespace App\Http\Requests\Flight;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Flight\FlightSystem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RechargeFlightSystemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_account_id' => ['required', 'integer', 'exists:accounts,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            /** @var FlightSystem|null $system */
            $system = $this->route('system');
            if (! $system instanceof FlightSystem) {
                return;
            }

            if (! $system->is_active) {
                $v->errors()->add('system', 'نظام الحجز غير مفعّل.');
            }

            $accountId = (int) $this->input('from_account_id');
            $account = Account::query()->find($accountId);
            if (! $account) {
                return;
            }

            if (! $account->is_active) {
                $v->errors()->add('from_account_id', 'حساب المصدر غير مفعّل.');
            }

            if ($account->module_type !== 'flights') {
                $v->errors()->add('from_account_id', 'يُسمح فقط بحسابات وحدة العمل «طيران».');
            }

            $type = $account->type instanceof AccountType ? $account->type : AccountType::tryFrom((string) $account->type);
            $allowed = [AccountType::Cashbox, AccountType::Wallet, AccountType::Bank, AccountType::Treasury];
            if (! $type || ! in_array($type, $allowed, true)) {
                $v->errors()->add('from_account_id', 'نوع الحساب غير مسموح للشحن (استخدم نقدي/خزينة/بنك/محفظة).');
            }

            if (strtoupper((string) $account->currency) !== strtoupper((string) $system->currency)) {
                $v->errors()->add('from_account_id', 'عملة حساب المصدر يجب أن تطابق عملة النظام ('.$system->currency.').');
            }

            $amount = (float) $this->input('amount');
            if ($amount > 0 && (float) $account->balance < $amount) {
                $v->errors()->add('amount', 'رصيد حساب المصدر غير كافٍ.');
            }
        });
    }
}
