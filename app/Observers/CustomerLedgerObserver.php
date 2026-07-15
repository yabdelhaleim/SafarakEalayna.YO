<?php

namespace App\Observers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use Illuminate\Support\Facades\Auth;

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

        $userId = $customer->created_by ?: Auth::id();
        if ($userId === null) {
            return;
        }

        $account = Account::create([
            'name' => 'ذممة عميل — '.$customer->full_name.' · '.$customer->phone,
            'type' => AccountType::Customer->value,
            'currency' => 'EGP',
            'balance' => 0,
            'is_active' => true,
            // Subject accounts (AR mirroring a real party) are owner-level.
            // Every other observer/service in the system uses OWNER_TYPE_OWNER
            // (see FlightGroupObserver, HajjUmraExecutingCompanyObserver,
            // UmrahSupplierObserver, VisaAgentObserver, plus bus/fawry/flight/
            // hajj/online/visa/wallet booking services). Customer accounts
            // belong to the same owner-ledger concept: they mirror the customer,
            // not a division. office would silently misclassify every new AR.
            'owner_type' => Account::OWNER_TYPE_OWNER,
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
