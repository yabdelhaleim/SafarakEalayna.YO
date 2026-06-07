<?php

namespace App\Observers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\HajjUmra\UmrahSupplier;
use Illuminate\Support\Facades\Auth;

class UmrahSupplierObserver
{
    public function saving(UmrahSupplier $supplier): void
    {
        if ($supplier->account_id !== null) {
            return;
        }

        $account = Account::create([
            'name' => 'حساب مورد العمرة: '.($supplier->name ?: 'غير مسمى'),
            'type' => AccountType::Supplier->value,
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'hajj_umra',
            'notes' => 'حساب مورد تلقائي مضاف من النظام.',
            'created_by' => Auth::id() ?? 1,
        ]);

        $supplier->account_id = $account->id;
    }
}
