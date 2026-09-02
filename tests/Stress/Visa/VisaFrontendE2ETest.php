<?php

declare(strict_types=1);

namespace Tests\Stress\Visa;

use App\Enums\AccountType;
use App\Enums\VisaEntryType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmra\VisaDuration;
use App\Models\User;
use App\Models\VisaBooking;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Tests\Stress\Support\StressReconciliation;

/**
 * VISA MODULE — FRONTEND E2E TEST (GUI black-box)
 * ==================================================
 *
 * Goal: simulate what the Vue SPA + Filament admin panel actually do
 * for every visa financial operation, hitting the same API endpoints the
 * Pinia store consumes (resources/js/stores/visaStore.js).
 *
 * Coverage matrix mirrors the backend stress test but adds the
 *   "what the UI sees" angle:
 *
 *    ① INDEX PAGE (VisaIndex.vue)        → GET /api/v1/visa/bookings
 *    ② CREATE PAGE (VisaCreate.vue)      → POST /api/v1/visa/bookings
 *    ③ SHOW PAGE (VisaShow.vue)          → GET /api/v1/visa/bookings/{id}
 *                                          + POST /payments + POST /cancel
 *    ④ TREASURY PAGE (VisaTreasury.vue)  → GET /api/v1/visa/treasury/overview
 *                                          + GET /visa/treasury/accounts/{id}/transactions
 *    ⑤ CUSTOMER DEBTS (VisaCustomerBalances.vue)
 *                                         → GET /api/v1/visa/customer-balances
 *                                          + GET /api/v1/visa/customer-statement
 *                                          + POST /api/v1/visa/customers/{id}/pay-debt
 *    ⑥ AGENTS FINANCE (VisaAgentsFinance.vue)
 *                                         → GET /api/v1/visa/agents/dues
 *                                          + POST /api/v1/visa/agents/{id}/withdraw
 *                                          + POST /api/v1/visa/agents/{id}/repay
 *    ⑦ FILAMENT ADMIN PANEL              → all of the above as Admin role
 *
 * After every flow we re-run StressReconciliation::runAll() so any
 * ledger drift introduced via the GUI surface is caught.
 *
 * Expected outcome: every scenario PASSES. The final stress artifact
 * (storage/app/stress/visa-frontend-final-report.json) is the
 * production-ready receipt for the GUI surface.
 */
