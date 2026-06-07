<?php

namespace App\Observers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\HajjUmra\VisaAgent;
use Illuminate\Support\Facades\Auth;

class VisaAgentObserver
{
    public function saving(VisaAgent $agent): void
    {
        if ($agent->account_id !== null) {
            return;
        }

        $account = Account::create([
            'name' => 'حساب وكيل التأشيرة: '.($agent->company_name ?: $agent->contact_person ?: 'غير مسمى'),
            'type' => AccountType::Supplier->value,
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'visas',
            'notes' => 'حساب وكيل تلقائي مضاف من النظام.',
            'created_by' => Auth::id() ?? 1,
        ]);

        $agent->account_id = $account->id;
    }
}
