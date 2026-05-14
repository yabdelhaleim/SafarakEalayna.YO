<?php

namespace App\Services\Bus;

use App\Enums\BusBookingStatus;
use App\Enums\BusPaymentStatus;
use App\Enums\TransactionModule;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusInventory;
use App\Models\Employee;
use App\Services\Finance\TransactionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BusBookingService
{
    protected TransactionService $transactionService;
    protected \App\Services\Finance\LedgerClearingAccounts $ledgerClearingAccounts;

    public function __construct(
        TransactionService $transactionService,
        \App\Services\Finance\LedgerClearingAccounts $ledgerClearingAccounts
    ) {
        $this->transactionService = $transactionService;
        $this->ledgerClearingAccounts = $ledgerClearingAccounts;
    }

    public function getAllBookings(array $filters): LengthAwarePaginator
    {
        $query = BusBooking::with([
            'inventory.company',
            'customer',
            'employee.user',
            'account',
            'payments',
            'createdBy',
        ]);

        if (isset($filters['status']) && $filters['status']) {
            $query->where('status', $filters['status']);
        }

        if (isset($filters['customer_id']) && $filters['customer_id']) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if (isset($filters['employee_id']) && $filters['employee_id']) {
            $query->where('employee_id', $filters['employee_id']);
        }

        if (isset($filters['inventory_id']) && $filters['inventory_id']) {
            $query->where('inventory_id', $filters['inventory_id']);
        }

        if (isset($filters['company_id']) && $filters['company_id']) {
            $query->whereHas('inventory', fn ($q) => $q->where('company_id', $filters['company_id']));
        }

        if (isset($filters['search']) && ($term = trim((string) $filters['search'])) !== '') {
            $query->where(function ($q) use ($term) {
                if (ctype_digit($term)) {
                    $q->where('id', (int) $term)
                        ->orWhereHas('customer', function ($c) use ($term) {
                            $c->where('full_name', 'like', '%'.$term.'%')
                                ->orWhere('phone', 'like', '%'.$term.'%');
                        });

                    return;
                }
                $q->whereHas('customer', function ($c) use ($term) {
                    $c->where('full_name', 'like', '%'.$term.'%')
                        ->orWhere('phone', 'like', '%'.$term.'%');
                });
            });
        }

        if (isset($filters['date_from']) && $filters['date_from']) {
            $query->whereHas('inventory', fn ($q) => $q->whereDate('travel_date', '>=', $filters['date_from']));
        }

        if (isset($filters['date_to']) && $filters['date_to']) {
            $query->whereHas('inventory', fn ($q) => $q->whereDate('travel_date', '<=', $filters['date_to']));
        }

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);
        $page = max(1, (int) ($filters['page'] ?? 1));

        return $query->orderBy('created_at', 'desc')->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * Aggregate counters for dashboards (full database, not current page).
     *
     * @return array{total_bookings:int,paid_bookings:int,pending_bookings:int,cancelled_bookings:int,total_revenue:float,pending_payments:float}
     */
    public function getBookingStats(): array
    {
        $totalBookings = BusBooking::query()->count();
        $paidBookings = BusBooking::query()->where('status', BusBookingStatus::Paid->value)->count();
        $pendingBookings = BusBooking::query()->where('status', BusBookingStatus::Pending->value)->count();
        $cancelledBookings = BusBooking::query()->where('status', BusBookingStatus::Cancelled->value)->count();

        $totalRevenue = (float) BusBooking::query()
            ->where('status', '!=', BusBookingStatus::Cancelled->value)
            ->sum('total_price');

        $pendingPayments = (float) BusBooking::query()
            ->where('status', '!=', BusBookingStatus::Cancelled->value)
            ->where('payment_status', '!=', BusPaymentStatus::Paid->value)
            ->selectRaw('COALESCE(SUM(total_price - paid_amount), 0) as aggregate')
            ->value('aggregate');

        return [
            'total_bookings' => $totalBookings,
            'paid_bookings' => $paidBookings,
            'pending_bookings' => $pendingBookings,
            'cancelled_bookings' => $cancelledBookings,
            'total_revenue' => $totalRevenue,
            'pending_payments' => max(0, $pendingPayments),
        ];
    }

    /**
     * Create a new bus booking (reserve tickets).
     * Does NOT record payment here — payment is separate step.
     * Decrements available_tickets from inventory.
     *
     * @param  array  $data  Validated booking data
     *
     * @throws \Exception
     */
    public function createBooking(array $data): BusBooking
    {
        try {
            return DB::transaction(function () use ($data) {
                $inventory = BusInventory::where('id', $data['inventory_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($inventory->available_tickets < $data['quantity']) {
                    throw new \Exception(
                        'Not enough available tickets. Only '.
                        $inventory->available_tickets.' left.'
                    );
                }

                // Handle customer - create new if not exists
                $customerId = $data['customer_id'] ?? null;
                if (!$customerId && isset($data['customer_name']) && isset($data['customer_phone'])) {
                    $customer = \App\Models\Customer::firstOrCreate(
                        ['phone' => $data['customer_phone']],
                        [
                            'full_name' => $data['customer_name'],
                            'type' => 'individual',
                            'is_active' => true,
                            'created_by' => Auth::id(),
                        ]
                    );
                    $customerId = $customer->id;
                }

                $unitPrice = $inventory->selling_price;
                $totalPrice = $data['quantity'] * $unitPrice;
                $costPerTicket = $inventory->cost_per_ticket;
                $profit = ($unitPrice - $costPerTicket) * $data['quantity'];

                $inventory->decrement('available_tickets', $data['quantity']);

                // Get employee_id from authenticated user if not provided
                $employeeId = $data['employee_id'] ?? null;
                if (! $employeeId && Auth::check()) {
                    $user = Auth::user();
                    if ($user->employee) {
                        $employeeId = $user->employee->id;
                    }
                }
                if (! $employeeId) {
                    $employeeId = Employee::query()->orderBy('id')->value('id');
                }

                $booking = BusBooking::create([
                    'inventory_id' => $data['inventory_id'],
                    'customer_id' => $customerId,
                    'employee_id' => $employeeId,
                    'quantity' => $data['quantity'],
                    'unit_price' => $unitPrice,
                    'total_price' => $totalPrice,
                    'paid_amount' => 0,
                    'payment_status' => BusPaymentStatus::Pending,
                    'profit' => $profit,
                    'status' => BusBookingStatus::Pending,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => Auth::id(),
                ]);

                // ✅ Record cost to company account if available
                $company = $inventory->company;
                if ($company && $company->account_id && $costPerTicket > 0) {
                    $totalCost = $costPerTicket * $data['quantity'];
                    $clearingAccountId = $this->ledgerClearingAccounts->expenseContraIdForModule(TransactionModule::Bus);
                    
                    if ($clearingAccountId) {
                        $this->transactionService->recordJournalTransfer([
                            'amount' => $totalCost,
                            'from_account_id' => $company->account_id, // Debit company (decreases balance/increases debt)
                            'to_account_id' => $clearingAccountId,    // Credit clearing
                            'module' => TransactionModule::Bus->value,
                            'related_type' => BusBooking::class,
                            'related_id' => $booking->id,
                            'notes' => 'تكلفة حجز باص #' . $booking->id,
                            'allow_from_negative' => true,
                        ]);
                    }
                }

                Log::info('Bus booking created', [
                    'booking_id' => $booking->id,
                    'inventory_id' => $inventory->id,
                    'customer_id' => $customerId,
                    'employee_id' => $employeeId,
                    'quantity' => $data['quantity'],
                    'user_id' => Auth::id(),
                ]);

                return $booking->load([
                    'inventory.company',
                    'customer',
                    'employee.user',
                    'account',
                    'payments',
                    'createdBy',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('BusBookingService::createBooking failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'booking_id' => null,
                'inventory_id' => $data['inventory_id'] ?? null,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Record payment for a bus booking.
     * Supports multiple partial payments.
     * Updates payment fields based on total paid amount.
     *
     * @param  array  $data  Validated payment data
     *
     * @throws \Exception
     */
    public function payBooking(BusBooking $booking, array $data): BusBooking
    {
        if ($booking->status === BusBookingStatus::Cancelled) {
            throw new \Exception('Cannot pay for a cancelled booking.');
        }

        try {
            return DB::transaction(function () use ($booking, $data) {
                $booking->refresh();
                $booking->loadSum('payments', 'amount');
                $paidSoFar = (float) ($booking->payments_sum_amount ?? 0);
                $remaining = max(0, (float) $booking->total_price - $paidSoFar);
                $amount = (float) $data['amount'];

                if ($remaining <= 0) {
                    throw new \Exception('This booking is already fully paid.');
                }
                if ($amount > $remaining + 0.000001) {
                    throw new \Exception('Payment amount exceeds remaining balance of '.number_format($remaining, 2));
                }

                // ✅ Create payment record
                $payment = \App\Models\Bus\BusPayment::create([
                    'booking_id' => $booking->id,
                    'amount' => $data['amount'],
                    'payment_method' => $data['payment_method'] ?? 'cash',
                    'account_id' => $data['account_id'],
                    'notes' => $data['notes'] ?? null,
                    'created_by' => Auth::id(),
                ]);

                // ✅ Record transaction if account provided
                $accountId = (int) ($data['account_id'] ?? 0);
                if ($accountId === 0) {
                    $vault = \App\Models\Account::getModuleVault('bus');
                    $accountId = $vault ? $vault->id : null;
                }

                $transactionId = null;
                if ($accountId) {
                    $transaction = $this->transactionService->recordIncome([
                        'amount' => $data['amount'],
                        'to_account_id' => $accountId,
                        'module' => TransactionModule::Bus->value,
                        'related_type' => BusBooking::class,
                        'related_id' => $booking->id,
                        'notes' => $data['notes'] ?? null,
                    ]);
                    $payment->update(['transaction_id' => $transaction->id, 'account_id' => $accountId]);
                    $transactionId = $transaction->id;
                }

                // ✅ Recalculate payment status from ALL payments
                $booking->load('payments');
                $totalPaid = $booking->payments->sum('amount');

                $newPaymentStatus = match (true) {
                    $totalPaid >= $booking->total_price => BusPaymentStatus::Paid,
                    $totalPaid > 0 => BusPaymentStatus::Partial,
                    default => BusPaymentStatus::Pending,
                };

                $newStatus = match ($newPaymentStatus) {
                    BusPaymentStatus::Paid => BusBookingStatus::Paid,
                    default => $booking->status,
                };

                $booking->update([
                    'paid_amount' => $totalPaid,
                    'payment_status' => $newPaymentStatus,
                    'status' => $newStatus,
                    'account_id' => $data['account_id'] ?? $booking->account_id,
                ]);

                Log::info('Bus booking payment recorded', [
                    'booking_id' => $booking->id,
                    'payment_id' => $payment->id,
                    'amount' => $data['amount'],
                    'total_paid' => $totalPaid,
                    'payment_status' => $newPaymentStatus->value,
                    'transaction_id' => $transactionId,
                    'user_id' => Auth::id(),
                ]);

                return $booking->fresh([
                    'inventory.company',
                    'customer',
                    'employee.user',
                    'account',
                    'payments',
                    'transaction',
                    'createdBy',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('BusBookingService::payBooking failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'booking_id' => $booking->id,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Cancel a bus booking.
     * Returns tickets to inventory.
     * No financial refund handled here (done manually by admin if needed).
     *
     * @throws \Exception
     */
    public function cancelBooking(BusBooking $booking): BusBooking
    {
        if ($booking->status === BusBookingStatus::Cancelled) {
            throw new \Exception('This booking is already cancelled.');
        }

        if ($booking->payments()->exists()) {
            throw new \Exception('Cannot cancel a booking that has recorded payments. Use finance adjustment if needed.');
        }

        try {
            return DB::transaction(function () use ($booking) {
                $inventory = $booking->inventory()->lockForUpdate()->first();

                $inventory->increment('available_tickets', $booking->quantity);
                $inventory->save();

                $booking->update([
                    'status' => BusBookingStatus::Cancelled,
                    'payment_status' => BusPaymentStatus::Pending,
                ]);

                // ✅ Reverse company cost if it was recorded
                $company = $booking->inventory?->company;
                if ($company && $company->account_id) {
                    $costPerTicket = $booking->inventory->cost_per_ticket;
                    $totalCost = $costPerTicket * $booking->quantity;
                    $clearingAccountId = $this->ledgerClearingAccounts->expenseContraIdForModule(TransactionModule::Bus);

                    if ($clearingAccountId && $totalCost > 0) {
                        $this->transactionService->recordJournalTransfer([
                            'amount' => $totalCost,
                            'from_account_id' => $clearingAccountId, // Debit clearing (reverse)
                            'to_account_id' => $company->account_id, // Credit company (reverse/increase balance)
                            'module' => TransactionModule::Bus->value,
                            'related_type' => BusBooking::class,
                            'related_id' => $booking->id,
                            'notes' => 'إلغاء تكلفة حجز باص #' . $booking->id,
                            'allow_from_negative' => true,
                        ]);
                    }
                }

                Log::info('Bus booking cancelled', [
                    'booking_id' => $booking->id,
                    'inventory_id' => $inventory->id,
                    'user_id' => Auth::id(),
                ]);

                return $booking->fresh([
                    'inventory.company',
                    'customer',
                    'employee.user',
                    'payments',
                    'createdBy',
                ]);
            });
        } catch (\Exception $e) {
            Log::error('BusBookingService::cancelBooking failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'inventory_id' => $booking->inventory_id,
                'booking_id' => $booking->id,
                'input' => null,
            ]);
            throw $e;
        }
    }

    /**
     * Get a single booking by ID with all relations.
     *
     * @throws ModelNotFoundException
     */
    public function getBookingById(int $id): BusBooking
    {
        return BusBooking::with([
            'inventory.company',
            'customer',
            'employee.user',
            'account',
            'payments',
            'transaction',
            'createdBy',
        ])->findOrFail($id);
    }

    /**
     * Soft delete a booking.
     * Only allowed if status is pending.
     * Returns tickets to inventory.
     *
     * @throws \Exception
     */
    public function deleteBooking(BusBooking $booking): bool
    {
        if ($booking->status !== BusBookingStatus::Pending) {
            throw new \Exception('Only pending bookings can be deleted.');
        }

        if ($booking->payments()->exists()) {
            throw new \Exception('Cannot delete a booking that has payments.');
        }

        try {
            DB::transaction(function () use ($booking) {
                $inventory = $booking->inventory()->lockForUpdate()->first();

                $inventory->increment('available_tickets', $booking->quantity);
                $inventory->save();

                $booking->delete();

                Log::info('Bus booking deleted', [
                    'booking_id' => $booking->id,
                    'inventory_id' => $inventory->id,
                    'user_id' => Auth::id(),
                ]);
            });

            return true;
        } catch (\Exception $e) {
            Log::error('BusBookingService::deleteBooking failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'inventory_id' => $booking->inventory_id,
                'booking_id' => $booking->id,
                'input' => null,
            ]);
            throw $e;
        }
    }
}
