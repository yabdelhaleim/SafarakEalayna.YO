<?php

namespace Tests\Feature\TourismEmployeeE2E;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Program;
use App\Models\User;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\FlightSystemRechargeService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use App\Support\UserPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase as BaseTestCase;

/**
 * Base TestCase for the Tourism Employee E2E Audit.
 *
 * Phase: 2026-08-17 — Tourism Employee E2E
 *
 * Auditing the EMPLOYEE role surface:
 *  - Normal Tourism employee (default permissions)
 *  - Restricted employee (subset, e.g. flights only)
 *  - Locked-down employee (no Tourism permissions)
 *  - Inactive employee (should be rejected by middleware)
 *
 * Prefix convention: EMP_AUDIT_20260817_*
 *
 * Scope:
 *  - Flight, Hajj/Umrah, Visa only — Office (bus/fawry/online/wallet) is NOT in scope.
 *  - Tests are API-only (Sanctum). Frontend permission surface is checked
 *    by a dedicated meta-permission audit at the end.
 *  - SQLite :memory: isolation. Production DB untouched.
 *
 * Pre-flight environment:
 *  - APP_ENV=local (production never touches this test)
 *  - DB_CONNECTION=sqlite, DB_DATABASE=:memory:
 *  - All data created in setUp() is wiped after each test (RefreshDatabase)
 */
