<?php

namespace Tests\Feature\HajjUmra;

use App\Models\Account;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Decomposed per-action tests for Api\V1\HajjUmra\HajjUmraExecutingCompanyFinanceController.
 *
 * @see \App\Http\Controllers\Api\V1\HajjUmra\HajjUmraExecutingCompanyFinanceController
 */
class HajjUmraExecutingCompanyFinanceControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'ExecCompany Tester',
            'email' => 'exec-company@'.now()->timestamp.'.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->user, ['*']);

        $this->treasury = Account::query()->create([
            'name' => 'Hajj Treasury',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'module' => 'hajj_umra',
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);
    }

    protected function makeCompany(bool $active = true): HajjUmraExecutingCompany
    {
        return HajjUmraExecutingCompany::query()->create([
            'name' => 'شركة تنفيذ اختبار',
            'license_number' => 'TEST-001',
            'phone' => '+966500000000',
            'is_active' => $active,
        ]);
    }

    /* =========================================================
     * DUES — auto-creates account for company without one
     * ========================================================= */

    public function test_dues_auto_creates_account_for_company_without_one(): void
    {
        $company = $this->makeCompany();

        $response = $this->getJson('/api/v1/hajj-umra/executing-companies/dues');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'items' => [
                        '*' => ['id', 'name', 'account_id', 'total_withdrawn', 'total_repaid', 'net_due'],
                    ],
                ],
            ]);

        $company->refresh();
        $this->assertNotNull($company->account_id);
    }

    public function test_dues_excludes_inactive_companies(): void
    {
        $this->makeCompany(true);
        $this->makeCompany(false);

        $response = $this->getJson('/api/v1/hajj-umra/executing-companies/dues');

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
    }

    public function test_dues_returns_zero_for_company_with_no_transactions(): void
    {
        $this->makeCompany();

        $response = $this->getJson('/api/v1/hajj-umra/executing-companies/dues');

        $items = $response->json('data.items');
        $this->assertEquals(0, $items[0]['total_withdrawn']);
        $this->assertEquals(0, $items[0]['total_repaid']);
        $this->assertEquals(0, $items[0]['net_due']);
    }

    /* =========================================================
     * WITHDRAW
     * ========================================================= */

    public function test_withdraw_records_transaction_from_company_to_treasury(): void
    {
        $company = $this->makeCompany();
        $this->getJson('/api/v1/hajj-umra/executing-companies/dues'); // forces account creation

        $company->refresh();

        $response = $this->postJson("/api/v1/hajj-umra/executing-companies/{$company->id}/withdraw", [
            'amount' => 1000,
            'to_account_id' => $this->treasury->id,
            'notes' => 'test withdraw',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['transaction_id']]);
    }

    public function test_withdraw_requires_amount_and_to_account(): void
    {
        $company = $this->makeCompany();
        $this->getJson('/api/v1/hajj-umra/executing-companies/dues');

        $response = $this->postJson("/api/v1/hajj-umra/executing-companies/{$company->id}/withdraw", [
            'amount' => 1000,
            // missing to_account_id
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['to_account_id']);
    }

    public function test_withdraw_rejects_office_division_target_account(): void
    {
        $company = $this->makeCompany();
        $this->getJson('/api/v1/hajj-umra/executing-companies/dues');

        $officeTreasury = Account::query()->create([
            'name' => 'Office Treasury',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 50000.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'office',
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);

        $response = $this->postJson("/api/v1/hajj-umra/executing-companies/{$company->id}/withdraw", [
            'amount' => 1000,
            'to_account_id' => $officeTreasury->id,
        ]);

        $response->assertStatus(422);
    }

    /* =========================================================
     * REPAY
     * ========================================================= */

    public function test_repay_records_transaction_from_treasury_to_company(): void
    {
        $company = $this->makeCompany();
        $this->getJson('/api/v1/hajj-umra/executing-companies/dues');

        $response = $this->postJson("/api/v1/hajj-umra/executing-companies/{$company->id}/repay", [
            'amount' => 1000,
            'from_account_id' => $this->treasury->id,
            'notes' => 'test repay',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['data' => ['transaction_id']]);
    }

    public function test_repay_blocks_when_source_balance_insufficient(): void
    {
        $company = $this->makeCompany();
        $this->getJson('/api/v1/hajj-umra/executing-companies/dues');

        // Try to repay more than the treasury balance
        $response = $this->postJson("/api/v1/hajj-umra/executing-companies/{$company->id}/repay", [
            'amount' => 999999, // exceeds 100000 treasury balance
            'from_account_id' => $this->treasury->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_repay_requires_from_account_id(): void
    {
        $company = $this->makeCompany();
        $this->getJson('/api/v1/hajj-umra/executing-companies/dues');

        $response = $this->postJson("/api/v1/hajj-umra/executing-companies/{$company->id}/repay", [
            'amount' => 1000,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['from_account_id']);
    }
}