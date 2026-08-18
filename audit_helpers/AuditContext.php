<?php

namespace AuditHelpers;

use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightGroup;
use App\Models\Flight\FlightSystem;
use App\Models\HajjUmraBooking;
use App\Models\VisaBooking;
use App\Models\User;
use App\Models\Employee;
use App\Models\Currency;
use App\Models\Program;
use App\Models\Account;
use App\Models\HajjUmra\Hotel;
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmra\VisaDuration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Builds isolated test entities for the audit run. Every created entity is
 * tagged with the audit prefix `TOURISM_FULL_AUDIT_20260818_` so cleanup can
 * identify and remove ONLY audit-created rows — never touching real data.
 */
class AuditContext
{
    public string $prefix = 'TOURISM_FULL_AUDIT_20260818_';

    /** @var int[] Booking IDs created during the audit */
    public array $flightBookingIds = [];
    public array $hajjUmraBookingIds = [];
    public array $visaBookingIds = [];

    /** @var int[] Customer IDs */
    public array $customerIds = [];

    /** @var int[] User IDs (employees) */
    public array $userIds = [];

    /** @var int[] Account IDs touched */
    public array $touchedAccountIds = [];

    /** @var int[] Flight carrier/group/system IDs */
    public array $carrierIds = [];
    public array $groupIds = [];
    public array $systemIds = [];

    /** @var int[] Transaction IDs created */
    public array $transactionIds = [];

    /** @var int[] RefundRequest IDs */
    public array $refundRequestIds = [];

    /** @var string[] Modules that fell back to non-canonical cashbox */
    public array $cashboxFallbackNotes = [];

    /** Current actor */
    public ?User $currentUser = null;
    public ?string $currentRole = null;

    public function __construct() {}

    public function setPrefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    // ── Actor (auth) helpers ──────────────────────────────────────────────

    public function actAsAdmin(): self
    {
        $user = $this->ensureUser('admin', ['*']);
        Auth::login($user);
        $this->currentUser = $user;
        $this->currentRole = 'admin';
        return $this;
    }

    public function actAsEmployee(): self
    {
        $user = $this->ensureUser('employee', [
            'manage_flights',
            'manage_hajj',
            'manage_online',  // visa uses same group
            'manage_refunds',
        ]);
        Auth::login($user);
        $this->currentUser = $user;
        $this->currentRole = 'employee';
        return $this;
    }

    public function actAsRestrictedEmployee(): self
    {
        $user = $this->ensureUser('employee_restricted', [
            'manage_flights',
            // no manage_hajj, no manage_online, no manage_refunds
        ]);
        Auth::login($user);
        $this->currentUser = $user;
        $this->currentRole = 'restricted_employee';
        return $this;
    }

    public function actAsAnonymous(): self
    {
        Auth::logout();
        $this->currentUser = null;
        $this->currentRole = 'anonymous';
        return $this;
    }

    protected function ensureUser(string $roleName, array $permissions): User
    {
        $email = $this->prefix . $roleName . '_' . substr(Str::uuid(), 0, 8) . '@audit.local';
        $existing = User::where('email', $email)->first();
        if ($existing) {
            $this->userIds[] = $existing->id;
            return $existing;
        }

        // NOTE: We do NOT create an Employee record here.
        // AUDIT FINDING: Employee model declares `email` in Fillable but the
        // local MySQL `employees` table does NOT have an `email` column.
        // This is a model-DB mismatch — the Employee model is not safe to
        // instantiate from a fresh audit. We therefore skip Employee creation
        // and create only a User row. `employee_id` does not exist on the
        // `users` table either, so we cannot link them.

        $user = User::create([
            'name'        => $this->prefix . $roleName,
            'email'       => $email,
            'password'    => Hash::make('audit_test_only'),
            'role'        => str_contains($roleName, 'admin') ? 'admin' : 'employee',
            'is_active'   => true,
            'permissions' => json_encode($permissions),
        ]);

        $this->userIds[] = $user->id;
        return $user;
    }

    // ── Customer helpers ──────────────────────────────────────────────────

    public function createCustomer(string $tag = 'CUST'): Customer
    {
        $unique = Str::uuid()->toString();
        $customer = Customer::create([
            'full_name' => "{$this->prefix}{$tag}_{$unique}",
            'phone'     => '09' . random_int(10000000, 99999999),
            'email'     => $this->prefix . Str::slug($tag) . '_' . substr($unique, 0, 8) . '@audit.local',
            'is_active' => true,
            'module_type' => 'flights',  // will be updated per booking creation
        ]);
        $this->customerIds[] = $customer->id;
        return $customer;
    }

