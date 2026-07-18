<?php

namespace App\Observers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Support\Finance\AccountModuleContract;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Ensures each customer row has an {@see Account} for future AR / unified ledger wiring.
 *
 * Phase 3.5 fix (2026-07-15):
 *   - Was hard-coding `module_type='bus'`, which silently misclassified every
 *     customer AR mirror account as "bus" regardless of which module the
 *     customer actually belongs to (flights, hajj_umra, visas, etc.).
 *   - Now reads `customer->module_type` (set by the creating module/API) and
 *     falls back to the shared 'office' division — which represents the
 *     union of bus / fawry / online / wallet_transfer per the contract.
 *   - The legacy FlightBookingService retag path (Phase 1.Bend3) still
 *     works as a safety net for older customer rows that predate this fix.
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

        // Pick the right module for the AR mirror. Honour the explicit value
        // set by the creating module context; otherwise default to the
        // 'office' division (the "shared" AR pool). NEVER default to a
        // specific module like 'bus' — that mislabels every flight/hajj/visa
        // customer as bus until a downstream retag happens.
        $moduleType = $customer->module_type ?: AccountModuleContract::OFFICE_MODULE_TYPE;

        // Defence in depth: if the explicit module_type happens to be a
        // division ('office' / 'tourism'), the Account saving hook will
        // reject it (subject accounts must use a SPECIFIC module). Fall
        // back to the closest specific module inside that division — for
        // tourism, that's 'flights' (most common AR mirror); for office,
        // that's 'bus'.
        if (in_array($moduleType, [
            AccountModuleContract::OFFICE_MODULE_TYPE,
            AccountModuleContract::TOURISM_MODULE_TYPE,
        ], true)) {
            $fallback = $moduleType === AccountModuleContract::TOURISM_MODULE_TYPE
                ? 'flights'
                : 'bus';
            Log::warning('CustomerLedgerObserver: division module_type not allowed for subject account, falling back', [
                'customer_id'      => $customer->id,
                'attempted_module' => $moduleType,
                'fallback'         => $fallback,
            ]);
            $moduleType = $fallback;
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
            // not a division.
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => $moduleType,
            'notes' => 'أُنشئ تلقائياً مع سجل العميل للربط المحاسبي.',
            'created_by' => $userId,
        ]);

        Customer::withoutEvents(function () use ($customer, $account): void {
            $customer->forceFill(['account_id' => $account->id])->saveQuietly();
        });

        $customer->account_id = $account->id;
    }
}