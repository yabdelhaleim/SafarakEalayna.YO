<?php

namespace App\Services\Bus;

use App\Enums\AccountType;
use App\Enums\BusBookingStatus;
use App\Enums\BusInventoryPaymentType;
use App\Enums\BusPaymentStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusInventory;
use App\Models\Bus\BusPayment;
use App\Models\Bus\BusRefundRequest;
use App\Models\Customer;
use App\Models\Employee;
use App\Services\Finance\CurrencyService;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\LedgerEntryDescriptionResolver;
use App\Services\Finance\TransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BusBookingService
{
    protected TransactionService $transactionService;

    protected LedgerClearingAccounts $ledgerClearingAccounts;

    public function __construct(
        TransactionService $transactionService,
        LedgerClearingAccounts $ledgerClearingAccounts
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

        // ── NEW (Phase 6.2 — bug fix): route_from / route_to filters ─────────
        // The Vue BusIndex.vue / busStore.js declare these filters in `state.filters`
        // but the backend never applied them, so the dropdowns silently returned
        // unfiltered results. We now split the inventory route on the common
        // ' - ' / ' ← ' / ' → ' separators and apply a LIKE match on each side.
        if (isset($filters['route_from']) && ($rf = trim((string) $filters['route_from'])) !== '') {
            $query->whereHas('inventory', function ($q) use ($rf) {
                // Match either a literal prefix or any route column starting with this token.
                $q->where('route', 'like', $rf.'%')
                    ->orWhere('route', 'like', $rf.' -%')
                    ->orWhere('route', 'like', $rf.' ←%')
                    ->orWhere('route', 'like', $rf.' →%');
            });
        }
        if (isset($filters['route_to']) && ($rt = trim((string) $filters['route_to'])) !== '') {
            $query->whereHas('inventory', function ($q) use ($rt) {
                // Match either a literal suffix or any route column ending with this token.
                $q->where('route', 'like', '%- '.$rt)
                    ->orWhere('route', 'like', '%← '.$rt)
                    ->orWhere('route', 'like', '%→ '.$rt)
                    ->orWhere('route', 'like', '%'.$rt);
            });
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
     * Supports two modes:
     *   A) inventory_id provided → use existing Filament-managed inventory
     *   B) company_id + route + selling_price → auto-create inventory (deferred/آجل)
     *
     * Multi-currency contract (Phase 6 — multi-currency wiring):
     *   - Inventory's currency is the BOOKING's currency (mirrored).
     *   - Customer AR account is created in booking currency (with FX tag).
     *   - Company debt is always posted in EGP (operating currency).
     *   - Income clearing offset is always in EGP (system-wide pivot).
     *
     * @param  array  $data  Validated booking data
     *
     * @throws \Exception
     */
    public function createBooking(array $data): BusBooking
    {
        try {
            return DB::transaction(function () use ($data) {

                // ── Resolve inventory ───────────────────────────────────────────
                if (! empty($data['inventory_id'])) {
                    // Mode A: explicit Filament inventory
                    $inventory = BusInventory::where('id', $data['inventory_id'])
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($inventory->available_tickets < $data['quantity']) {
                        throw new \Exception(
                            'لا توجد تذاكر كافية. المتاح: '.$inventory->available_tickets
                        );
                    }
                } else {
                    // Mode B: manual route → find-or-create auto inventory
                    $inventory = $this->findOrCreateAutoInventory($data);
                }

                // ── Resolve currency + FX snapshot from inventory ────────────────
                $bookingCurrency = strtoupper((string) ($inventory->currency ?? 'EGP'));
                $bookingFxRate = (float) ($inventory->exchange_rate_to_egp ?? 1.0);
                if ($bookingCurrency !== 'EGP' && $bookingFxRate <= 0) {
                    throw new \InvalidArgumentException(
                        "Cannot book inventory #{$inventory->id}: it is priced in {$bookingCurrency} ".
                        'but has no exchange_rate_to_egp snapshot. Re-save the inventory with a valid rate.'
                    );
                }

                // ── Resolve customer ────────────────────────────────────────────
                $customerId = $data['customer_id'] ?? null;
                if (! $customerId && isset($data['customer_name']) && isset($data['customer_phone'])) {
                    $customer = Customer::firstOrCreate(
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

                $unitPrice = (float) $inventory->selling_price;
                $totalPrice = $data['quantity'] * $unitPrice;
                $costPerTicket = (float) $inventory->cost_per_ticket;
                $profit = ($unitPrice - $costPerTicket) * $data['quantity'];

                $inventory->decrement('available_tickets', $data['quantity']);

                // ── Resolve employee ────────────────────────────────────────────
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

                // Wrapped in BusBooking::runProfitMutation() so the ModelProfitMutationGuard lets
                // the canonical `profit` write through — see BusBooking::booted()
                // saving observer.
                $booking = BusBooking::runProfitMutation(function () use ($inventory, $customerId, $employeeId, $data, $unitPrice, $totalPrice, $profit, $bookingCurrency, $bookingFxRate) {
                    return BusBooking::create([
                        'inventory_id' => $inventory->id,
                        'customer_id' => $customerId,
                        'employee_id' => $employeeId,
                        'quantity' => $data['quantity'],
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPrice,
                        'paid_amount' => 0,
                        'payment_status' => BusPaymentStatus::Pending,
                        'profit' => $profit,
                        'status' => BusBookingStatus::Pending,
                        'currency' => $bookingCurrency,
                        'exchange_rate_to_egp' => $bookingFxRate,
                        'notes' => $data['notes'] ?? null,
                        'created_by' => Auth::id(),
                    ]);
                });

                // ── Company debt posting (always in EGP) ────────────────────────
                $company = $inventory->company;
                if ($company && $costPerTicket > 0) {
                    $companyService = app(BusCompanyService::class);
                    $companyAccount = $companyService->ensureCompanyAccount($company);
                    $company->account_id = $companyAccount->id;

                    // The supplier ledger is always EGP (operating currency).
                    // Convert the foreign cost up-front to EGP so the
                    // same-currency transfer posts the right figure.
                    $totalCostForeign = $costPerTicket * $data['quantity'];
                    $totalCostEgp = $totalCostForeign; // default: same-currency case
                    if ($bookingCurrency !== 'EGP') {
                        $converted = $this->convertAmount($totalCostForeign, $bookingCurrency, 'EGP');
                        $totalCostEgp = round((float) $converted['to_amount'], 2);
                    }

                    $clearingAccountId = $this->ledgerClearingAccounts->expenseContraIdForModule(TransactionModule::Bus);

                    // يمنع تسجيل الإيراد بدون COGS — يؤدي لتضخيم صافي الربح
                    if (! $clearingAccountId) {
                        throw new \Exception(
                            'لم يُعيَّن حساب إقفال تكاليف الباص في إعدادات الحسابات. '.
                            'يرجى إعداد حساب expense clearing لموديول الباص قبل تسجيل الحجز.'
                        );
                    }

                    $this->transactionService->recordJournalTransfer([
                        'amount' => $totalCostEgp,
                        'from_account_id' => $company->account_id,
                        'to_account_id' => $clearingAccountId,
                        'module' => TransactionModule::Bus->value,
                        'related_type' => BusBooking::class,
                        'related_id' => $booking->id,
                        'notes' => 'تكلفة حجز باص #'.$booking->id.' — '.$inventory->route.
                            ($bookingCurrency !== 'EGP'
                                ? " ({$totalCostForeign} {$bookingCurrency} → {$totalCostEgp} EGP)"
                                : ''),
                        'allow_from_negative' => true,
                    ]);
                }

                // ── Customer sale posting ────────────────────────────────────────
                $this->recordSaleToCustomer(
                    $booking,
                    (int) $customerId,
                    $totalPrice,
                    Auth::id() ?? 1
                );

                Log::info('Bus booking created', [
                    'booking_id' => $booking->id,
                    'inventory_id' => $inventory->id,
                    'auto_created' => $inventory->is_auto_created,
                    'customer_id' => $customerId,
                    'employee_id' => $employeeId,
                    'quantity' => $data['quantity'],
                    'route' => $inventory->route,
                    'currency' => $bookingCurrency,
                    'fx_rate_to_egp' => $bookingFxRate,
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
                'input' => $data,
            ]);
            throw $e;
        }
    }

    /**
     * Convert an amount from one currency to another using the CurrencyService.
     * Returns the array format expected by TransactionService::recordJournalTransfer.
     *
     * @return array{from_amount: float, from_currency: string, to_amount: float, to_currency: string, rate: float}
     */
    protected function convertAmount(float $amount, string $fromCurrency, string $toCurrency): array
    {
        return app(CurrencyService::class)->convert($amount, $fromCurrency, $toCurrency, now());
    }

    /**
     * Find an existing auto-created inventory for (company + route + date)
     * or create a new deferred one with unlimited capacity.
     * This is used when booking directly from the Vue.js frontend (no Filament setup needed).
     *
     * Bug #B-01 fix: `travel_date` was passed through unchanged, which caused the
     * dedup query (`where travel_date = '2026-08-01'`) to never match the
     * existing row (stored as `'2026-08-01 00:00:00'` by the SQLite/MySQL
     * backend). Normalising to Y-m-d on both write and read paths restores the
     * dedup invariant.
     */
    protected function findOrCreateAutoInventory(array $data): BusInventory
    {
        $companyId = (int) $data['company_id'];
        $route = trim($data['route']);
        $sellingPrice = round((float) ($data['selling_price'] ?? 0), 2);
        $costPrice = round((float) ($data['cost_price'] ?? $sellingPrice), 2); // سعر الشراء — المديونية للشركة
        $travelDate = \Carbon\Carbon::parse($data['travel_date'] ?? now())->toDateString();

        // Try to find an existing auto-inventory for same company + route + date + prices.
        // We compare against `DATE(travel_date)` so the comparison is engine-agnostic
        // (works on MySQL date columns and SQLite TEXT-stored dates).
        $existing = BusInventory::where('company_id', $companyId)
            ->where('route', $route)
            ->where(DB::raw('DATE(travel_date)'), $travelDate)
            ->where('selling_price', $sellingPrice)
            ->where('cost_per_ticket', $costPrice)
            ->where('is_auto_created', true)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            return $existing;
        }

        // Create new auto-inventory:
        //   cost_per_ticket = سعر الشراء   → ما ندفعه للشركة (الآجل)
        //   selling_price   = سعر البيع    → ما يدفعه العميل
        //   profit margin   = selling - cost (تُحسب في createBooking)
        return BusInventory::create([
            'company_id' => $companyId,
            'route' => $route,
            'travel_date' => $travelDate,
            'departure_time' => $data['departure_time'] ?? null,
            'total_tickets' => 999999,
            'available_tickets' => 999999,
            'cost_per_ticket' => $costPrice,    // ← المديونية للشركة
            'selling_price' => $sellingPrice, // ← سعر البيع للعميل
            'payment_type' => BusInventoryPaymentType::Deferred,
            'total_cost' => 0,
            'amount_paid' => 0,
            'remaining_debt' => 0,
            'is_auto_created' => true,
            'notes' => 'تلقائي — '.$route.' — شراء '.$costPrice.' / بيع '.$sellingPrice,
            'created_by' => Auth::id(),
        ]);
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
                $payment = BusPayment::create([
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
                    $vault = Account::getModuleVault('bus');
                    $accountId = $vault ? $vault->id : null;
                }

                $transactionId = null;
                if ($accountId) {
                    $customerAccount = $this->ensureCustomerAccount(
                        (int) $booking->customer_id,
                        (string) ($booking->currency ?? 'EGP')
                    );
                    $paidAccount = Account::find($accountId);
                    $bookingCurrency = strtoupper((string) ($booking->currency ?? 'EGP'));
                    $paidAccountCurrency = strtoupper((string) ($paidAccount?->currency ?? 'EGP'));

                    // Phase 7 — FX-aware payment routing:
                    // The income clearing is ALWAYS EGP (per the saving-hook
                    // contract), so the FX trigger is "booking currency != EGP".
                    // When triggered we convert the booking-currency amount into
                    // an EGP-equivalent for the clearing-side debit (`amount`)
                    // and keep the original foreign-currency amount as the
                    // `converted_amount` for the wallet-side credit.
                    //
                    // Convention reminder — recordJournalTransfer:
                    //   amount          → debit on from_account (clearing, EGP)
                    //   converted_amount→ credit on to_account  (paid_account, foreign)
                    $incomeArgs = [
                        'amount' => (float) $data['amount'],
                        'to_account_id' => $accountId,
                        'module' => TransactionModule::Bus->value,
                        'related_type' => BusBooking::class,
                        'related_id' => $booking->id,
                        'notes' => $data['notes'] ?? null,
                    ];

                    if ($bookingCurrency !== 'EGP' && $bookingCurrency !== $paidAccountCurrency) {
                        // Booking is foreign AND the pay account is a different currency
                        // (typically EGP, but possibly another non-EGP currency).
                        // We need the EGP-equivalent of the booking amount.
                        $converted = $this->convertAmount(
                            (float) $data['amount'],
                            $bookingCurrency,
                            'EGP'
                        );
                        $incomeArgs['amount'] = round((float) $converted['to_amount'], 2);          // EGP (clearing debit)
                        $incomeArgs['converted_amount'] = round((float) $data['amount'], 2);         // foreign (wallet credit)
                        $incomeArgs['exchange_rate'] = $converted['rate'];
                    } elseif ($bookingCurrency !== 'EGP' && $bookingCurrency === $paidAccountCurrency) {
                        // Booking is foreign AND we pay from a same-currency account (e.g. USD/USD).
                        // The clearing (EGP) still receives the EGP-equivalent debit;
                        // the paid_account (foreign, same as booking) receives the exact
                        // booking amount as credit. We pass converted_amount so the
                        // wallet side gets the booking-currency amount rather than a
                        // synthetic 1:1 with the EGP clearing.
                        $converted = $this->convertAmount(
                            (float) $data['amount'],
                            $bookingCurrency,
                            'EGP'
                        );
                        $incomeArgs['amount'] = round((float) $converted['to_amount'], 2);          // EGP (clearing debit)
                        $incomeArgs['converted_amount'] = round((float) $data['amount'], 2);         // foreign (wallet credit)
                        $incomeArgs['exchange_rate'] = $converted['rate'];
                    }

                    $transaction = $this->transactionService->recordIncome($incomeArgs);
                    $payment->update(['transaction_id' => $transaction->id, 'account_id' => $accountId]);
                    $transactionId = $transaction->id;
                }

                // ✅ Recalculate payment status from ALL payments
                $booking->load('payments');
                $totalPaid = $booking->payments->sum('amount');

                $newPaymentStatus = match (true) {                    $totalPaid >= $booking->total_price => BusPaymentStatus::Paid,                    $totalPaid > 0 => BusPaymentStatus::Partial,                    default => BusPaymentStatus::Pending,                };                $newStatus = match ($newPaymentStatus) {                    BusPaymentStatus::Paid => BusBookingStatus::Paid,                    default => $booking->status,                };                $booking->update([                    'paid_amount' => $totalPaid,                    'payment_status' => $newPaymentStatus,                    'status' => $newStatus,                    'account_id' => $data['account_id'] ?? $booking->account_id,                ]);                Log::info('Bus booking payment recorded', [                    'booking_id' => $booking->id,                    'payment_id' => $payment->id,                    'amount' => $data['amount'],                    'total_paid' => $totalPaid,                    'payment_status' => $newPaymentStatus->value,                    'transaction_id' => $transactionId,                    'user_id' => Auth::id(),                ]);                return $booking->fresh([                    'inventory.company',                    'customer',                    'employee.user',                    'account',                    'payments',                    'transaction',                    'createdBy',                ]);
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
     * Cancel a bus booking with optional penalties and customer cash refund.
     *
     * @param  array  $data  company_penalty, office_penalty, account_id, notes
     *
     * @throws \Exception
     */
    public function cancelBooking(BusBooking $booking, array $data = []): BusRefundRequest
    {
        if (in_array($booking->status, [
            BusBookingStatus::Cancelled,
            BusBookingStatus::Refunded,
            BusBookingStatus::PartiallyRefunded,
        ], true)) {
            throw new \Exception('الحجز ملغي أو مسترد بالفعل.');
        }

        try {
            return DB::transaction(function () use ($booking, $data) {
                $userId = Auth::id() ?: 1;

                $booking = BusBooking::query()
                    ->with(['inventory.company', 'customer', 'payments'])
                    ->lockForUpdate()
                    ->findOrFail($booking->id);

                $companyPenalty = (float) ($data['company_penalty'] ?? 0);
                $officePenalty = (float) ($data['office_penalty'] ?? 0);
                $totalPenalties = $companyPenalty + $officePenalty;

                $totalPaid = (float) $booking->payments()->sum('amount');
                $totalPrice = (float) $booking->total_price;

                if ($totalPenalties > $totalPrice + 0.001) {
                    throw new \InvalidArgumentException('مجموع الخصومات لا يمكن أن يتجاوز سعر البيع.');
                }

                if ($totalPaid > 0.001 && $totalPenalties > $totalPaid + 0.001) {
                    throw new \InvalidArgumentException('مجموع الخصومات لا يمكن أن يتجاوز المبلغ المدفوع من العميل.');
                }

                $refundAmount = max(0, $totalPaid - $totalPenalties);

                if ($refundAmount > 0.001 && empty($data['account_id'])) {
                    throw new \InvalidArgumentException('يجب اختيار حساب الصرف عند وجود مبلغ مرتجع للعميل.');
                }

                $inventory = $booking->inventory()->lockForUpdate()->first();
                // Phase 7 — multi-currency cost reversal:
                // The supplier debt was originally posted in EGP (operating
                // currency). When cancelling we must reverse the SAME EGP
                // figure — not the foreign cost as-listed on the inventory.
                $totalCostForeign = (float) ($inventory->cost_per_ticket ?? 0) * (int) $booking->quantity;
                $bookingCurrency = strtoupper((string) ($booking->currency ?? 'EGP'));
                $totalCost = $totalCostForeign; // default: same currency
                if ($bookingCurrency !== 'EGP') {
                    $converted = $this->convertAmount(
                        $totalCostForeign,
                        $bookingCurrency,
                        'EGP'
                    );
                    $totalCost = round((float) $converted['to_amount'], 2);
                }
                $companyCreditAmount = max(0, $totalCost - $companyPenalty);

                Log::info('Processing bus booking cancellation', [
                    'booking_id' => $booking->id,
                    'total_paid' => $totalPaid,
                    'total_price' => $totalPrice,
                    'total_cost' => $totalCost,
                    'company_penalty' => $companyPenalty,
                    'office_penalty' => $officePenalty,
                    'refund_amount' => $refundAmount,
                    'user_id' => $userId,
                ]);

                $this->applyCompanyCreditOnCancel($booking, $inventory, $companyCreditAmount);

                $inventory->increment('available_tickets', $booking->quantity);

                // Phase 7 — Reverse the customer AR in BOTH cases:
                //   (a) unpaid debt (debtReversalAmount), OR
                //   (b) the refund amount (when a refund was issued for a paid
                //       booking — the AR was created at booking time and is
                //       still on the books until cancellation clears it).
                // Previously only (a) was handled; the refund scenario left
                // the customer's AR stranded at +price indefinitely.
                $debtReversalAmount = max(0, $totalPrice - max($totalPaid, $totalPenalties));
                $arReversalAmount = $debtReversalAmount + ($refundAmount > 0.001 ? $refundAmount : 0);
                $this->reverseCustomerSaleDebt($booking, $arReversalAmount);

                $refundLedgerTx = null;
                if ($refundAmount > 0.001 && ! empty($data['account_id'])) {
                    // Phase 7 — Multi-currency refund wiring:
                    // The refund must be posted in the destination account's currency.
                    // When the booking was priced in a foreign currency (USD/SAR/KWD)
                    // and the chosen refund account is EGP, we convert via
                    // CurrencyService and post the EGP-equivalent as same-currency
                    // journal entry. When currencies match, no conversion is needed.
                    $refundAccount = Account::query()->lockForUpdate()->find((int) $data['account_id']);
                    $refundAccountCurrency = strtoupper((string) ($refundAccount?->currency ?? 'EGP'));
                    $bookingCurrency = strtoupper((string) ($booking->currency ?? 'EGP'));

                    $refundAmountSameCurrency = $refundAmount;
                    $fxNote = '';
                    if ($refundAccount && $refundAccountCurrency !== $bookingCurrency) {
                        $converted = $this->convertAmount($refundAmount, $bookingCurrency, $refundAccountCurrency);
                        $refundAmountSameCurrency = round((float) $converted['to_amount'], 2);
                        $fxNote = " ({$refundAmount} {$bookingCurrency} → {$refundAmountSameCurrency} {$refundAccountCurrency})";
                    }

                    $refundLedgerTx = $this->transactionService->recordExpense([
                        'amount' => $refundAmountSameCurrency,
                        'from_account_id' => (int) $data['account_id'],
                        'module' => TransactionModule::Bus->value,
                        'related_type' => BusBooking::class,
                        'related_id' => $booking->id,
                        'notes' => 'استرداد حجز باص #'.$booking->id.$fxNote,
                    ]);
                }

                $company = $inventory->company;

                // The BusRefundRequest stores the booking-currency snapshot so
                // downstream reports (refund history, audit log) reflect the
                // original currency even after the company's reports only display
                // the EGP-base equivalent.
                $bookingCurrency = strtoupper((string) ($booking->currency ?? 'EGP'));
                $bookingFxRate = (float) ($booking->exchange_rate_to_egp ?? 1.0);

                $refund = BusRefundRequest::create([
                    'bus_booking_id' => $booking->id,
                    'company_id' => $company?->id ?? $inventory->company_id,
                    'refund_type' => 'cancel',
                    'original_currency' => $bookingCurrency,
                    'original_amount' => $totalPrice,
                    'cancellation_fee' => $totalPenalties,
                    'company_penalty' => $companyPenalty,
                    'office_penalty' => $officePenalty,
                    'total_paid' => $totalPaid,
                    'refund_amount' => $refundAmount,
                    'refund_currency' => $bookingCurrency,
                    'refund_exchange_rate' => $bookingFxRate,
                    'base_currency_refund' => $refundAmount * $bookingFxRate,
                    'destination' => 'ledger',
                    'account_id' => $data['account_id'] ?? null,
                    'transaction_id' => $refundLedgerTx?->id,
                    'status' => 'processed',
                    'processed_at' => now(),
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $userId,
                ]);

                $newStatus = match (true) {                    $refundAmount > 0.001 => BusBookingStatus::Refunded,                    $totalPenalties > 0.001 => BusBookingStatus::PartiallyRefunded,                    default => BusBookingStatus::Cancelled,                };                $booking->update([                    'status' => $newStatus,                    'payment_status' => $refundAmount > 0.001                        ? BusPaymentStatus::Pending                        : $booking->payment_status,                ]);                Log::info('Bus booking cancelled successfully', [                    'booking_id' => $booking->id,                    'refund_id' => $refund->id,                    'new_status' => $newStatus->value,                    'user_id' => $userId,                ]);                return $refund->load(['booking', 'account', 'transaction', 'createdBy']);
            });
        } catch (\InvalidArgumentException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('BusBookingService::cancelBooking failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'inventory_id' => $booking->inventory_id,
                'booking_id' => $booking->id,
                'input' => $data,
            ]);
            throw new \Exception('فشل إلغاء الحجز: '.$e->getMessage());
        }
    }

    /**
     * Reduce company purchase debt (or block if debt was already settled).
     */
    protected function applyCompanyCreditOnCancel(
        BusBooking $booking,
        BusInventory $inventory,
        float $companyCreditAmount
    ): void {
        if ($companyCreditAmount <= 0.001) {
            return;
        }

        $company = $inventory->company;
        if (! $company || ! $company->account_id) {
            return;
        }

        $companyAccount = Account::query()->lockForUpdate()->find($company->account_id);
        if (! $companyAccount) {
            return;
        }

        $balance = (float) $companyAccount->balance;

        if ($balance >= 0) {
            throw new \Exception(
                'لا يمكن إلغاء هذا الحجز لأن دين الشركة تم تسديده بالفعل (رصيد الشركة: '.
                number_format($balance, 2).' ج.م). '.
                'قم بتسوية يدوية من خلال قسم المحاسبة لاسترداد المبلغ من الشركة أولاً.'
            );
        }

        $owed = abs($balance);
        if ($companyCreditAmount > $owed + 0.001) {
            throw new \Exception(
                'لا يمكن إلغاء هذا الحجز لأن جزءاً من دين الشركة تم تسديده بالفعل (المديونية المتبقية: '.
                number_format($owed, 2).' ج.م). '.
                'قم بتسوية يدوية من خلال قسم المحاسبة.'
            );
        }

        $clearingAccountId = $this->ledgerClearingAccounts->expenseContraIdForModule(TransactionModule::Bus);
        if (! $clearingAccountId) {
            return;
        }

        $this->transactionService->recordJournalTransfer([
            'amount' => $companyCreditAmount,
            'from_account_id' => $clearingAccountId,
            'to_account_id' => $company->account_id,
            'module' => TransactionModule::Bus->value,
            'related_type' => BusBooking::class,
            'related_id' => $booking->id,
            'notes' => 'إلغاء تكلفة حجز باص #'.$booking->id.' (بعد خصم الشركة)',
            'allow_from_negative' => true,
        ]);
    }

    /**
     * Partially or fully reverse customer sale debt on cancellation.
     *
     * Phase 7 — multi-currency: the customer's AR account holds the
     * booking-currency balance (EGP for EGP bookings, USD for USD, etc.).
     * The income clearing account is always EGP. When currencies differ we
     * pass the converted `converted_amount` to `recordJournalTransfer` so the
     * EGP-side clearing posts the right figure (debit-from source = `amount`
     * in customer currency; credit-to destination = `converted_amount` in
     * EGP-equivalent).
     */
    protected function reverseCustomerSaleDebt(BusBooking $booking, float $amount): void
    {
        if ($amount <= 0.001 || ! $booking->customer_id) {
            return;
        }

        $customerAccount = $this->ensureCustomerAccount(
            (int) $booking->customer_id,
            (string) ($booking->currency ?? 'EGP')
        );
        $clearingAccountId = $this->ledgerClearingAccounts->incomeContraIdForModule(TransactionModule::Bus->value);

        if (! $clearingAccountId || $clearingAccountId === $customerAccount->id) {
            return;
        }

        $journalArgs = [
            'amount' => $amount,
            'from_account_id' => $customerAccount->id,
            'to_account_id' => $clearingAccountId,
            'module' => TransactionModule::Bus->value,
            'related_type' => BusBooking::class,
            'related_id' => $booking->id,
            'notes' => 'إلغاء مديونية حجز باص #'.$booking->id,
            'allow_from_negative' => true,
        ];

        $bookingCurrency = strtoupper((string) ($booking->currency ?? 'EGP'));
        if ($bookingCurrency !== 'EGP' && strtoupper((string) $customerAccount->currency) !== 'EGP') {
            // Cross-currency posting convention:
            //   amount           → debit on `from_account` (in from_account currency = foreign customer)
            //   converted_amount → credit on `to_account` (in to_account currency = EGP clearing)
            // We therefore:
            //   1. Compute the EGP-equivalent of the foreign-currency debt.
            //   2. Set `amount`           = the original foreign-currency amount (customer debit).
            //   3. Set `converted_amount` = the EGP-equivalent (clearing credit).
            $converted = $this->convertAmount($amount, $bookingCurrency, 'EGP');
            $journalArgs['amount'] = round($amount, 2);                              // foreign (customer debit)
            $journalArgs['converted_amount'] = round((float) $converted['to_amount'], 2); // EGP (clearing credit)
            $journalArgs['exchange_rate'] = $converted['rate'];
        }

        $this->transactionService->recordJournalTransfer($journalArgs);
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
     * Soft delete a booking (simple admin delete).
     *
     * Allowed for ANY status — the old `'Only pending'` constraint has been
     * loosened per the Bus deletion contract (mirrors Flight/HajjUmra/Visa
     * philosophy of separating cancel vs admin-delete).
     *
     * Still REQUIRES no payments to exist. If payments exist, callers must
     * use `deleteBookingWithReversal()` instead — otherwise the customer
     * ledger would end up with negative balance after sale-debt reversal.
     *
     * Performs additive ledger reversal:
     *   ① Reverses company cost entry (recordJournalTransfer, expense clearing → company account).
     *   ② Reverses customer sale debt (recordJournalTransfer, customer account → income clearing).
     *   ③ Restores inventory tickets.
     *   ④ Soft-deletes the booking row.
     *
     * Wrap is done via `BusBooking::run()` so the new `deleting` observer
     * (with `ModelDeletionGuard`) allows the soft-delete. The observer
     * still throws for direct `$booking->delete()` from outside any
     * canonical path (Filament raw DeleteAction, accidental API hits,
     * tinker mistakes).
     *
     * @throws \Exception if payments exist (caller must use deleteBookingWithReversal)
     * @throws \RuntimeException if called outside BusBooking::run()
     */
    public function deleteBooking(BusBooking $booking): bool
    {
        if ($booking->payments()->exists()) {
            throw new \Exception(
                'لا يمكن حذف هذا الحجز لوجود مدفوعات مرتبطة به. '
                .'استخدم BusBookingService::deleteBookingWithReversal() للحذف الإداري الشامل مع عكس المدفوعات.'
            );
        }

        try {
            return BusBooking::run(function () use ($booking) {
                return DB::transaction(function () use ($booking) {
                    $inventory = $booking->inventory()->lockForUpdate()->first();

                    // 🛡️ ACCOUNTING INTEGRITY (same guard as cancelBooking — inside transaction with lock)
                    $companyForCheck = $inventory->company;
                    if ($companyForCheck && $companyForCheck->account_id) {
                        $costForThisBooking = (float) ($inventory->cost_per_ticket ?? 0) * $booking->quantity;
                        if ($costForThisBooking > 0) {
                            $companyAccount = Account::lockForUpdate()->find($companyForCheck->account_id);
                            if ($companyAccount && (float) $companyAccount->balance >= 0) {
                                throw new \Exception(
                                    'لا يمكن حذف هذا الحجز لأن دين الشركة تم تسديده بالفعل (رصيد الشركة: '.
                                    number_format((float) $companyAccount->balance, 2).' ج.م). '.
                                    'يرجى تسوية يدوية من خلال قسم المحاسبة لاسترداد المبلغ من الشركة أولاً.'
                                );
                            }
                        }
                    }

                    $inventory->increment('available_tickets', $booking->quantity);
                    $inventory->save();

                    // ✅ Reverse company cost if it was recorded
                    $company = $inventory->company;
                    if ($company && $company->account_id) {
                        $costPerTicket = $inventory->cost_per_ticket;
                        $totalCost = $costPerTicket * $booking->quantity;
                        $clearingAccountId = $this->ledgerClearingAccounts->expenseContraIdForModule(TransactionModule::Bus);

                        if ($clearingAccountId && $totalCost > 0) {
                            $this->transactionService->recordJournalTransfer([
                                'amount' => $totalCost,
                                'from_account_id' => $clearingAccountId,
                                'to_account_id' => $company->account_id,
                                'module' => TransactionModule::Bus->value,
                                'related_type' => BusBooking::class,
                                'related_id' => $booking->id,
                                'notes' => 'حذف تكلفة حجز باص #'.$booking->id,
                                'allow_from_negative' => true,
                            ]);
                        }
                    }

                    // ✅ Reverse sale to customer
                    $customer = $booking->customer;
                    if ($customer && $customer->account_id) {
                        $clearingAccountId = $this->ledgerClearingAccounts->incomeContraIdForModule(TransactionModule::Bus->value);
                        if ($clearingAccountId) {
                            $this->transactionService->recordJournalTransfer([
                                'amount' => $booking->total_price,
                                'from_account_id' => $customer->account_id,
                                'to_account_id' => $clearingAccountId,
                                'module' => TransactionModule::Bus->value,
                                'related_type' => BusBooking::class,
                                'related_id' => $booking->id,
                                'notes' => 'حذف مديونية حجز باص #'.$booking->id,
                                'allow_from_negative' => true,
                            ]);
                        }
                    }

                    $booking->delete();

                    Log::info('Bus booking deleted (simple path)', [
                        'booking_id' => $booking->id,
                        'inventory_id' => $inventory->id,
                        'status' => $booking->status?->value ?? (string) $booking->status,
                        'user_id' => Auth::id(),
                    ]);

                    return true;
                });
            });
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

    /**
     * Administrative soft-delete with full financial reversal.
     *
     * Mirrors the canonical `FlightBookingService::deleteBookingWithReversal()`
     * and `HajjUmraBookingService::deleteBookingWithReversal()` /
     * `VisaBookingService::deleteBookingWithReversal()` pattern. Use this
     * when an admin needs to fully remove a booking from active lists while:
     *   ① reversing EVERY payment transaction (additive — never destructive),
     *   ② reversing company cost + customer sale ledger entries (additive),
     *   ③ restoring inventory tickets,
     *   ④ soft-deleting the payment rows (via new SoftDeletes trait),
     *   ⑤ soft-deleting the booking row itself.
     *
     * Use this when `deleteBooking()` would refuse (booking has payments).
     * For operational cancellation that keeps the booking visible, use
     * `cancelBooking()` instead.
     *
     * Idempotency: throws RuntimeException if the booking is already
     * soft-deleted (matches the Flight/HajjUmra/Visa pattern).
     *
     * @throws \RuntimeException on duplicates
     * @throws \Exception if not inside BusBooking::run()
     */
    public function deleteBookingWithReversal(int $bookingId, ?int $userId = null): bool
    {
        return BusBooking::run(function () use ($bookingId, $userId) {
            return DB::transaction(function () use ($bookingId, $userId) {
                // 1) Lock + reload with relations (withTrashed so an already-
                //    soft-deleted booking surfaces a clean error, not "No query results").
                $booking = BusBooking::query()
                    ->withTrashed()
                    ->with(['inventory.company', 'customer', 'payments.transaction'])
                    ->lockForUpdate()
                    ->findOrFail($bookingId);

                // 2) Idempotency guard — second call returns a clean Arabic error.
                if ($booking->trashed()) {
                    throw new \RuntimeException(
                        'هذا الحجز محذوف بالفعل (soft delete) — لا يمكن عكسه مرة ثانية.'
                    );
                }

                $userIdEffective = $userId ?: (int) (Auth::id() ?: 1);

                Log::info('BusBookingService::deleteBookingWithReversal — starting', [
                    'booking_id' => $booking->id,
                    'status' => $booking->status?->value ?? (string) $booking->status,
                    'payments_count' => $booking->payments->count(),
                    'user_id' => $userIdEffective,
                ]);

                // 3) Reverse every payment transaction (additive — never destructive).
                foreach ($booking->payments as $payment) {
                    if ($payment->transaction_id) {
                        $tx = \App\Models\Transaction::find($payment->transaction_id);
                        if ($tx) {
                            $this->transactionService->reverseTransaction($tx);
                        }
                    }
                }

                // 4) Reverse the booking's ledger entries (company cost + customer sale debt).
                $inventory = $booking->inventory;
                $company = $inventory->company ?? null;

                if ($company && $company->account_id) {
                    $totalCost = (float) ($inventory->cost_per_ticket ?? 0) * (int) $booking->quantity;
                    $clearingAccountId = $this->ledgerClearingAccounts->expenseContraIdForModule(TransactionModule::Bus);

                    if ($clearingAccountId && $totalCost > 0) {
                        $this->transactionService->recordJournalTransfer([
                            'amount' => $totalCost,
                            'from_account_id' => $clearingAccountId,
                            'to_account_id' => $company->account_id,
                            'module' => TransactionModule::Bus->value,
                            'related_type' => BusBooking::class,
                            'related_id' => $booking->id,
                            'notes' => 'عكس تكلفة حجز باص #'.$booking->id.' (حذف إداري شامل)',
                            'allow_from_negative' => true,
                        ]);
                    }
                }

                $customer = $booking->customer;
                if ($customer && $customer->account_id) {
                    $clearingAccountId = $this->ledgerClearingAccounts->incomeContraIdForModule(TransactionModule::Bus->value);
                    if ($clearingAccountId) {
                        $this->transactionService->recordJournalTransfer([
                            'amount' => (float) $booking->total_price,
                            'from_account_id' => $customer->account_id,
                            'to_account_id' => $clearingAccountId,
                            'module' => TransactionModule::Bus->value,
                            'related_type' => BusBooking::class,
                            'related_id' => $booking->id,
                            'notes' => 'عكس مديونية حجز باص #'.$booking->id.' (حذف إداري شامل)',
                            'allow_from_negative' => true,
                        ]);
                    }
                }

                // 5) Restore inventory tickets (same as deleteBooking).
                if ($inventory) {
                    $inventory->increment('available_tickets', $booking->quantity);
                }

                // 6) Soft-delete the payment rows (requires SoftDeletes on bus_payments).
                $booking->payments()->delete();

                // 7) Soft-delete the booking row itself. Allowed because we are
                //    inside BusBooking::run(...) which flipped the model's deletion
                //    gate open for the canonical reversal flow.
                $booking->delete();

                Log::info('BusBookingService::deleteBookingWithReversal — complete', [
                    'booking_id' => $booking->id,
                    'user_id' => $userIdEffective,
                ]);

                return true;
            });
        });
    }

    /**
     * Ensures the customer has a ledger account. Creates one if missing.
     *
     * Multi-currency contract:
     *   - If `$customerCurrency` is supplied (e.g. derived from the booking's
     *     currency) and the existing account is in EGP, we create a new account
     *     in the desired currency rather than re-tagging the existing one
     *     (mixing currencies on one account would corrupt the ledger).
     */
    protected function ensureCustomerAccount(int $customerId, ?string $customerCurrency = null): Account
    {
        $customer = Customer::findOrFail($customerId);

        if ($customer->account_id) {
            $account = Account::find($customer->account_id);
            if ($account) {
                // Phase 1.Bend3 fix: CustomerLedgerObserver creates a generic
                // 'office'-tagged account the moment a Customer row is
                // inserted. When that customer is later used in a Bus
                // booking flow we re-tag the account to 'bus' so it surfaces
                // in the strict module_type='bus' queries (e.g. TreasuryService
                // line 718). Wrapped in LedgerBalanceMutationGuard because
                // touching `balance` — even to confirm 0.00 — would otherwise
                // trip the Account::updating boot guard.
                if ($account->module_type !== 'bus') {
                    LedgerBalanceMutationGuard::run(function () use ($account) {
                        $account->module_type = 'bus';
                        $account->save();
                    });
                }

                // If the booking is in a foreign currency but the existing
                // customer account is EGP-only, we have to open a second
                // account in the booking currency and link it back to the
                // customer. This preserves per-currency balance tracking
                // — the existing EGP ledger is left intact.
                if ($customerCurrency && strtoupper($customerCurrency) !== 'EGP'
                    && strtoupper((string) $account->currency) !== strtoupper($customerCurrency)) {
                    return $this->createCustomerCurrencyAccount($customer, $customerCurrency);
                }

                return $account;
            }
        }

        return $this->createCustomerCurrencyAccount($customer, $customerCurrency ?? 'EGP');
    }

    /**
     * Create a new customer AR ledger account in the requested currency.
     */
    protected function createCustomerCurrencyAccount(Customer $customer, string $currency): Account
    {
        return LedgerBalanceMutationGuard::run(fn () => DB::transaction(function () use ($customer, $currency) {
            $account = Account::create([
                'name' => 'حساب العميل: '.$customer->full_name.' ('.strtoupper($currency).')',
                'type' => AccountType::Customer,
                'balance' => 0,
                'currency' => strtoupper($currency),
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'module_type' => 'bus',
                'is_module_vault' => false,
                'notes' => 'حساب تلقائي للعميل #'.$customer->id.' بعملة '.strtoupper($currency),
                'created_by' => Auth::id() ?? 1,
            ]);

            // Always link the *primary* AR slot to the new account so future
            // bus-module queries find it.  If the customer already had an
            // EGP account, that account is preserved as a 2nd ledger (no
            // funds are touched) but the new foreign-currency account is the
            // primary going forward.
            $customer->update(['account_id' => $account->id]);

            Log::info('Customer ledger account created automatically (Bus module)', [
                'customer_id' => $customer->id,
                'account_id' => $account->id,
                'currency' => strtoupper($currency),
            ]);

            return $account;
        }));
    }

    /**
     * Record the sale as a debt on the customer ledger.
     *
     * Multi-currency contract:
     *   - For non-EGP bookings: the customer AR account is in booking currency;
     *     the income-clearing account is always in EGP. We post a
     *     cross-currency journal transfer (clearing → customer) with
     *     `converted_amount` describing the EGP debit on the clearing side
     *     and the foreign-currency credit on the customer side.
     *   - For EGP bookings: single-currency posting (no FX required).
     */
    protected function recordSaleToCustomer(BusBooking $booking, int $customerId, float $sellingPrice, int $userId): void
    {
        // Pass the booking currency so the AR account is created/opened in the
        // right currency for cross-currency bookings.
        $customerAccount = $this->ensureCustomerAccount(
            $customerId,
            (string) ($booking->currency ?? 'EGP')
        );
        $clearingAccountId = $this->ledgerClearingAccounts->incomeContraIdForModule(TransactionModule::Bus->value);

        if ($clearingAccountId === null) {
            Log::warning('No bus clearing account configured for income. Skipping sale journal.');

            return;
        }

        if ($clearingAccountId === $customerAccount->id) {
            return;
        }

        $booking->loadMissing(['customer', 'inventory']);
        $saleNotes = app(LedgerEntryDescriptionResolver::class)->forBusBooking($booking);

        $journalArgs = [
            'amount' => $sellingPrice,
            'from_account_id' => $clearingAccountId,
            'to_account_id' => $customerAccount->id,
            'allow_from_negative' => true,
            'module' => TransactionModule::Bus->value,
            'related_type' => BusBooking::class,
            'related_id' => $booking->id,
            'notes' => $saleNotes,
            'created_by' => $userId,
        ];

        $bookingCurrency = strtoupper((string) ($booking->currency ?? 'EGP'));
        if ($bookingCurrency !== 'EGP') {
            // Cross-currency posting convention (see TransactionService::recordJournalTransfer):
            //   amount           → debit on `from_account` (in from_account currency = EGP clearing)
            //   converted_amount → credit on `to_account` (in to_account currency = USD/SAR/... customer)
            // We therefore:
            //   1. Compute the EGP-equivalent of the foreign-currency sale.
            //   2. Set `amount`           = the EGP-equivalent (clearing-side debit).
            //   3. Set `converted_amount` = the original foreign-currency amount (customer-side credit).
            $converted = $this->convertAmount($sellingPrice, $bookingCurrency, 'EGP');
            $journalArgs['amount'] = round((float) $converted['to_amount'], 2); // EGP
            $journalArgs['converted_amount'] = round($sellingPrice, 2); // foreign (USD/SAR/...)
            $journalArgs['exchange_rate'] = $converted['rate'];
            $journalArgs['allow_from_negative'] = true;
        }

        $tx = $this->transactionService->recordJournalTransfer($journalArgs);

        Log::info('Bus sale recorded on customer ledger', [
            'booking_id' => $booking->id,
            'customer_id' => $customerId,
            'account_id' => $customerAccount->id,
            'amount' => $sellingPrice,
            'currency' => $bookingCurrency,
        ]);
    }
}
