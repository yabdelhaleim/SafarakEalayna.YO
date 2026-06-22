<?php

namespace App\Observers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Flight\FlightGroup;
use Illuminate\Support\Facades\Auth;

class FlightGroupObserver
{
    public function saving(FlightGroup $group): void
    {
        if ($group->account_id !== null) {
            return;
        }

        $group->loadMissing('carrier');
        $currency = $group->carrier?->currency ?: 'EGP';

        $account = Account::create([
            'name' => 'حساب مجموعة طيران: ' . ($group->name ?: 'غير مسمى'),
            'type' => AccountType::Supplier->value,
            'currency' => $currency,
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'flights',
            'notes' => 'حساب مجموعة تلقائي مضاف من النظام.',
            'created_by' => Auth::id() ?? 1,
        ]);

        $group->account_id = $account->id;
    }
}
