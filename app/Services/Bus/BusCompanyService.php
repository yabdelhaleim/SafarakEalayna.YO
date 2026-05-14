<?php

namespace App\Services\Bus;

use App\Models\Bus\BusCompany;
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
        $query = BusCompany::with('createdBy');

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

                Log::info('Bus company created', [
                    'company_id' => $company->id,
                    'created_by' => Auth::id(),
                ]);

                return $company->load('createdBy');
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
     * @throws \Exception
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
            DB::transaction(function () use ($company) {
                $company->delete();

                Log::info('Bus company deleted', [
                    'company_id' => $company->id,
                    'deleted_by' => Auth::id(),
                ]);
            });

            return true;
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
        return BusCompany::with('createdBy')->findOrFail($id);
    }
}
