<?php

namespace Tests\Feature\Customer;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for module_type consistency in CustomerController::payDebt().
 *
 * Covers Bug #11 — payDebt() previously always hardcoded module_type='tourism'
 * when creating a missing customer AR account, regardless of the module
 * parameter. This meant bus-module customers created via this path were
 * invisible to queries filtering on module_type='bus', creating a split
 * between accounts created by BusBookingService::ensureCustomerAccount()
 * (module_type='bus') and those created by payDebt() (module_type='tourism').
 *
 * Fix: resolve module_type from the 'module' request parameter using the
 * same office/tourism division split as AccountModuleContract.
 */
class CustomerModuleTypeConsistencyTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Account $cashboxEgp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role'      => 'admin',
            'is_active' => true,
            'password'  => Hash::make('password'),
        ]);
        Sanctum::actingAs($this->user, ['*']);

        \App\Support\Finance\LedgerBalanceMutationGuard::run(function () {
            $this->cashboxEgp = Account::create([
                'name'            => 'Test Cashbox EGP',
                'type'            => AccountType::Cashbox,
                'currency'        => 'EGP',
                'balance'         => 10000.0,
                'is_active'       => true,
                'owner_type'      => Account::OWNER_TYPE_OFFICE,
                'module_type'     => 'office',
                'is_module_vault' => false,
                'created_by'      => $this->user->id,
            ]);
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 1 — module=bus → customer account gets module_type='bus'
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pay_debt_with_bus_module_creates_bus_account(): void
    {
        $customer = Customer::factory()->create(['phone' => '01003000001']);
        // Ensure no existing account
        $customer->update(['account_id' => null]);

        $response = $this->postJson("/api/v1/customers/{$customer->id}/pay-debt", [
            'amount'     => 100.0,
            'account_id' => $this->cashboxEgp->id,
            'type'       => 'receipt',
            'module'     => 'bus',
        ]);

        $response->assertStatus(200);

        $customer->refresh();
        $this->assertNotNull($customer->account_id,
            'Customer should have an account_id after payDebt');

        $account = Account::find($customer->account_id);
        $this->assertEquals('bus', $account->module_type,
            'Fix #11: bus module should produce module_type=bus');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 2 — module=flight → customer account gets module_type='flights' ( tourism division specific )
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pay_debt_with_flight_module_creates_flights_account(): void
    {
        $customer = Customer::factory()->create(['phone' => '01003000002']);
        $customer->update(['account_id' => null]);

        $response = $this->postJson("/api/v1/customers/{$customer->id}/pay-debt", [
            'amount'     => 100.0,
            'account_id' => $this->cashboxEgp->id,
            'type'       => 'receipt',
            'module'     => 'flight',
        ]);

        $response->assertStatus(200);

        $customer->refresh();
        $account = Account::find($customer->account_id);
        $this->assertEquals('flights', $account->module_type,
            'flight module should produce module_type=flights');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 3 — module=fawry → customer account gets module_type='fawry' ( office division specific )
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pay_debt_with_fawry_module_creates_fawry_account(): void
    {
        $customer = Customer::factory()->create(['phone' => '01003000003']);
        $customer->update(['account_id' => null]);

        $response = $this->postJson("/api/v1/customers/{$customer->id}/pay-debt", [
            'amount'     => 100.0,
            'account_id' => $this->cashboxEgp->id,
            'type'       => 'receipt',
            'module'     => 'fawry',
        ]);

        $response->assertStatus(200);

        $customer->refresh();
        $account = Account::find($customer->account_id);
        $this->assertEquals('fawry', $account->module_type,
            'fawry module should produce module_type=fawry');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 4 — No module (default) → customer account gets module_type='flights'
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pay_debt_without_module_defaults_to_flights(): void
    {
        $customer = Customer::factory()->create(['phone' => '01003000004']);
        $customer->update(['account_id' => null]);

        $response = $this->postJson("/api/v1/customers/{$customer->id}/pay-debt", [
            'amount'     => 100.0,
            'account_id' => $this->cashboxEgp->id,
            'type'       => 'receipt',
            // 'module' intentionally omitted — defaults to 'flight' -> 'flights'
        ]);

        $response->assertStatus(200);

        $customer->refresh();
        $account = Account::find($customer->account_id);
        $this->assertEquals('flights', $account->module_type,
            'Default (no module) should produce module_type=flights');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // 5 — Existing account is reused regardless of module
    // ─────────────────────────────────────────────────────────────────────────

    public function test_pay_debt_reuses_existing_account(): void
    {
        $customer = Customer::factory()->create(['phone' => '01003000005']);

        // Pre-create an existing account with a valid specific module
        \App\Support\Finance\LedgerBalanceMutationGuard::run(function () use ($customer) {
            $account = Account::create([
                'name'            => 'Existing Customer Account',
                'type'            => AccountType::Customer,
                'currency'        => 'EGP',
                'balance'         => 0,
                'is_active'       => true,
                'owner_type'      => Account::OWNER_TYPE_OWNER,
                'module_type'     => 'flights',
                'is_module_vault' => false,
                'created_by'      => $this->user->id,
            ]);
            $customer->update(['account_id' => $account->id]);
        });

        $existingAccountId = $customer->account_id;
        $accountCountBefore = Account::count();

        $response = $this->postJson("/api/v1/customers/{$customer->id}/pay-debt", [
            'amount'     => 50.0,
            'account_id' => $this->cashboxEgp->id,
            'type'       => 'receipt',
            'module'     => 'bus',
        ]);

        $response->assertStatus(200);

        // No new account created
        $this->assertEquals($accountCountBefore, Account::count(),
            'No new account should be created when one already exists');
        $customer->refresh();
        $this->assertEquals($existingAccountId, $customer->account_id,
            'Existing account_id must not change');
    }
}
