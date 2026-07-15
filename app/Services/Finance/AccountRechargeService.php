<?php

namespace App\Services\Finance;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Support\Facades\Log;

/**
 * إعادة شحن أرصدة حسابات التحصيل (بنك / محفظة) من حساب داخلي أو كإيداع خارجي.
 */
class AccountRechargeService
{
    public function __construct(
        private TransactionService $transactionService
    ) {}

    /**
     * @param  array{amount: float|int|string, external_deposit?: bool, from_account_id?: int|null, notes?: string|null}  $data
     */
    public function recharge(Account $target, array $data): Transaction
    {
        $type = $target->type instanceof AccountType
            ? $target->type
            : AccountType::tryFrom((string) $target->type);

        if (! in_array($type, [AccountType::Bank, AccountType::Wallet], true)) {
            throw new \InvalidArgumentException('إعادة الشحن متاحة فقط لحسابات بنك أو محفظة.');
        }

        if (! $target->is_active) {
            throw new \InvalidArgumentException('الحساب غير نشط.');
        }

        $amount = round((float) ($data['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new \InvalidArgumentException('المبلغ يجب أن يكون أكبر من صفر.');
        }

        $external = (bool) ($data['external_deposit'] ?? false);
        $notes = isset($data['notes']) ? trim((string) $data['notes']) : '';
        $tag = $external ? '[إعادة شحن — إيداع خارجي]' : '[إعادة شحن — تحويل داخلي]';
        $fullNotes = $notes !== '' ? $notes.' '.$tag : $tag;

        if ($external) {
            $transaction = $this->transactionService->recordIncome([
                'amount' => $amount,
                'to_account_id' => $target->id,
                'module' => TransactionModule::General->value,
                'notes' => $fullNotes,
            ]);

            Log::info('Account external recharge (income)', [
                'to_account_id' => $target->id,
                'amount' => $amount,
                'transaction_id' => $transaction->id,
            ]);

            return $transaction;
        }

        $fromId = isset($data['from_account_id']) ? (int) $data['from_account_id'] : 0;
        if ($fromId <= 0) {
            throw new \InvalidArgumentException('اختر حساب المصدر أو فعّل «إيداع خارجي».');
        }

        if ($fromId === (int) $target->id) {
            throw new \InvalidArgumentException('لا يمكن التحويل من نفس الحساب.');
        }

        $transaction = $this->transactionService->recordJournalTransfer([
            'amount' => $amount,
            'from_account_id' => $fromId,
            'to_account_id' => (int) $target->id,
            'module' => TransactionModule::General->value,
            'notes' => $fullNotes,
        ]);

        Log::info('Account recharge (journal transfer)', [
            'from_account_id' => $fromId,
            'to_account_id' => $target->id,
            'amount' => $amount,
            'transaction_id' => $transaction->id,
        ]);

        return $transaction;
    }

    /**
     * @return array<int, string>
     */
    public static function sourceAccountOptions(Account $target): array
    {
        $currency = strtoupper((string) $target->currency);
        $allowedTypes = [AccountType::Bank->value, AccountType::Wallet->value, AccountType::Cashbox->value];

        return Account::query()
            ->whereKeyNot($target->getKey())
            ->where('is_active', true)
            ->whereRaw('upper(currency) = ?', [$currency])
            ->whereIn('type', $allowedTypes)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(function (Account $a): array {
                $t = $a->type instanceof AccountType ? $a->type : AccountType::tryFrom((string) $a->type);

                $typeLabel = $t?->label() ?? (string) $a->type;

                return [$a->id => $a->name.' ('.$typeLabel.')'];
            })
            ->all();
    }
}
