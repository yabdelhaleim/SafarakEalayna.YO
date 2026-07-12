<?php

namespace App\Services\Bus;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Bus\BusCompany;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BusCompanyService
{
    /**
     * Get paginated bus companies with filters.
     *
     * @param  array  $filters  Keys: search, is_active, per_page
     */
    public function getAllCompanies(array $filters): LengthAwarePaginator
    {
        $query = BusCompany::with(['createdBy', 'account']);

        if (isset($filters['search']) && $filters['search']) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        if (isset($filters['is_active'])) {
            $query->where('is_active', (bool) $filters['is_active']);
        }

        $perPage = min($filters['per_page'] ?? 15, 100);

        return $query->orderBy('created_at', 'desc')->paginate($perPage);
    }

    /**
     * Create a new bus company.
     *
     * @param  array  $data  Validated company data
     *
     * @throws \Exception
     */
    public function createCompany(array $data): BusCompany
    {
        try {
            return DB::transaction(function () use ($data) {
                $company = BusCompany::create([
                    'name' => $data['name'],
                    'phone' => $data['phone'] ?? null,
                    'address' => $data['address'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => Auth::id(),
                ]);

                // Create the ledger account right away!
                $this->ensureCompanyAccount($company);

                Log::info('Bus company created', [
                    'company_id' => $company->id,
                    'created_by' => Auth::id(),
                ]);

                return $company->load(['createdBy', 'account']);
            });
        } catch (\Exception $e) {
            Log::error('BusCompanyService::createCompany failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'inventory_id' => null,
                'booking_id' => null,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Update bus company details.
     *
     * @param  array  $data  Validated update data
     *
     * @throws \Exception
     */
    public function updateCompany(BusCompany $company, array $data): BusCompany
    {
        try {
            return DB::transaction(function () use ($company, $data) {
                $changes = [];

                if (isset($data['name'])) {
                    $company->name = $data['name'];
                    $changes['name'] = $data['name'];
                }

                if (isset($data['phone'])) {
                    $company->phone = $data['phone'];
                    $changes['phone'] = $data['phone'];
                }

                if (isset($data['address'])) {
                    $company->address = $data['address'];
                    $changes['address'] = $data['address'];
                }

                if (isset($data['is_active'])) {
                    $company->is_active = $data['is_active'];
                    $changes['is_active'] = $data['is_active'];
                }

                if (isset($data['notes'])) {
                    $company->notes = $data['notes'];
                    $changes['notes'] = $data['notes'];
                }

                $company->save();

                Log::info('Bus company updated', [
                    'company_id' => $company->id,
                    'changes' => $changes,
                    'updated_by' => Auth::id(),
                ]);

                return $company->fresh('createdBy');
            });
        } catch (\Exception $e) {
            Log::error('BusCompanyService::updateCompany failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'inventory_id' => null,
                'booking_id' => null,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Soft delete a bus company.
     * Only if no active inventories linked.
     *
     * GL-safe by construction:
     *   - `ensureCompanyAccount()` does NOT post any transactions on creation.
     *   - Booking cost entries (recorded against `company->account_id`) are
     *     reversed by `BusBookingService::deleteBooking` /
     *     `deleteBookingWithReversal` before a company can be deleted.
     *   - `BusInventory` cash expenses go to the cashbox/vault account
     *     (`from_account_id = account_id`), NOT to the company account.
     *
     * Wrap is done via `BusCompany::run()` so the new `deleting` observer
     * (with `ModelDeletionGuard`) allows the soft-delete. The observer
     * still throws for direct `$company->delete()` from outside any
     * canonical path.
     *
     * @throws \Exception if active inventories exist
     * @throws \RuntimeException if called outside BusCompany::run()
     */
    public function deleteCompany(BusCompany $company): bool
    {
        $hasInventories = $company->inventories()
            ->whereNull('deleted_at')
            ->exists();

        if ($hasInventories) {
            throw new \Exception(
                'Cannot delete a company with existing inventory records.'
            );
        }

        try {
            return BusCompany::run(function () use ($company) {
                return DB::transaction(function () use ($company) {
                    $company->delete();

                    Log::info('Bus company deleted', [
                        'company_id' => $company->id,
                        'deleted_by' => Auth::id(),
                    ]);

                    return true;
                });
            });
        } catch (\Exception $e) {
            Log::error('BusCompanyService::deleteCompany failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'inventory_id' => null,
                'booking_id' => null,
                'input' => null,
            ]);
            throw $e;
        }
    }

    /**
     * Get company by ID.
     */
    public function getCompanyById(int $id): BusCompany
    {
        return BusCompany::with(['createdBy', 'account'])->findOrFail($id);
    }

    /**
     * Ensures the bus company has a ledger account. Creates one if missing.
     */
    public function ensureCompanyAccount(BusCompany $company): Account
    {
        if ($company->account_id) {
            $account = Account::find($company->account_id);
            if ($account) {
                // Phase 1.Bend3 fix: re-tag the company account to 'bus'
                // so it surfaces in the strict module_type='bus' queries.
                // (There is no BusCompanyObserver that pre-creates the
                // account, but a BusCompany may be imported / migrated with
                // an existing account tagged to another module — this keeps
                // the gate consistent with the customer-account flow.)
                // Wrapped in LedgerBalanceMutationGuard because touching
                // `balance` — even to confirm 0.00 — would otherwise trip
                // the Account::updating boot guard.
                if ($account->module_type !== 'bus') {
                    LedgerBalanceMutationGuard::run(function () use ($account) {
                        $account->module_type = 'bus';
                        $account->save();
                    });
                }

                return $account;
            }
        }

        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($company) {
            $account = Account::create([
                'name' => 'حساب شركة باصات: '.$company->name,
                'type' => AccountType::Supplier,
                'balance' => 0,
                'currency' => 'EGP',
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'bus',
                'is_module_vault' => false,
                'notes' => 'حساب تلقائي لشركة الباصات #'.$company->id,
                'created_by' => Auth::id() ?? 1,
            ]);

            $company->update(['account_id' => $account->id]);

            Log::info('Bus company ledger account created automatically', [
                'company_id' => $company->id,
                'account_id' => $account->id,
            ]);

            return $account;
        }));
    }
}