abstract class EmployeeTestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Audit prefix used for all created entities (users, customers, accounts). */
    public const AUDIT_PREFIX = 'EMP_AUDIT_20260817_';

    protected User $admin;
    protected User $normalEmployee;
    protected User $restrictedEmployee;
    protected User $lockedEmployee;
    protected User $inactiveEmployee;
    protected User $otherEmployee;

    protected Account $vaultEgp;
    protected Account $vaultUsd;
    protected Account $bankEgp;

    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // 1) Admin (privileged) — the control subject
        $this->admin = $this->makeUser('admin');

        // 2) Normal Tourism Employee — default module set
        $this->normalEmployee = $this->makeUser('employee', [
            'permissions' => UserPermissions::defaultEmployeeModules(),
        ]);

        // 3) Restricted Employee — only flights
        $this->restrictedEmployee = $this->makeUser('employee', [
            'permissions' => [UserPermissions::MANAGE_FLIGHTS],
        ]);

        // 4) Locked-down Employee — no Tourism permissions
        $this->lockedEmployee = $this->makeUser('employee', [
            'permissions' => [],
        ]);

        // 5) Inactive Employee — rejected by EnsureIsActive middleware
        $this->inactiveEmployee = $this->makeUser('employee', [
            'is_active' => false,
            'permissions' => UserPermissions::defaultEmployeeModules(),
        ]);

        // 6) Other Employee — same role, different person; used for IDOR tests
        $this->otherEmployee = $this->makeUser('employee', [
            'permissions' => UserPermissions::defaultEmployeeModules(),
        ]);

        // Default auth: admin acts to seed data
        Sanctum::actingAs($this->admin, ['*']);

        // Tourism vaults
        LedgerBalanceMutationGuard::run(function () {
            $this->vaultEgp = Account::query()->create([
                'name' => 'EMP_AUDIT_20260817_VAULT_EGP',
                'type' => AccountType::Cashbox->value,
                'currency' => 'EGP',
                'balance' => 500_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'flights',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);

            $this->vaultUsd = Account::query()->create([
                'name' => 'EMP_AUDIT_20260817_VAULT_USD',
                'type' => AccountType::Cashbox->value,
                'currency' => 'USD',
                'balance' => 50_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'flights',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);

            $this->bankEgp = Account::query()->create([
                'name' => 'EMP_AUDIT_20260817_BANK_EGP',
                'type' => AccountType::Bank->value,
                'currency' => 'EGP',
                'balance' => 250_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'flights',
                'is_module_vault' => false,
                'created_by' => $this->admin->id,
            ]);
        });

        // Tourism customer
        $this->customer = Customer::query()->create([
            'full_name' => 'EMP_AUDIT_20260817_Customer_A',
            'name' => 'EMP_AUDIT_20260817_Customer_A',
            'phone' => '01000000001',
            'nationality' => 'EG',
            'gender' => 'male',
            'status' => 'active',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);
    }

    /* ============================================================
     *  User factories
     * ============================================================ */

    protected function makeUser(string $role, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'name' => 'EMP_AUDIT_20260817_'.ucfirst($role).'_'.uniqid(),
            'email' => 'EMP_AUDIT_20260817_'.strtolower($role).'_'.uniqid('', true).'@audit.local',
            'password' => Hash::make('audit-password'),
            'role' => $role,
            'is_active' => true,
            'permissions' => [],
        ], $overrides));
    }

    protected function actAs(User $user): self
    {
        Sanctum::actingAs($user, ['*']);

        return $this;
    }

    /* ============================================================
     *  Tourism infrastructure helpers
     * ============================================================ */

    /**
     * Recharge the flight system with sufficient balance for booking tests.
     */
    protected function rechargeFlightSystem(Account $system, float $amount = 200_000.0): void
    {
        app(FlightSystemRechargeService::class)
            ->rechargeFromAccount($system, $this->vaultEgp, $amount, 'Employee audit setup');
    }

    /**
     * Create a Hajj/Umra program ready for booking tests.
     */
    protected function createHajjProgram(array $overrides = []): Program
    {
        return Program::query()->create(array_merge([
            'program_name' => 'EMP_AUDIT_20260817_Program_'.uniqid(),
            'program_type' => 'hajj',
            'total_nights' => 14,
            'accommodation_type' => 'QUAD',
            'mecca_hotel_name' => 'EMP_AUDIT_20260817_Mekka_Hotel',
            'mecca_nights' => 7,
            'medina_hotel_name' => 'EMP_AUDIT_20260817_Medina_Hotel',
            'medina_nights' => 7,
            'airline' => 'Saudi Airlines',
            'executing_company' => 'EMP_AUDIT_20260817_Executing',
            'departure_point' => 'Cairo',
            'booking_status' => 'open',
            'is_active' => true,
            'departure_date' => now()->addDays(30)->toDateString(),
            'return_date' => now()->addDays(45)->toDateString(),
            'default_purchase_price' => 25000.0,
            'default_selling_price' => 30000.0,
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    /**
     * Create a Flight System + Carrier with recharged balance for booking tests.
     *
     * @return array{0: FlightSystem, 1: FlightCarrier}
     */
    protected function createFlightInfra(float $rechargeAmount = 200_000.0): array
    {
        $system = FlightSystem::query()->create([
            'name' => 'EMP_AUDIT_20260817_System',
            'code' => 'EMPSYS'.random_int(10000, 99999),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        // System balance is optional; we use carrier-as-source for bookings
        $carrier = FlightCarrier::query()->create([
            'name' => 'EMP_AUDIT_20260817_Airline',
            'code' => 'EMPAIR'.random_int(100, 999),
            'flight_system_id' => $system->id,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 50_000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        // Recharge carrier via proper flow (treasury → prepaid GL → carrier)
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier,
            $this->vaultEgp,
            $rechargeAmount,
            'Employee audit setup'
        );

        $system->refresh();
        $carrier->refresh();

        return [$system, $carrier];
    }

    /**
     * Build a Flight booking payload ready for POST /api/v1/flight/bookings.
     *
     * @return array<string, mixed>
     */
    protected function flightBookingPayload(FlightCarrier $carrier, array $overrides = []): array
    {
        return array_merge([
            'customer_id' => $this->customer->id,
            'airline_name' => 'EMP_AUDIT_20260817_Air',
            'from_airport' => 'CAI',
            'to_airport' => 'DXB',
            'departure_date' => now()->addDays(7)->toDateString(),
            'departure_time' => '10:00',
            'arrival_time' => '14:00',
            'trip_type' => 'one_way',
            'currency' => 'EGP',
            'selling_price' => 6000.0,
            'purchase_price' => 5000.0,
            'flight_carrier_id' => $carrier->id,
            'account_id' => $this->vaultEgp->id,
            'pnr' => 'EMP'.random_int(100000, 999999),
            'passengers' => [
                ['first_name' => 'EMP', 'last_name' => 'AUDIT', 'type' => 'adult'],
            ],
        ], $overrides);
    }
}