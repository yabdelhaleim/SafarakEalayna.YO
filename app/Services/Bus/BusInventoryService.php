<?php

namespace App\Services\Bus;

use App\Enums\BusCompanyPaymentStatus;
use App\Enums\BusInventoryPaymentType;
use App\Enums\TransactionModule;
use App\Models\Bus\BusCompanyPayment;
use App\Models\Bus\BusInventory;
use App\Models\Transaction;
use App\Services\Finance\TransactionService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BusInventoryService
{
    protected TransactionService $transactionService;

    public function __construct(TransactionService $transactionService)
    {
        $this->transactionService = $transactionService;
    }

    /**
     * Get paginated inventories with filters.
     *
     * @param  array  $filters  Keys: company_id, travel_date, has_available, with_debt, payment_type, per_page
     */
    public function getAllInventories(array $filters): LengthAwarePaginator
    {
        $query = BusInventory::with([
            'company',
            'account',
            'createdBy',
        ]);

        if (isset($filters['company_id']) && $filters['company_id']) {
            $query->where('company_id', $filters['company_id']);
        }

        if (isset($filters['travel_date']) && $filters['travel_date']) {
            $query->where('travel_date', $filters['travel_date']);
        }

        if (isset($filters['has_available']) && $filters['has_available']) {
            $query->hasAvailableTickets();
        }

        if (isset($filters['with_debt']) && $filters['with_debt']) {
            $query->withDebt();
        }

        if (isset($filters['payment_type']) && $filters['payment_type']) {
            $query->where('payment_type', $filters['payment_type']);
        }

        $perPage = min($filters['per_page'] ?? 15, 100);

        return $query->orderBy('travel_date', 'asc')->paginate($perPage);
    }

    /**
     * Get available inventories for booking form.
     * Returns inventories with available tickets for a specific company and date.
     *
     * @param  int  $companyId
     * @param  string  $travelDate
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getAvailableInventories(int $companyId, string $travelDate): \Illuminate\Database\Eloquent\Collection
    {
        return BusInventory::with(['company', 'account'])
            ->where('company_id', $companyId)
            ->where('travel_date', '>=', $travelDate)
            ->where('available_tickets', '>', 0)
            ->orderBy('travel_date', 'asc')
            ->get();
    }

    /**
     * Create a new ticket inventory batch.
     * If payment_type = cash: immediately record expense via TransactionService.
     * If payment_type = deferred: set remaining_debt = total_cost, no transaction.
     *
     * @param  array  $data  Validated inventory data
     *
     * @throws \Exception
     */
    public function createInventory(array $data): BusInventory
    {
        try {
            return DB::transaction(function () use ($data) {
                $totalCost = $data['total_tickets'] * $data['cost_per_ticket'];

                $inventoryData = [
                    'company_id' => $data['company_id'],
                    'route' => $data['route'],
                    'travel_date' => $data['travel_date'],
                    'departure_time' => $data['departure_time'] ?? null,
                    'total_tickets' => $data['total_tickets'],
                    'available_tickets' => $data['total_tickets'],
                    'cost_per_ticket' => $data['cost_per_ticket'],
                    'selling_price' => $data['selling_price'],
                    'payment_type' => $data['payment_type'],
                    'total_cost' => $totalCost,
                    'amount_paid' => 0.00,
                    'remaining_debt' => $totalCost,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => Auth::id(),
                ];

                if ($data['payment_type'] === BusInventoryPaymentType::Cash->value) {
                    $accountId = $data['account_id'];
                    if (! $accountId) {
                        throw new \Exception('Account ID is required for cash payments.');
                    }

                    $transaction = $this->transactionService->recordExpense([
                        'amount' => $totalCost,
                        'from_account_id' => $accountId,
                        'module' => TransactionModule::Bus->value,
                        'related_type' => BusInventory::class,
                        'related_id' => null, // will set after inventory created
                        'notes' => $data['notes'] ?? null,
                    ]);

                    $inventoryData['account_id'] = $accountId;
                    $inventoryData['transaction_id'] = $transaction->id;
                    $inventoryData['amount_paid'] = $totalCost;
                    $inventoryData['remaining_debt'] = 0.00;
                } else {
                    $inventoryData['account_id'] = null;
                    $inventoryData['transaction_id'] = null;
                }

                $inventory = BusInventory::create($inventoryData);

                // If transaction created before inventory, update related_id linking
                if (isset($transaction) && $inventory->id) {
                    $transaction->update([
                        'related_id' => $inventory->id,
                    ]);
                }

                Log::info('Bus inventory created', [
                    'inventory_id' => $inventory->id,
                    'company_id' => $data['company_id'],
                    'payment_type' => $data['payment_type'],
                    'total_cost' => $totalCost,
                    'user_id' => Auth::id(),
                ]);

                return $inventory->load([
                    'company',
                    'account',
                    'createdBy',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('BusInventoryService::createInventory failed', [
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
     * Update non-financial inventory fields only.
     * Cannot update: total_tickets, cost_per_ticket, payment_type
     *
     * @param  array  $data  Validated update data
     *
     * @throws \Exception
     */
    public function updateInventory(BusInventory $inventory, array $data): BusInventory
    {
        try {
            return DB::transaction(function () use ($inventory, $data) {
                $inventory->fill([
                    'route' => $data['route'] ?? $inventory->route,
                    'travel_date' => $data['travel_date'] ?? $inventory->travel_date,
                    'departure_time' => $data['departure_time'] ?? $inventory->departure_time,
                    'selling_price' => $data['selling_price'] ?? $inventory->selling_price,
                    'notes' => $data['notes'] ?? $inventory->notes,
                ]);
                $inventory->save();

                Log::info('Bus inventory updated', [
                    'inventory_id' => $inventory->id,
                    'changes' => $inventory->getChanges(),
                    'user_id' => Auth::id(),
                ]);

                return $inventory->fresh([
                    'company',
                    'account',
                    'createdBy',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('BusInventoryService::updateInventory failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'inventory_id' => $inventory->id,
                'booking_id' => null,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Pay a deferred inventory debt (partially or fully).
     * Can be called multiple times until remaining_debt = 0.
     *
     * @param  array  $data  Keys: amount, account_id, notes
     *
     * @throws \Exception
     */
    public function payInventoryDebt(BusInventory $inventory, array $data): BusCompanyPayment
    {
        if ($inventory->payment_type !== BusInventoryPaymentType::Deferred) {
            throw new \Exception('This inventory was paid in cash. No debt to settle.');
        }

        if ($inventory->remaining_debt <= 0) {
            throw new \Exception('This inventory has no remaining debt.');
        }

        if ($data['amount'] > $inventory->remaining_debt) {
            throw new \Exception(
                'Payment amount exceeds remaining debt of '.$inventory->remaining_debt
            );
        }

        try {
            return DB::transaction(function () use ($inventory, $data) {
                $transaction = $this->transactionService->recordExpense([
                    'amount' => $data['amount'],
                    'from_account_id' => $data['account_id'],
                    'module' => TransactionModule::Bus->value,
                    'related_type' => BusInventory::class,
                    'related_id' => $inventory->id,
                    'notes' => $data['notes'] ?? null,
                ]);

                $inventory->increment('amount_paid', $data['amount']);
                $inventory->decrement('remaining_debt', $data['amount']);
                $inventory->refresh();

                $payment = BusCompanyPayment::create([
                    'company_id' => $inventory->company_id,
                    'inventory_id' => $inventory->id,
                    'amount' => $data['amount'],
                    'account_id' => $data['account_id'],
                    'transaction_id' => $transaction->id,
                    'status' => BusCompanyPaymentStatus::Paid,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => Auth::id(),
                ]);

                Log::info('Bus inventory debt payment recorded', [
                    'payment_id' => $payment->id,
                    'inventory_id' => $inventory->id,
                    'amount' => $data['amount'],
                    'user_id' => Auth::id(),
                ]);

                return $payment->load([
                    'company',
                    'inventory',
                    'account',
                    'createdBy',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('BusInventoryService::payInventoryDebt failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'inventory_id' => $inventory->id,
                'booking_id' => null,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Get inventory by ID.
     */
    public function getInventoryById(int $id): BusInventory
    {
        return BusInventory::with([
            'company',
            'account',
            'bookings',
            'createdBy',
        ])->findOrFail($id);
    }

    /**
     * Soft delete an inventory batch with full financial reversal.
     *
     * Allowed only if no bookings exist for the inventory (the service-level
     * `bookings()->count() > 0` check stays — the new `deleting` observer on
     * `BusInventory` also keeps this as a complementary safety layer per the
     * Bus deletion contract).
     *
     * Reversal flow when the inventory was paid in CASH (payment_type=Cash):
     *   ① finds the original expense transaction stored at creation time
     *      (`inventory->transaction_id`),
     *   ② calls `TransactionService::reverseTransaction($tx)` — adds inverse
     *      `account_entries` on the SAME transaction_id (additive — never
     *      destructive). The original transaction row stays.
     * This restores the cashbox balance that was debited at creation time,
     * fixing the previous silent financial leak.
     *
     * Wrap is done via `BusInventory::run()` so the new `deleting` observer
     * (with `ModelDeletionGuard`) allows the soft-delete. The observer
     * still throws for direct `$inventory->delete()` from outside any
     * canonical path.
     *
     * @throws \Exception if bookings exist or reversal fails
     * @throws \RuntimeException if called outside BusInventory::run()
     */
    public function deleteInventory(BusInventory $inventory): bool
    {
        if ($inventory->bookings()->count() > 0) {
            throw new \Exception('Cannot delete an inventory with existing bookings.');
        }

        try {
            return BusInventory::run(function () use ($inventory) {
                return DB::transaction(function () use ($inventory) {
                    // 🛡️ Reverse the cash purchase expense (only when payment_type=Cash
                    //    and a transaction_id was stored at creation time).
                    //    For payment_type=Deferred, there is no expense to reverse
                    //    (the cost sits in BusCompanyPayment when later paid).
                    if ($inventory->payment_type === BusInventoryPaymentType::Cash && $inventory->transaction_id) {
                        $tx = Transaction::find($inventory->transaction_id);
                        if ($tx) {
                            $this->transactionService->reverseTransaction($tx);

                            Log::info('Bus inventory cash expense reversed on delete', [
                                'inventory_id' => $inventory->id,
                                'transaction_id' => $tx->id,
                                'amount' => (float) $tx->amount,
                                'user_id' => Auth::id(),
                            ]);
                        }
                    }

                    // Soft-delete the inventory row. Allowed because we are inside
                    // BusInventory::run(...) which flipped the model's deletion gate
                    // open for the canonical reversal flow.
                    $inventory->delete();

                    Log::info('Bus inventory deleted', [
                        'inventory_id' => $inventory->id,
                        'payment_type' => $inventory->payment_type instanceof BusInventoryPaymentType
                            ? $inventory->payment_type->value
                            : (string) $inventory->payment_type,
                        'cash_expense_reversed' => (bool) ($inventory->payment_type === BusInventoryPaymentType::Cash && $inventory->transaction_id),
                        'user_id' => Auth::id(),
                    ]);

                    return true;
                });
            });
        } catch (\Exception $e) {
            Log::error('BusInventoryService::deleteInventory failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'inventory_id' => $inventory->id,
                'booking_id' => null,
                'input' => null,
            ]);
            throw $e;
        }
    }
}