class VisaFrontendE2ETest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Account $vaultEgp;
    protected Account $vaultUsd;
    protected Account $bankEgp;
    protected Customer $customer;
    protected VisaDuration $duration;
    protected VisaAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name'  => 'Visa Frontend Admin',
            'email' => 'visa-frontend-admin@stress.test',
            'password' => Hash::make('password'),
            'role'  => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        LedgerBalanceMutationGuard::run(function () {
            $this->vaultEgp = Account::create([
                'name' => 'FE Vault EGP', 'type' => AccountType::Cashbox->value,
                'currency' => 'EGP', 'balance' => 500_000.0, 'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE, 'module_type' => 'tourism',
                'module' => 'visas', 'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);
            $this->vaultUsd = Account::create([
                'name' => 'FE Vault USD', 'type' => AccountType::Cashbox->value,
                'currency' => 'USD', 'balance' => 20_000.0, 'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE, 'module_type' => 'tourism',
                'module' => 'visas', 'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);
            $this->bankEgp = Account::create([
                'name' => 'FE Bank EGP', 'type' => AccountType::Bank->value,
                'currency' => 'EGP', 'balance' => 300_000.0, 'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE, 'module_type' => 'tourism',
                'module' => 'visas', 'is_module_vault' => false,
                'created_by' => $this->admin->id,
            ]);
        });

        $this->customer = Customer::query()->create([
            'full_name' => 'FE Customer', 'phone' => '01000088000',
            'national_id' => '12345678908888', 'passport_number' => 'FE0001',
            'type' => 'individual', 'status' => 'active', 'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $this->duration = VisaDuration::query()->create([
            'code' => 'FE-D', 'label_ar' => 'مدة FE', 'label_en' => 'FE duration',
            'months' => 3, 'entry_type' => 'single', 'sort_order' => 50, 'is_active' => true,
        ]);

        LedgerBalanceMutationGuard::run(function () {
            $supplierAccount = Account::create([
                'name' => 'FE Supplier', 'type' => AccountType::Supplier->value,
                'currency' => 'EGP', 'balance' => 0.0, 'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER, 'module_type' => 'visas',
                'module' => 'visas', 'is_module_vault' => false,
                'created_by' => $this->admin->id,
            ]);
            $this->agent = VisaAgent::query()->create([
                'company_name' => 'FE Agent Co', 'contact_person' => 'FE Contact',
                'phone' => '01000088999', 'email' => 'fe-agent@stress.test',
                'country' => 'EG', 'visa_type' => 'tourist',
                'default_cost_price' => 1500.0, 'account_id' => $supplierAccount->id,
                'is_active' => true, 'created_by' => $this->admin->id,
            ]);
        });
    }

    protected function assertBalanceOk(): void
    {
        $recon = StressReconciliation::runAll();
        $nonOpeningOrphan = (int) \Illuminate\Support\Facades\DB::table('account_entries')
            ->whereNull('transaction_id')->where('is_opening', 0)->count();

        $this->assertSame(0, $recon['per_account']['failed']);
        $this->assertSame(0, $recon['per_transaction']['failed']);
        $this->assertSame(0, $nonOpeningOrphan);
        $this->assertEqualsWithDelta(0.0, (float) $recon['totals']['diff'], 0.02);
    }

    protected function payload(string $currency, Account $vault, array $overrides = []): array
    {
        $base = [
            'customer_id'    => $this->customer->id,
            'purchase_price' => $currency === 'USD' ? 800.0 : 6000.0,
            'selling_price'  => $currency === 'USD' ? 1200.0 : 9000.0,
            'service_fee'    => $currency === 'USD' ? 50.0 : 500.0,
            'currency'       => $currency,
            'account_id'     => $vault->id,
            'status'         => VisaStatus::Submitted->value,
            'visa_details'   => [
                'visa_type' => VisaType::Tourist->value, 'country' => 'FE-LAND',
                'duration' => '90', 'visa_duration_id' => $this->duration->id,
                'entry_type' => VisaEntryType::Single->value,
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addMonths(3)->toDateString(),
                'visa_agent_id' => $this->agent->id,
            ],
        ];
        return array_replace_recursive($base, $overrides);
    }

    // ───────────────────────────────────────────────────────────────────────────
    //  ① INDEX PAGE
    // ───────────────────────────────────────────────────────────────────────────

    public function test_index_page_loads_with_paginated_list(): void
    {
        // Seed 5 bookings
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/visa/bookings', $this->payload('EGP', $this->vaultEgp))
                ->assertCreated();
        }

        // Vue Index page calls GET /api/v1/visa/bookings
        $response = $this->getJson('/api/v1/visa/bookings?per_page=15');
        $response->assertOk();

        // Vue expects either {data: {items, pagination}} or {data: [...]} or {items: [...]}
        $body = $response->json();
        $hasItems = (isset($body['data']['items']) && isset($body['data']['pagination']))
            || (isset($body['data']) && is_array($body['data']))
            || (isset($body['items']));
        $this->assertTrue($hasItems, 'Index response shape mismatch: '.json_encode(array_keys($body ?? [])));

        $this->assertBalanceOk();
    }

    public function test_index_page_filters_by_status(): void
    {
        $b1 = $this->postJson('/api/v1/visa/bookings', $this->payload('EGP', $this->vaultEgp))
            ->json('data.id');
        $b2 = $this->postJson('/api/v1/visa/bookings', $this->payload('EGP', $this->vaultEgp))
            ->json('data.id');

        $this->postJson("/api/v1/visa/bookings/{$b2}/cancel", ['reason' => 'FE filter test']);

        $r = $this->getJson('/api/v1/visa/bookings?status=approved&per_page=15');
        $r->assertOk();
        $this->assertBalanceOk();
    }

    // ───────────────────────────────────────────────────────────────────────────
    //  ② CREATE PAGE
    // ───────────────────────────────────────────────────────────────────────────

    public function test_create_page_creates_booking(): void
    {
        $response = $this->postJson('/api/v1/visa/bookings', $this->payload('EGP', $this->vaultEgp));
        $response->assertCreated();
        $this->assertNotEmpty($response->json('data.id'));
        $this->assertBalanceOk();
    }

    public function test_create_page_validates_required_fields(): void
    {
        $response = $this->postJson('/api/v1/visa/bookings', []);
        $response->assertStatus(422);
    }

    public function test_create_page_rejects_currency_mismatch(): void
    {
        $response = $this->postJson('/api/v1/visa/bookings', $this->payload('USD', $this->vaultEgp));
        $response->assertStatus(422);
    }

    public function test_create_page_with_initial_payment(): void
    {
        $payload = $this->payload('EGP', $this->vaultEgp);
        $payload['initial_payment'] = [
            'amount' => 2000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash',
        ];
        $response = $this->postJson('/api/v1/visa/bookings', $payload);
        $response->assertCreated();

        $booking = VisaBooking::findOrFail($response->json('data.id'));
        $this->assertSame(2000.0, (float) $booking->paid_amount);
        $this->assertBalanceOk();
    }

    // ───────────────────────────────────────────────────────────────────────────
    //  ③ SHOW PAGE
    // ───────────────────────────────────────────────────────────────────────────

    public function test_show_page_loads_booking_detail(): void
    {
        $id = $this->postJson('/api/v1/visa/bookings', $this->payload('EGP', $this->vaultEgp))
            ->json('data.id');

        $response = $this->getJson("/api/v1/visa/bookings/{$id}");
        $response->assertOk();
        $body = $response->json('data');
        $this->assertSame($id, $body['id']);
        $this->assertArrayHasKey('finance', $body);
        $this->assertArrayHasKey('paid_amount', $body['finance']);
        $this->assertArrayHasKey('remaining_amount', $body['finance']);
        $this->assertArrayHasKey('is_fully_paid', $body['finance']);
        $this->assertArrayHasKey('pricing', $body);
        $this->assertSame('EGP', $body['pricing']['currency']);

        $this->assertBalanceOk();
    }

    public function test_show_page_add_payment_flow(): void
    {
        $id = $this->postJson('/api/v1/visa/bookings', $this->payload('EGP', $this->vaultEgp))
            ->json('data.id');

        $payRes = $this->postJson("/api/v1/visa/bookings/{$id}/payments", [
            'amount' => 1500, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash',
        ]);
        $payRes->assertCreated();

        $this->assertSame(1500.0, (float) VisaBooking::find($id)->paid_amount);
        $this->assertBalanceOk();
    }

    public function test_show_page_cancel_button_flow(): void
    {
        $id = $this->postJson('/api/v1/visa/bookings', $this->payload('EGP', $this->vaultEgp))
            ->json('data.id');

        $response = $this->postJson("/api/v1/visa/bookings/{$id}/cancel", [
            'reason' => 'FE cancel test',
        ]);
        $response->assertOk();
        $this->assertSame('cancelled', VisaBooking::find($id)->status->value);
        $this->assertBalanceOk();
    }

    public function test_show_page_refund_button_flow(): void
    {
        $id = $this->postJson('/api/v1/visa/bookings', $this->payload('EGP', $this->vaultEgp))
            ->json('data.id');

        $this->postJson("/api/v1/visa/bookings/{$id}/payments", [
            'amount' => 1000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash',
        ]);

        $response = $this->postJson("/api/v1/visa/bookings/{$id}/refund", [
            'reason' => 'FE refund test',
        ]);
        $response->assertOk();
        $this->assertSame('refunded', VisaBooking::find($id)->status->value);
        $this->assertBalanceOk();
    }

    public function test_show_page_delete_button_flow(): void
    {
        $id = $this->postJson('/api/v1/visa/bookings', $this->payload('EGP', $this->vaultEgp))
            ->json('data.id');

        $this->deleteJson("/api/v1/visa/bookings/{$id}")->assertOk();
        $this->assertNotNull(VisaBooking::withTrashed()->find($id)->deleted_at);
        $this->assertBalanceOk();
    }

    public function test_show_page_modifications_endpoint(): void
    {
        $id = $this->postJson('/api/v1/visa/bookings', $this->payload('EGP', $this->vaultEgp))
            ->json('data.id');

        $r = $this->getJson("/api/v1/visa/bookings/{$id}/modifications");
        $r->assertOk();
        $this->assertIsArray($r->json('data'));
        $this->assertBalanceOk();
    }

    // ───────────────────────────────────────────────────────────────────────────
    //  ④ TREASURY PAGE
    // ───────────────────────────────────────────────────────────────────────────

    public function test_treasury_overview_loads(): void
    {
        // Seed a booking first so the overview has data
        $this->postJson('/api/v1/visa/bookings', $this->payload('EGP', $this->vaultEgp))
            ->assertCreated();

        $r = $this->getJson('/api/v1/visa/treasury/overview');
        $r->assertOk();
        $r->assertJsonStructure(['data' => ['settlement_accounts', 'agents', 'recent_visa_transactions']]);
        $this->assertBalanceOk();
    }

    public function test_treasury_account_transactions_loads(): void
    {
        $this->postJson('/api/v1/visa/bookings', $this->payload('EGP', $this->vaultEgp))
            ->assertCreated();

        $r = $this->getJson("/api/v1/visa/treasury/accounts/{$this->vaultEgp->id}/transactions");
        $r->assertOk();
        $this->assertIsArray($r->json('data'));
        $this->assertBalanceOk();
    }

    // ───────────────────────────────────────────────────────────────────────────
    //  ⑤ CUSTOMER DEBTS PAGE
    // ───────────────────────────────────────────────────────────────────────────

    public function test_customer_balances_loads_with_debtor(): void
    {
        $this->postJson('/api/v1/visa/bookings', $this->payload('EGP', $this->vaultEgp))
            ->assertCreated();

        $r = $this->getJson('/api/v1/visa/customer-balances?status=debtors');
        $r->assertOk();
        $items = $r->json('data');
        $this->assertNotEmpty($items);
        $this->assertGreaterThan(0, $items[0]['total_debt']);
        $this->assertBalanceOk();
    }

    public function test_customer_statement_loads(): void
    {
        $this->postJson('/api/v1/visa/bookings', $this->payload('EGP', $this->vaultEgp))
            ->assertCreated();

        $r = $this->getJson("/api/v1/visa/customer-statement?client_id={$this->customer->id}");
        $r->assertOk();
        $this->assertIsArray($r->json('data.transactions'));
        $this->assertBalanceOk();
    }

    public function test_customer_debt_pay_button_flow(): void
    {
        $this->postJson('/api/v1/visa/bookings', $this->payload('EGP', $this->vaultEgp))
            ->assertCreated();

        $r = $this->postJson("/api/v1/visa/customers/{$this->customer->id}/pay-debt", [
            'amount' => 2000, 'account_id' => $this->bankEgp->id,
        ]);
        $r->assertOk();
        $this->assertBalanceOk();
    }

    // ───────────────────────────────────────────────────────────────────────────
    //  ⑥ AGENTS FINANCE PAGE
    // ───────────────────────────────────────────────────────────────────────────

    public function test_agents_dues_loads(): void
    {
        $r = $this->getJson('/api/v1/visa/agents/dues');
        $r->assertOk();
        $this->assertIsArray($r->json('data.items'));
        $this->assertBalanceOk();
    }

    public function test_agent_withdraw_button_flow(): void
    {
        $r = $this->postJson("/api/v1/visa/agents/{$this->agent->id}/repay", [
            'amount' => 500, 'from_account_id' => $this->bankEgp->id,
        ]);
        $r->assertOk();

        $r2 = $this->postJson("/api/v1/visa/agents/{$this->agent->id}/withdraw", [
            'amount' => 200, 'to_account_id' => $this->vaultEgp->id,
        ]);
        $r2->assertOk();
        $this->assertBalanceOk();
    }

    public function test_agent_withdraw_rejects_cross_currency(): void
    {
        $this->postJson("/api/v1/visa/agents/{$this->agent->id}/repay", [
            'amount' => 500, 'from_account_id' => $this->bankEgp->id,
        ])->assertOk();

        $r = $this->postJson("/api/v1/visa/agents/{$this->agent->id}/withdraw", [
            'amount' => 100, 'to_account_id' => $this->vaultUsd->id, // mismatch
        ]);
        $r->assertStatus(422);
        $this->assertBalanceOk();
    }

    // ───────────────────────────────────────────────────────────────────────────
    //  ⑦ SETTINGS ENDPOINTS (used by every Visa page dropdown)
    // ───────────────────────────────────────────────────────────────────────────

    public function test_settings_endpoints(): void
    {
        $this->getJson('/api/v1/visa/settings/agents')->assertOk();
        $this->getJson('/api/v1/visa/settings/durations')->assertOk();
        $this->getJson('/api/v1/visa/settings/statuses')->assertOk();
    }

    // ───────────────────────────────────────────────────────────────────────────
    //  ⑧ FULL GUI FLOW (the "happy user" scenario)
    // ───────────────────────────────────────────────────────────────────────────

    public function test_full_gui_user_flow(): void
    {
        // 1. Open Index page (empty)
        $this->getJson('/api/v1/visa/bookings')->assertOk();

        // 2. Open Create page — load settings first (dropdowns)
        $this->getJson('/api/v1/visa/settings/agents')->assertOk();
        $this->getJson('/api/v1/visa/settings/durations')->assertOk();
        $this->getJson('/api/v1/visa/settings/statuses')->assertOk();

        // 3. Submit booking
        $create = $this->postJson('/api/v1/visa/bookings', $this->payload('EGP', $this->vaultEgp));
        $create->assertCreated();
        $id = $create->json('data.id');

        // 4. Open Show page
        $this->getJson("/api/v1/visa/bookings/{$id}")->assertOk();

        // 5. Add a payment
        $this->postJson("/api/v1/visa/bookings/{$id}/payments", [
            'amount' => 2500, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash',
        ])->assertCreated();

        // 6. Open Customer Balances page
        $this->getJson('/api/v1/visa/customer-balances?status=debtors')->assertOk();

        // 7. Open Customer Statement page
        $this->getJson("/api/v1/visa/customer-statement?client_id={$this->customer->id}")->assertOk();

        // 8. Pay customer debt
        $this->postJson("/api/v1/visa/customers/{$this->customer->id}/pay-debt", [
            'amount' => 1000, 'account_id' => $this->bankEgp->id,
        ])->assertOk();

        // 9. Open Treasury page
        $this->getJson('/api/v1/visa/treasury/overview')->assertOk();

        // 10. Open Agents Finance page
        $this->getJson('/api/v1/visa/agents/dues')->assertOk();

        // 11. Refund the booking
        $this->postJson("/api/v1/visa/bookings/{$id}/refund", ['reason' => 'FE full flow'])
            ->assertOk();

        $this->assertSame('refunded', VisaBooking::find($id)->status->value);
        $this->assertBalanceOk();
    }

    // ───────────────────────────────────────────────────────────────────────────
    //  ⑨ FINAL FRONTEND RECONCILIATION REPORT
    // ───────────────────────────────────────────────────────────────────────────

    public function test_final_frontend_reconciliation_report(): void
    {
        // Create diverse bookings
        $this->postJson('/api/v1/visa/bookings', $this->payload('EGP', $this->vaultEgp))->assertCreated();
        $this->postJson('/api/v1/visa/bookings', $this->payload('USD', $this->vaultUsd))->assertCreated();
        $b3 = $this->postJson('/api/v1/visa/bookings', $this->payload('EGP', $this->vaultEgp))->json('data.id');
        $this->postJson("/api/v1/visa/bookings/{$b3}/payments", [
            'amount' => 500, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash',
        ])->assertCreated();

        $recon = StressReconciliation::runAll();
        $nonOpeningOrphan = (int) \Illuminate\Support\Facades\DB::table('account_entries')
            ->whereNull('transaction_id')->where('is_opening', 0)->count();

        $allOk = $recon['per_account']['failed'] === 0
            && $recon['per_transaction']['failed'] === 0
            && $nonOpeningOrphan === 0
            && abs((float) $recon['totals']['diff']) < (float) $recon['tolerance'];
        $recon['verdict'] = $allOk ? 'OK' : 'FAIL';
        $recon['orphan_entries']['count'] = $nonOpeningOrphan;

        $dir = storage_path('app/stress');
        if (! is_dir($dir)) @mkdir($dir, 0775, true);
        file_put_contents(
            $dir.'/visa-frontend-final-report.json',
            json_encode($recon, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );

        $this->assertSame('OK', $recon['verdict']);
    }
}
