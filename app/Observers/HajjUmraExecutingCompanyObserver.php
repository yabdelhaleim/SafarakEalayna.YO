<?php

namespace App\Observers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use Illuminate\Support\Facades\Auth;

class HajjUmraExecutingCompanyObserver
{
    public function saving(HajjUmraExecutingCompany $company): void
    {
        if ($company->account_id !== null) {
            return;
        }

        $account = Account::create([
            'name' => 'حساب الشركة المنفذة للحج/العمرة: '.($company->name ?: 'غير مسمى'),
            'type' => AccountType::Supplier->value,
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'hajj_umra',
            'notes' => 'حساب شركة منفذة تلقائي مضاف من النظام.',
            'created_by' => Auth::id() ?? 1,
        ]);

        $company->account_id = $account->id;
    }
}