    // ── Flight booking factory ────────────────────────────────────────────

    /**
     * Creates a Flight booking with realistic pricing. Returns the persisted model.
     * @param array $overrides selling_price, purchase_price, currency, status, etc.
     */
    public function createFlightBooking(array $overrides = []): FlightBooking
    {
        $customer = $overrides['customer'] ?? $this->createCustomer('FLIGHT');
        // AUDIT NOTE: `users` table does not have an `employee_id` column on
        // this local MySQL instance, and `employees.email` column is missing
        // despite the model declaring it. We therefore pass employee_id=1
        // (any valid FK value) without instantiating Employee.
        $employeeId = $overrides['employee_id'] ?? 1;

        $defaultData = [
            'booking_number'        => $this->prefix . 'FL' . substr(Str::uuid(), 0, 10),
            'customer_id'           => $customer->id,
            'employee_id'           => $employeeId,
            'currency'              => 'EGP',
            'selling_price'         => 1000.00,
            'purchase_price'        => 800.00,
            'purchase_price_egp'    => 800.00,
            'selling_price_foreign' => 1000.00,
            'purchase_price_foreign'=> 800.00,
            'exchange_rate'         => 1.0,
            'status'                => 'pending',
            'trip_type'             => 'one_way',
            'passenger_count'       => 1,
            'notes'                 => $this->prefix . ' flight booking test',
        ];

        $data = array_merge($defaultData, $overrides);
        $service = app(\App\Services\Flight\FlightBookingService::class);
        $booking = $service->createBooking($data);

        $this->flightBookingIds[] = $booking->id;
        return $booking;
    }

    // ── HajjUmra booking factory ──────────────────────────────────────────

    public function createHajjUmraBooking(array $overrides = []): HajjUmraBooking
    {
        $customer = $overrides['customer'] ?? $this->createCustomer('HAJJ');
        $employeeId = $overrides['employee_id'] ?? 1;

        // Need a Program (FK required by migration).
        // AUDIT NOTE: local `programs` table uses `program_name` (no `code`)
        // and requires multiple NOT NULL fields with no defaults.
        $program = Program::firstOrCreate(
            ['program_name' => $this->prefix . 'PROG'],
            [
                'program_type'    => 'hajj',
                'season'          => '1447H',
                'total_nights'    => 14,
                'is_active'       => true,
                'booking_status'  => 'open',
                'default_selling_price' => 50000.00,
                'default_purchase_price' => 40000.00,
                'mecca_hotel_name' => $this->prefix . 'MECCA',
                'medina_hotel_name' => $this->prefix . 'MEDINA',
                'mecca_nights'    => 7,
                'medina_nights'   => 7,
                'departure_date'  => '2026-09-01',
                'return_date'     => '2026-09-15',
                'airline'         => $this->prefix . 'AIRLINE',
                'departure_point' => $this->prefix . 'CAIRO',
            ]
        );

        $defaultData = [
            'booking_number'        => $this->prefix . 'HJ' . substr(Str::uuid(), 0, 10),
            'customer_id'           => $customer->id,
            'employee_id'           => $employeeId,
            'program_id'            => $program->id,
            'currency'              => 'EGP',
            'selling_price'         => 5000.00,
            'purchase_price'        => 4000.00,
            'total_amount'          => 5000.00,
            'paid_amount'           => 0,
            'status'                => 'pending',
            'notes'                 => $this->prefix . ' hajj booking test',
        ];

        $data = array_merge($defaultData, $overrides);
        $service = app(\App\Services\HajjUmra\HajjUmraBookingService::class);
        $booking = $service->create($data);

        $this->hajjUmraBookingIds[] = $booking->id;
        return $booking;
    }

    // ── Visa booking factory ──────────────────────────────────────────────

    public function createVisaBooking(array $overrides = []): VisaBooking
    {
        $customer = $overrides['customer'] ?? $this->createCustomer('VISA');
        $employeeId = $overrides['employee_id'] ?? 1;

        $duration = VisaDuration::firstOrCreate(
            ['code' => $this->prefix . 'DUR'],
            ['name' => $this->prefix . ' 30 Days', 'days' => 30, 'is_active' => true]
        );

        $defaultData = [
            'booking_number'        => $this->prefix . 'VS' . substr(Str::uuid(), 0, 10),
            'customer_id'           => $customer->id,
            'employee_id'           => $employeeId,
            'visa_duration_id'      => $duration->id,
            'currency'              => 'EGP',
            'selling_price'         => 1000.00,
            'purchase_price'        => 700.00,
            'service_fee'           => 100.00,
            'total_amount'          => 1100.00,
            'paid_amount'           => 0,
            'status'                => 'pending',
            'notes'                 => $this->prefix . ' visa booking test',
        ];

        $data = array_merge($defaultData, $overrides);
        $service = app(\App\Services\Visa\VisaBookingService::class);
        $booking = $service->create($data);

        $this->visaBookingIds[] = $booking->id;
        return $booking;
    }

