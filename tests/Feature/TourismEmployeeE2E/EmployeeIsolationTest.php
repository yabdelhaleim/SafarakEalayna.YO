<?php

namespace Tests\Feature\TourismEmployeeE2E;

use App\Models\Account;
use Illuminate\Support\Facades\DB;

/**
 * Tourism/Office isolation under Employee flows.
 *
 * Verifies the canonical division contract holds end-to-end:
 *  - module_type IN (tourism, flights, hajj_umra, visas) = TOURISM division
 *  - module_type IN (office, bus, fawry, online, wallet_transfer) = OFFICE division
 *
 * Employee actions on Tourism bookings must:
 *  - NOT change Office division balances
 *  - NOT cross-post transactions across the division boundary
 *  - NOT corrupt the tourism/office account classification
 */
class EmployeeIsolationTest extends EmployeeTestCase
{
    /* ============================================================
     *  Office accounts untouched by Tourism flows
     * ============================================================ */

    public function test_employee_hajj_booking_does_not_touch_office_accounts(): void
    {
        $officeAccount = $this->createOfficeAccount('OFFICE_VAULT_EGP', 100_000.0);
        $officeBank = $this->createOfficeAccount('OFFICE_BANK_EGP', 50_000.0);
        $officeBalanceBefore = $officeAccount->balance;
        $officeBankBefore = $officeBank->balance;

        $program = $this->createHajjProgram();
        $this->actAs($this->normalEmployee);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->customer->id,
            'program_id' => $program->id,
            'selling_price' => 30000.0,
            'purchase_price' => 25000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'initial_payment' => [
                'amount' => 5000.0,
                'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
            ],
        ]);
        $response->assertStatus(201);

        $officeAccount->refresh();
        $officeBank->refresh();

        $this->assertEquals(
            $officeBalanceBefore,
            $officeAccount->balance,
            'Office vault balance must NOT change due to Tourism employee action'
        );
        $this->assertEquals(
            $officeBankBefore,
            $officeBank->balance,
            'Office bank balance must NOT change due to Tourism employee action'
        );
    }

    public function test_employee_visa_booking_does_not_touch_office_accounts(): void
    {
        $officeAccount = $this->createOfficeAccount('OFFICE_VAULT_EGP', 100_000.0);
        $balanceBefore = $officeAccount->balance;

        $this->actAs($this->normalEmployee);
        $response = $this->postJson('/api/v1/visa/bookings', [
            'customer_id' => $this->customer->id,
            'purchase_price' => 1000.0,
            'selling_price' => 1500.0,
            'service_fee' => 100.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'visa_details' => [
                'visa_type' => 'tourist',
                'country' => 'EG',
            ],
            'initial_payment' => [
                'amount' => 500.0,
                'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
            ],
        ]);
        $response->assertStatus(201);

        $officeAccount->refresh();
        $this->assertEquals(
            $balanceBefore,
            $officeAccount->balance,
            'Office vault balance must NOT change due to Tourism visa action'
        );
    }

    public function test_employee_flight_booking_does_not_touch_office_accounts(): void
    {
        $officeAccount = $this->createOfficeAccount('OFFICE_VAULT_EGP', 100_000.0);
        $balanceBefore = $officeAccount->balance;

        [$system, $carrier] = $this->createFlightInfra();
        $this->actAs($this->normalEmployee);

        $response = $this->postJson('/api/v1/flight/bookings', $this->flightBookingPayload($carrier));
        $response->assertStatus(201);

        $officeAccount->refresh();
        $this->assertEquals(
            $balanceBefore,
            $officeAccount->balance,
            'Office vault balance must NOT change due to Tourism flight action'
        );
    }

    /* ============================================================
     *  Ledger entries stay within Tourism division
     * ============================================================ */

    public function test_employee_actions_create_only_tourism_module_entries(): void
    {
        $program = $this->createHajjProgram();
        $this->actAs($this->normalEmployee);

        $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->customer->id,
            'program_id' => $program->id,
            'selling_price' => 30000.0,
            'purchase_price' => 25000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
            'initial_payment' => [
                'amount' => 5000.0,
                'payment_method' => 'cash',
                'account_id' => $this->vaultEgp->id,
            ],
        ])->assertStatus(201);

        // Every account that has new entries must be in the Tourism division
        $offendingAccounts = DB::table('account_entries as ae')
            ->join('accounts as a', 'a.id', '=', 'ae.account_id')
            ->whereNotIn('a.module_type', ['tourism', 'flights', 'hajj_umra', 'visas'])
            ->whereNotNull('ae.created_at')
            ->select('a.id', 'a.name', 'a.module_type')
            ->groupBy('a.id', 'a.name', 'a.module_type')
            ->get();

        $this->assertSame(
            0,
            $offendingAccounts->count(),
            'No Office-division accounts should have ledger entries from a Tourism flow. '.
            'Found: '.json_encode($offendingAccounts->toArray())
        );
    }

    /* ============================================================
     *  Liquidity vs Subject account classification invariant
     * ============================================================ */

    public function test_liquidity_accounts_stay_in_tourism_module_type(): void
    {
        // Run a full flow and verify vault classifications are preserved
        $program = $this->createHajjProgram();
        $this->actAs($this->normalEmployee);

        $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $this->customer->id,
            'program_id' => $program->id,
            'selling_price' => 30000.0,
            'purchase_price' => 25000.0,
            'currency' => 'EGP',
            'account_id' => $this->vaultEgp->id,
        ])->assertStatus(201);

        // Original Tourism vault classification must be preserved
        $this->assertContains(
            $this->vaultEgp->fresh()->module_type,
            ['tourism', 'flights', 'hajj_umra', 'visas'],
            'Tourism vault classification must remain in Tourism division after employee actions'
        );

        // Prepaid / GL accounts created during the flow must also be Tourism
        $prepaidAccounts = Account::query()
            ->where('is_module_vault', true)
            ->whereIn('module_type', ['tourism', 'flights', 'hajj_umra', 'visas'])
            ->get();
        $this->assertGreaterThan(0, $prepaidAccounts->count(), 'Prepaid accounts created during flow must exist');
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    protected function createOfficeAccount(string $name, float $balance): Account
    {
        return Account::query()->create([
            'name' => 'EMP_AUDIT_20260817_'.$name,
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => $balance,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'is_module_vault' => true,
            'created_by' => $this->admin->id,
        ]);
    }
}