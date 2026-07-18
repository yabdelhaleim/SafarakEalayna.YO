<?php

namespace Tests\Feature\Bus;

use Tests\TestCase;

/**
 * Dashboard endpoint scenarios.
 *
 * Validates:
 *   - Dashboard returns the canonical 5-stat structure.
 *   - Office-division cashboxes/banks/wallets show up in the dashboard cards
 *     (Bug #B-02 fix: previously filtered to `module_type='bus'` only, which
 *     excluded any unified liquidity account).
 *   - Recent bookings list reflects the most recent N bookings.
 *   - total_company_debt reflects supplier account balances (negative => owed).
 */
class DashboardTest extends BusTestCase
{
    public function test_dashboard_endpoint_returns_canonical_structure(): void
    {
        $response = $this->getJson('/api/v1/bus/dashboard');
        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'stats' => [
                        'monthly_revenue',
                        'total_bookings',
                        'cashboxes' => ['count', 'balance'],
                        'banks' => ['count', 'balance'],
                        'wallets' => ['count', 'balance'],
                    ],
                    'recent_bookings',
                    'liquidity' => ['total'],
                    'total_company_debt',
                ],
            ]);
    }

    public function test_unified_office_cashboxes_appear_in_dashboard(): void
    {
        // Seed a booking so the dashboard has at least one booking to count.
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory(['company_id' => $company->id]);
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Dash Test',
            'customer_phone' => '01093333001',
            'quantity' => 1,
        ])->assertCreated();

        $response = $this->getJson('/api/v1/bus/dashboard');
        $response->assertOk();

        $stats = $response->json('data.stats');

        // The seeded cashbox/bank/wallet are office-division; they MUST
        // appear in the dashboard cards (Bug #B-02 fix).
        $this->assertGreaterThanOrEqual(1, $stats['cashboxes']['count']);
        $this->assertGreaterThanOrEqual(1, $stats['banks']['count']);
        $this->assertGreaterThanOrEqual(1, $stats['wallets']['count']);
    }

    public function test_total_company_debt_reflects_supplier_balance(): void
    {
        // One booking generates a -cost supplier debt (we owe the company).
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeInventory([
            'company_id' => $company->id,
            'cost_per_ticket' => 100,
            'selling_price' => 150,
            'total_tickets' => 5,
            'available_tickets' => 5,
        ]);
        $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'Debt Reflect',
            'customer_phone' => '01093333002',
            'quantity' => 2,
        ])->assertCreated();

        $response = $this->getJson('/api/v1/bus/dashboard');
        $response->assertOk();
        // 2 × 100 = 200 EGP debt.
        $this->assertEqualsWithDelta(
            200.0,
            $response->json('data.total_company_debt'),
            0.5
        );
    }

    public function test_liquidity_total_sums_all_three_account_categories(): void
    {
        $response = $this->getJson('/api/v1/bus/dashboard');
        $response->assertOk();
        $stats = $response->json('data.stats');

        $expectedTotal = (float) $stats['cashboxes']['balance']
            + (float) $stats['banks']['balance']
            + (float) $stats['wallets']['balance'];

        $this->assertEqualsWithDelta(
            $expectedTotal,
            $response->json('data.liquidity.total'),
            0.01
        );
    }
}