    // ── Snapshots ────────────────────────────────────────────────────────

    /** Snapshot balances for all touched accounts */
    public function snapshotAccountBalances(?array $accountIds = null): array
    {
        $ids = $accountIds ?? $this->touchedAccountIds;
        if (empty($ids)) return [];
        $rows = DB::table('accounts')
            ->whereIn('id', $ids)
            ->select(['id', 'name', 'balance', 'currency'])
            ->get();
        return $rows->mapWithKeys(fn ($r) => [(int) $r->id => (float) $r->balance])->toArray();
    }

    /** Snapshot transactions linked to a related record */
    public function snapshotTransactions(string $relatedType, int $relatedId): array
    {
        return DB::table('transactions')
            ->where('related_type', $relatedType)
            ->where('related_id', $relatedId)
            ->select(['id', 'type', 'amount', 'from_account_id', 'to_account_id', 'currency', 'module'])
            ->get()->map(fn ($r) => (array) $r)->toArray();
    }

    /** Get all currently affected account IDs from transactions linked to audit bookings */
    public function allAuditAccountIds(): array
    {
        $relatedIds = array_merge(
            $this->flightBookingIds,
            $this->hajjUmraBookingIds,
            $this->visaBookingIds,
        );
        if (empty($relatedIds)) return $this->touchedAccountIds;

        $typeMap = [
            \App\Models\Flight\FlightBooking::class,
            \App\Models\HajjUmraBooking::class,
            \App\Models\VisaBooking::class,
            \App\Models\Flight\FlightRefund::class,
            \App\Models\RefundRequest::class,
            \App\Models\Flight\FlightPayment::class,
            \App\Models\HajjUmraPayment::class,
            \App\Models\VisaPayment::class,
        ];

        $accountIds = DB::table('account_entries as ae')
            ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
            ->whereIn('t.related_type', $typeMap)
            ->whereIn('t.related_id', $relatedIds)
            ->pluck('ae.account_id')
            ->unique()
            ->toArray();

        $this->touchedAccountIds = array_values(array_unique(array_merge($this->touchedAccountIds, $accountIds)));
        return $this->touchedAccountIds;
    }

    // ── Cleanup ───────────────────────────────────────────────────────────

