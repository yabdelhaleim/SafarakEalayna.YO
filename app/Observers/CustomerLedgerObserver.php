<?php

namespace App\Observers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;

/**
 * Ensures each customer row has an {@see Account} for future AR / unified ledger wiring.
 */
class CustomerLedgerObserver
{
    public function created(Customer $customer): void
    {
        if ($customer->account_id !== null) {
            return;
        }

        $userId = $customer->created_by ?: 1;

        $account = Account::create([
            'name' => 'ذممة عميل — '.$customer->full_name.' · '.$customer->phone,
            'type' => AccountType::Treasury->value,
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'office',
            'notes' => 'أُنشئ تلقائياً مع سجل العميل للربط المحاسبي.',
            'created_by' => $userId,
        ]);

        Customer::withoutEvents(function () use ($customer, $account): void {
            $customer->forceFill(['account_id' => $account->id])->saveQuietly();
        });

        $customer->account_id = $account->id;
    }
}
