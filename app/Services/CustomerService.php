<?php

namespace App\Services;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CustomerService
{
    /**
     * Get paginated customers with filters.
     *
     * @param  array  $filters  Available keys: search, per_page
     */
    public function getAllCustomers(array $filters): LengthAwarePaginator
    {
        $query = Customer::with(['createdBy', 'ledgerAccount'])
            ->withCount([
                'flightBookings',
                'busBookings',
                'hajjUmraBookings',
                'visaBookings',
                'fawryTransactions',
                'onlineTransactions',
            ])
            ->leftJoin('accounts', 'customers.account_id', '=', 'accounts.id')
            ->select('customers.*');

        if (isset($filters['search']) && $filters['search']) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('customers.full_name', 'like', "%{$search}%")
                    ->orWhere('customers.phone', 'like', "%{$search}%")
                    ->orWhere('customers.national_id', 'like', "%{$search}%");
            });
        }

        if (isset($filters['customer_tier']) && $filters['customer_tier']) {
            $query->where('customers.customer_tier', $filters['customer_tier']);
        }

        if (isset($filters['type']) && $filters['type']) {
            $type = $filters['type'];
            if ($type === 'regular') {
                $type = 'individual';
            } elseif ($type === 'counter') {
                $type = 'company';
            }
            $query->where('customers.type', $type);
        }

        if (isset($filters['module']) && $filters['module'] && $filters['module'] !== 'all') {
            $module = $filters['module'];
            if ($module === 'flight') {
                $query->has('flightBookings');
            } elseif ($module === 'visa') {
                $query->has('visaBookings');
            } elseif ($module === 'hajj_umra') {
                $query->has('hajjUmraBookings');
            } elseif ($module === 'bus') {
                $query->has('busBookings');
            } elseif ($module === 'fawry') {
                $query->has('fawryTransactions');
            } elseif ($module === 'online') {
                $query->has('onlineTransactions');
            }
        }

        if (! empty($filters['balance_status'])) {
            match ($filters['balance_status']) {
                'settled' => $query->where(function ($q) {
                    $q->whereNull('accounts.id')
                        ->orWhere('accounts.balance', 0);
                }),
                'outstanding' => $query->where('accounts.balance', '!=', 0),
                'debtors' => $query->where('accounts.balance', '>', 0),
                default => null,
            };
        }

        $perPage = min($filters['per_page'] ?? 50, 100);

        return $query->orderBy('customers.created_at', 'desc')
            ->paginate($perPage);
    }

    /**
     * Create a new customer.
     *
     * @param  array  $data  Validated customer data
     *
     * @throws \Exception
     */
    public function createCustomer(array $data): Customer
    {
        try {
            $customer = DB::transaction(function () use ($data) {
                $data['created_by'] = auth()->id();

                if (isset($data['type'])) {
                    if ($data['type'] === 'regular') {
                        $data['type'] = 'individual';
                    } elseif ($data['type'] === 'counter') {
                        $data['type'] = 'company';
                    }
                }

                $customer = Customer::create($data);

                Log::info('Customer created', [
                    'customer_id' => $customer->id,
                    'created_by' => auth()->id(),
                ]);

                return $customer;
            });

            return $customer->load(['createdBy', 'ledgerAccount']);
        } catch (\Exception $e) {
            Log::error('CustomerService::createCustomer failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'customer_id' => null,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Update an existing customer.
     *
     * @param  array  $data  Validated update data
     *
     * @throws \Exception
     */
    public function updateCustomer(Customer $customer, array $data): Customer
    {
        try {
            $customer = DB::transaction(function () use ($customer, $data) {
                if (isset($data['type'])) {
                    if ($data['type'] === 'regular') {
                        $data['type'] = 'individual';
                    } elseif ($data['type'] === 'counter') {
                        $data['type'] = 'company';
                    }
                }
                $customer->update($data);

                Log::info('Customer updated', [
                    'customer_id' => $customer->id,
                    'updated_by' => auth()->id(),
                    'changes' => $customer->getChanges(),
                ]);

                return $customer;
            });

            return $customer->fresh(['createdBy', 'ledgerAccount']);
        } catch (\Exception $e) {
            Log::error('CustomerService::updateCustomer failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'customer_id' => $customer->id,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Soft delete a customer after checking for related operations.
     *
     * @throws \Exception
     */
    public function deleteCustomer(Customer $customer): bool
    {
        if ($this->hasRelatedOperations($customer)) {
            throw new \Exception(
                'Cannot delete a customer with existing operations in the system'
            );
        }

        try {
            DB::transaction(function () use ($customer) {
                $customer->delete();

                Log::info('Customer deleted', [
                    'customer_id' => $customer->id,
                    'deleted_by' => auth()->id(),
                ]);
            });

            return true;
        } catch (\Exception $e) {
            Log::error('CustomerService::deleteCustomer failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'customer_id' => $customer->id,
                'input' => null,
            ]);
            throw $e;
        }
    }

    /**
     * Find a customer by ID or throw ModelNotFoundException.
     *
     * @throws ModelNotFoundException
     */
    public function getCustomerById(int $id): Customer
    {
        return Customer::with('createdBy')->findOrFail($id);
    }

    /**
     * Fast search for autocomplete / dropdown usage.
     */
    public function searchCustomers(string $query): Collection
    {
        return Customer::where(function ($q) use ($query) {
            $q->where('full_name', 'like', "%{$query}%")
                ->orWhere('phone', 'like', "%{$query}%")
                ->orWhere('national_id', 'like', "%{$query}%");
        })
            ->limit(10)
            ->get(['id', 'full_name', 'phone', 'national_id', 'customer_tier']);
    }

    /**
     * Check if customer has any related operations.
     * Returns false now — extendable when operation modules are added.
     */
    private function hasRelatedOperations(Customer $customer): bool
    {
        // Check for flight bookings
        if ($customer->flightBookings()->count() > 0) {
            return true;
        }

        // Check for hajj/umra bookings
        if ($customer->hajjUmraBookings()->count() > 0) {
            return true;
        }

        // Check for visa bookings
        if ($customer->visaBookings()->count() > 0) {
            return true;
        }

        // Check for bus bookings
        if (method_exists($customer, 'busBookings') && $customer->busBookings()->count() > 0) {
            return true;
        }

        // Check for online transactions
        if (method_exists($customer, 'onlineTransactions') && $customer->onlineTransactions()->count() > 0) {
            return true;
        }

        return false;
    }
}