    /**
     * Delete every audit-prefixed entity. Order matters:
     *   1. Audit logs (refund + general)
     *   2. Refund requests
     *   3. Account entries + transactions (via related_id cascade via app code)
     *   4. Payments
     *   5. Bookings (soft-delete-aware)
     *   6. Customers
     *   7. Users + Employees
     *   8. Reference data (programs, durations) — only if not used elsewhere
     */
    public function cleanup(): array
    {
        $deleted = ['refund_audit_logs' => 0, 'audit_logs' => 0, 'transactions' => 0,
                    'account_entries' => 0, 'flight_payments' => 0, 'hajj_payments' => 0,
                    'visa_payments' => 0, 'refund_requests' => 0, 'flight_bookings' => 0,
                    'hajj_bookings' => 0, 'visa_bookings' => 0, 'customers' => 0,
                    'users' => 0, 'employees' => 0, 'programs' => 0, 'durations' => 0];

        // 1. RefundAuditLogs where booking_reference / customer_name begins with prefix
        $deleted['refund_audit_logs'] = DB::table('refund_audit_logs')
            ->where(function ($q) {
                $q->where('user_name', 'like', $this->prefix . '%')
                  ->orWhere('booking_reference', 'like', $this->prefix . '%')
                  ->orWhere('customer_name', 'like', $this->prefix . '%');
            })
            ->delete();

        // 2. AuditLogs (notes + user_id prefix match)
        // AUDIT NOTE: local audit_logs schema lacks actor_name/description columns.
        // We filter by user_id (the audit's own User rows) and by notes containing
        // the audit prefix. Anything written by the audit will match one of these.
        $auditUserIds = !empty($this->userIds) ? $this->userIds : [0];
        $deleted['audit_logs'] = DB::table('audit_logs')
            ->where(function ($q) use ($auditUserIds) {
                $q->whereIn('user_id', $auditUserIds)
                  ->orWhere('notes', 'like', $this->prefix . '%');
            })
            ->delete();

        // 3. Refund requests
        if (!empty($this->flightBookingIds)) {
            $deleted['refund_requests'] += DB::table('refund_requests')
                ->whereIn('flight_booking_id', $this->flightBookingIds)
                ->delete();
        }

        // 4. Transactions + AccountEntries (via related_id IN audit ids, all related_types)
        $relatedIds = array_merge(
            $this->flightBookingIds,
            $this->hajjUmraBookingIds,
            $this->visaBookingIds,
        );
        $relatedTypes = [
            \App\Models\Flight\FlightBooking::class,
            \App\Models\HajjUmraBooking::class,
            \App\Models\VisaBooking::class,
            \App\Models\Flight\FlightRefund::class,
            \App\Models\RefundRequest::class,
            \App\Models\Flight\FlightPayment::class,
            \App\Models\HajjUmraPayment::class,
            \App\Models\VisaPayment::class,
        ];
        if (!empty($relatedIds)) {
            $txIds = DB::table('transactions')
                ->whereIn('related_type', $relatedTypes)
                ->whereIn('related_id', $relatedIds)
                ->pluck('id')
                ->toArray();

            if (!empty($txIds)) {
                $deleted['account_entries'] = DB::table('account_entries')
                    ->whereIn('transaction_id', $txIds)
                    ->delete();
                $deleted['transactions'] = DB::table('transactions')
                    ->whereIn('id', $txIds)
                    ->delete();
            }
        }

        // 5. Payments
        if (!empty($this->flightBookingIds)) {
            $deleted['flight_payments'] = DB::table('flight_payments')
                ->whereIn('flight_booking_id', $this->flightBookingIds)
                ->delete();
        }
        if (!empty($this->hajjUmraBookingIds)) {
            $deleted['hajj_payments'] = DB::table('hajj_umra_payments')
                ->whereIn('hajj_umra_booking_id', $this->hajjUmraBookingIds)
                ->delete();
        }
        if (!empty($this->visaBookingIds)) {
            $deleted['visa_payments'] = DB::table('visa_payments')
                ->whereIn('visa_booking_id', $this->visaBookingIds)
                ->delete();
        }

        // 6. Bookings — force-delete (bypass soft-delete for audit cleanup)
        if (!empty($this->flightBookingIds)) {
            $deleted['flight_bookings'] = DB::table('flight_bookings')
                ->whereIn('id', $this->flightBookingIds)
                ->delete();
        }
        if (!empty($this->hajjUmraBookingIds)) {
            $deleted['hajj_bookings'] = DB::table('hajj_umra_bookings')
                ->whereIn('id', $this->hajjUmraBookingIds)
                ->delete();
        }
        if (!empty($this->visaBookingIds)) {
            $deleted['visa_bookings'] = DB::table('visa_bookings')
                ->whereIn('id', $this->visaBookingIds)
                ->delete();
        }

        // 7. Customers
        if (!empty($this->customerIds)) {
            $deleted['customers'] = DB::table('customers')
                ->whereIn('id', $this->customerIds)
                ->delete();
        }

        // 8. Users + Employees (only audit-created ones)
        // AUDIT NOTE: Multiple FK chains reference users(accounts.created_by,
        // customers.created_by, etc). We NULL those references first so the
        // user rows can be deleted without violating FK constraints.
        if (!empty($this->userIds)) {
            $users = User::whereIn('id', $this->userIds)->get();
            $empIds = $users->pluck('employee_id')->filter()->toArray();

            // 8a. NULL out accounts.created_by referencing audit users
            if (\Illuminate\Support\Facades\Schema::hasColumn('accounts', 'created_by')) {
                DB::table('accounts')
                    ->whereIn('created_by', $this->userIds)
                    ->update(['created_by' => null]);
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('customers', 'created_by')) {
                DB::table('customers')
                    ->whereIn('created_by', $this->userIds)
                    ->update(['created_by' => null]);
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('flight_bookings', 'created_by')) {
                DB::table('flight_bookings')
                    ->whereIn('created_by', $this->userIds)
                    ->update(['created_by' => null]);
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('hajj_umra_bookings', 'created_by')) {
                DB::table('hajj_umra_bookings')
                    ->whereIn('created_by', $this->userIds)
                    ->update(['created_by' => null]);
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('visa_bookings', 'created_by')) {
                DB::table('visa_bookings')
                    ->whereIn('created_by', $this->userIds)
                    ->update(['created_by' => null]);
            }

            $deleted['users'] = DB::table('users')
                ->whereIn('id', $this->userIds)
                ->delete();
            if (!empty($empIds)) {
                $deleted['employees'] = DB::table('employees')
                    ->whereIn('id', $empIds)
                    ->delete();
            }
        }

        // 9. Reference data
        // AUDIT NOTE: local `programs` table uses `program_name` (not `code`).
        // We filter by name LIKE prefix.
        if (\Illuminate\Support\Facades\Schema::hasColumn('programs', 'program_name')) {
            $deleted['programs'] = DB::table('programs')
                ->where('program_name', 'like', $this->prefix . '%')
                ->delete();
        } else {
            $deleted['programs'] = 0;
        }
        $deleted['durations'] = DB::table('visa_durations')
            ->where('code', 'like', $this->prefix . '%')
            ->delete();

        return $deleted;
    }

    public function trackAccount(int $accountId): void
    {
        if (!in_array($accountId, $this->touchedAccountIds, true)) {
            $this->touchedAccountIds[] = $accountId;
        }
    }

    public function trackTransaction(int $txId): void
    {
        $this->transactionIds[] = $txId;
    }

    public function trackRefundRequest(int $refundRequestId): void
    {
        $this->refundRequestIds[] = $refundRequestId;
    }

    /**
     * Resolve an active EGP cashbox for a Tourism module.
     *
     * CONTRACT (per AccountModuleContract):
     *   The canonical cashbox for a Tourism module has
     *     type='cashbox', module_type='tourism', is_module_vault=true,
     *     is_active=true.
     *
     * ACTUAL DB STATE (audit finding):
     *   No accounts match this contract — there are 0 tourism-division
     *   cashbox vaults in the local MySQL. The audit must NOT modify the
     *   environment, so we use the existing office cashbox WL_CASH_EGP
     *   (id=162) as a fallback. This is documented as a finding so the
     *   reader knows the audit ran against office cashboxes, not the
     *   missing tourism vaults.
     *
     * @param  string  $module     'flights' | 'hajj_umra' | 'visas'
     * @param  string  $currency   'EGP' | 'USD' | 'SAR'
     * @return \App\Models\Account|null  Returns null if no fallback exists.
     */
    public function resolveCashbox(string $module = 'flights', string $currency = 'EGP'): ?Account
    {
        // First: try the canonical tourism vault
        $canonical = Account::where('type', 'cashbox')
            ->where('module_type', 'tourism')
            ->where('is_module_vault', true)
            ->where('is_active', true)
            ->where('currency', $currency)
            ->first();
        if ($canonical) {
            $this->trackAccount($canonical->id);
            return $canonical;
        }

        // Fallback: existing office cashbox with matching currency
        $fallback = Account::where('type', 'cashbox')
            ->where('is_active', true)
            ->where('currency', $currency)
            ->where(function ($q) {
                $q->where('module_type', 'office')
                  ->orWhereNull('module_type');
            })
            ->orderByRaw('CASE WHEN module_type = ? THEN 0 ELSE 1 END', ['office'])
            ->first();

        if ($fallback) {
            $key = "{$module}:{$currency}";
            if (!in_array($key, $this->cashboxFallbackNotes, true)) {
                $this->cashboxFallbackNotes[] = $key;
            }
            $this->trackAccount($fallback->id);
            return $fallback;
        }

        return null;
    }

    /**
     * Resolve an active wallet account for a Tourism module.
     * Same fallback strategy as resolveCashbox().
     */
    public function resolveWallet(string $module = 'flights', string $currency = 'EGP'): ?Account
    {
        $canonical = Account::where('type', 'wallet')
            ->where('module_type', 'tourism')
            ->where('is_active', true)
            ->where('currency', $currency)
            ->first();
        if ($canonical) {
            $this->trackAccount($canonical->id);
            return $canonical;
        }

        return Account::where('type', 'wallet')
            ->where('is_active', true)
            ->where('currency', $currency)
            ->orderByRaw('CASE WHEN module_type = ? THEN 0 ELSE 1 END', ['office'])
            ->first();
    }

    /**
     * Resolve an active bank account for a Tourism module.
     */
    public function resolveBank(string $module = 'flights', string $currency = 'EGP'): ?Account
    {
        $canonical = Account::where('type', 'bank')
            ->where('module_type', 'tourism')
            ->where('is_active', true)
            ->where('currency', $currency)
            ->first();
        if ($canonical) {
            $this->trackAccount($canonical->id);
            return $canonical;
        }

        return Account::where('type', 'bank')
            ->where('is_active', true)
            ->where('currency', $currency)
            ->orderByRaw('CASE WHEN module_type = ? THEN 0 ELSE 1 END', ['office'])
            ->first();
    }
}
