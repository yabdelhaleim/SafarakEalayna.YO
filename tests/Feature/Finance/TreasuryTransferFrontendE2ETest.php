<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Employee;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Treasury Transfers — Frontend HTTP API Contract Test
 *
 * Date:   2026-08-29
 * Scope:  Black-box HTTP contract for the 3 Vue pages:
 *   - TransfersIndex.vue
 *   - TransferCreate.vue
 *   - TransferHistory.vue
 *
 * ──────────────────────────────────────────────────────────────────────
 * Modules covered:
 *   FE_01.  POST /api/v1/finance/transfers — happy path
 *   FE_02.  POST type=expense with to_account_id
 *   FE_03.  POST type=expense with to_account_name (dynamic)
 *   FE_04.  POST cross-currency EGP→USD
 *   FE_05.  POST validation 422 (missing fields)
 *   FE_06.  POST insufficient balance 422
 *   FE_07.  POST same-currency with converted_amount mismatch 422
 *   FE_08.  POST cross-currency without converted_amount 422
 *   FE_09.  POST inactive from_account 422
 *   FE_10.  GET /api/v1/finance/transfers — history list with items + summary
 *   FE_11.  GET history pagination (per_page + page)
 *   FE_12.  GET history filter from_account_id
 *   FE_13.  GET history filter to_account_id
 *   FE_14.  GET history date range filter
 *   FE_15.  GET history summary.total_amount + today_count
 *   FE_16.  POST attachment upload via multipart (jpeg/png/pdf)
 *   FE_17.  POST notes field accepts Arabic text
 *   FE_18.  POST validation: amount below 0.01 → 422
 *   FE_19.  POST validation: from == to → 422
 *   FE_20.  Happy user flow: create + view in history
 */
class TreasuryTransferFrontendE2ETest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Account $cashbox;
    protected Account $bank;
    protected Account $wallet;
    protected Account $usdVault;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Frontend E2E Admin',
            'email' => 'fe-'.uniqid('', true).'@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Employee::query()->create([
            'user_id' => $this->admin->id,
            'status' => 'active',
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        LedgerBalanceMutationGuard::run(function () {
            $this->cashbox = Account::query()->create([
                'name' => 'FE Cashbox EGP',
                'type' => AccountType::Cashbox->value,
                'currency' => 'EGP',
                'balance' => 200_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'is_module_vault' => false,
                'created_by' => $this->admin->id,
            ]);
            $this->bank = Account::query()->create([
                'name' => 'FE Bank EGP',
                'type' => AccountType::Bank->value,
                'currency' => 'EGP',
                'balance' => 100_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'is_module_vault' => false,
                'created_by' => $this->admin->id,
            ]);
            $this->wallet = Account::query()->create([
                'name' => 'FE Wallet EGP',
                'type' => AccountType::Wallet->value,
                'currency' => 'EGP',
                'balance' => 50_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'is_module_vault' => false,
                'wallet_provider' => 'vodafone_cash',
                'wallet_number' => '01099999999',
                'created_by' => $this->admin->id,
            ]);
            $this->usdVault = Account::query()->create([
                'name' => 'FE USD Vault',
                'type' => AccountType::Cashbox->value,
                'currency' => 'USD',
                'balance' => 5_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'is_module_vault' => false,
                'created_by' => $this->admin->id,
            ]);
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    //  POST /api/v1/finance/transfers
    // ──────────────────────────────────────────────────────────────────────

    public function test_FE_01_post_happy_path(): void
    {
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $this->bank->id,
            'amount' => 10_000.00,
            'notes' => 'Happy path',
        ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'transaction_id',
                    'from_account_id',
                    'to_account_id',
                    'amount',
                    'from_currency',
                    'to_currency',
                    'exchange_rate',
                    'converted_amount',
                    'notes',
                    'created_at',
                ],
            ])
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', 10_000)
            ->assertJsonPath('data.from_currency', 'EGP')
            ->assertJsonPath('data.to_currency', 'EGP');
    }

    public function test_FE_02_post_expense_with_to_account_id(): void
    {
        $expense = LedgerBalanceMutationGuard::run(fn () => Account::query()->create([
            'name' => 'مصروف FE_02',
            'type' => AccountType::Expense->value,
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'general',
            'is_module_vault' => false,
            'created_by' => $this->admin->id,
        ]));

        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $expense->id,
            'amount' => 1_500.00,
            'type' => 'expense',
            'module' => 'general',
            'notes' => 'صرف مصروف',
        ]);

        $response->assertCreated()->assertJsonPath('success', true);
    }

    public function test_FE_03_post_expense_with_to_account_name(): void
    {
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_name' => 'مصروف FE_03',
            'amount' => 750.00,
            'type' => 'expense',
            'module' => 'general',
        ]);

        $response->assertCreated()->assertJsonPath('success', true);

        $this->assertDatabaseHas('accounts', ['name' => 'مصروف FE_03']);
    }

    public function test_FE_04_post_cross_currency_egp_to_usd(): void
    {
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $this->usdVault->id,
            'amount' => 5_000.00,
            'converted_amount' => 100.00,
            'exchange_rate' => 50.0,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.from_currency', 'EGP')
            ->assertJsonPath('data.to_currency', 'USD')
            ->assertJsonPath('data.exchange_rate', 50)
            ->assertJsonPath('data.converted_amount', 100);
    }

    public function test_FE_05_post_validation_422_missing_fields(): void
    {
        $response = $this->postJson('/api/v1/finance/transfers', [
            'amount' => 100.00,
            // missing from_account_id AND to_account_id
        ]);

        $response->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_FE_06_post_insufficient_balance(): void
    {
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->wallet->id,
            'to_account_id' => $this->bank->id,
            'amount' => 999_999.00,
        ]);

        $response->assertStatus(422);
    }

    public function test_FE_07_post_same_currency_mismatched_converted_amount(): void
    {
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $this->bank->id,
            'amount' => 1_000.00,
            'converted_amount' => 999.00,
        ]);

        $response->assertStatus(422);
    }

    public function test_FE_08_post_cross_currency_missing_converted_amount(): void
    {
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $this->usdVault->id,
            'amount' => 1_000.00,
            // missing converted_amount
        ]);

        $response->assertStatus(422);
    }

    public function test_FE_09_post_inactive_from_account(): void
    {
        $inactive = LedgerBalanceMutationGuard::run(fn () => Account::query()->create([
            'name' => 'FE Inactive',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 10_000.00,
            'is_active' => false,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'office',
            'is_module_vault' => false,
            'created_by' => $this->admin->id,
        ]));

        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $inactive->id,
            'to_account_id' => $this->bank->id,
            'amount' => 1_000.00,
        ]);

        $response->assertStatus(422);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  GET /api/v1/finance/transfers (history)
    // ──────────────────────────────────────────────────────────────────────

    public function test_FE_10_get_history_list(): void
    {
        // Seed 3 transfers
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/v1/finance/transfers', [
                'from_account_id' => $this->cashbox->id,
                'to_account_id' => $this->bank->id,
                'amount' => 1000.00 * $i,
                'notes' => "تحويل {$i}",
            ])->assertCreated();
        }

        $response = $this->getJson('/api/v1/finance/transfers');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'items' => [
                        '*' => [
                            'id',
                            'amount',
                            'date',
                            'from_account' => ['id', 'name', 'currency', 'type'],
                            'to_account' => ['id', 'name', 'currency', 'type'],
                            'description',
                            'notes',
                            'from_account_id',
                            'to_account_id',
                            'created_at',
                            'transaction_id',
                        ],
                    ],
                    'pagination' => ['total', 'current_page', 'last_page', 'per_page'],
                    'summary' => ['total_amount', 'today_count'],
                ],
            ])
            ->assertJsonPath('data.pagination.total', 3);
    }

    public function test_FE_11_get_history_pagination(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v1/finance/transfers', [
                'from_account_id' => $this->cashbox->id,
                'to_account_id' => $this->bank->id,
                'amount' => 100.00 * $i,
            ])->assertCreated();
        }

        $page1 = $this->getJson('/api/v1/finance/transfers?per_page=2&page=1');
        $page1->assertOk()
            ->assertJsonPath('data.pagination.total', 5)
            ->assertJsonPath('data.pagination.per_page', 2)
            ->assertJsonPath('data.pagination.last_page', 3)
            ->assertJsonCount(2, 'data.items');

        $page3 = $this->getJson('/api/v1/finance/transfers?per_page=2&page=3');
        $page3->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_FE_12_get_history_filter_from_account(): void
    {
        // 2 cashbox→bank, 1 bank→wallet
        $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $this->bank->id,
            'amount' => 1_000.00,
        ])->assertCreated();
        $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $this->bank->id,
            'amount' => 2_000.00,
        ])->assertCreated();
        $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->bank->id,
            'to_account_id' => $this->wallet->id,
            'amount' => 3_000.00,
        ])->assertCreated();

        $response = $this->getJson("/api/v1/finance/transfers?from_account_id={$this->cashbox->id}");

        $response->assertOk()
            ->assertJsonPath('data.pagination.total', 2);
    }

    public function test_FE_13_get_history_filter_to_account(): void
    {
        $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $this->bank->id,
            'amount' => 1_000.00,
        ])->assertCreated();
        $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $this->wallet->id,
            'amount' => 2_000.00,
        ])->assertCreated();
        $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $this->bank->id,
            'amount' => 3_000.00,
        ])->assertCreated();

        $response = $this->getJson("/api/v1/finance/transfers?to_account_id={$this->bank->id}");

        $response->assertOk()
            ->assertJsonPath('data.pagination.total', 2);
    }

    public function test_FE_14_get_history_date_range(): void
    {
        // Create 1 transfer today
        $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $this->bank->id,
            'amount' => 1_000.00,
        ])->assertCreated();

        $today = now()->toDateString();

        $response = $this->getJson("/api/v1/finance/transfers?from_date={$today}&to_date={$today}");

        $response->assertOk()
            ->assertJsonPath('data.pagination.total', 1);
    }

    public function test_FE_15_get_history_summary(): void
    {
        // Create 2 transfers
        $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $this->bank->id,
            'amount' => 1_500.00,
        ])->assertCreated();
        $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $this->bank->id,
            'amount' => 2_500.00,
        ])->assertCreated();

        $response = $this->getJson('/api/v1/finance/transfers');
        $response->assertOk();

        // total_amount = sum of all transfer amounts = 4,000
        $this->assertSame(4_000.00, (float) $response->json('data.summary.total_amount'));
        // today_count = 2 (both created today)
        $this->assertSame(2, (int) $response->json('data.summary.today_count'));
    }

    // ──────────────────────────────────────────────────────────────────────
    //  POST extras: attachment, Arabic notes, validation
    // ──────────────────────────────────────────────────────────────────────

    public function test_FE_16_post_attachment_upload(): void
    {
        // Write a real PDF magic header so mime detection works
        $tmpFile = tempnam(sys_get_temp_dir(), 'fe_attach_').'.pdf';
        file_put_contents($tmpFile, "%PDF-1.4\n%\xff\xff\xff\xff\n");

        $response = $this->post('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $this->bank->id,
            'amount' => 1_000.00,
            'attachment' => new \Illuminate\Http\UploadedFile($tmpFile, 'receipt.pdf', 'application/pdf', null, true),
        ]);

        @unlink($tmpFile);

        $response->assertCreated()->assertJsonPath('success', true);
    }

    public function test_FE_17_post_arabic_notes(): void
    {
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $this->bank->id,
            'amount' => 500.00,
            'notes' => 'تحويل مصاريف شهرية للبنك الأهلي',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.notes', 'تحويل مصاريف شهرية للبنك الأهلي');
    }

    public function test_FE_18_post_validation_amount_below_min(): void
    {
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $this->bank->id,
            'amount' => 0.001,
        ]);

        $response->assertStatus(422);
    }

    public function test_FE_19_post_validation_from_equals_to(): void
    {
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $this->cashbox->id,
            'amount' => 1_000.00,
        ]);

        $response->assertStatus(422);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  FE_20: Full happy-user flow
    // ──────────────────────────────────────────────────────────────────────

    public function test_FE_20_happy_user_full_flow(): void
    {
        // 1. Create transfer
        $createResp = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->cashbox->id,
            'to_account_id' => $this->bank->id,
            'amount' => 5_000.00,
            'notes' => 'Full flow transfer',
        ]);
        $createResp->assertCreated();

        $transferId = $createResp->json('data.id');

        // 2. List history → should include this transfer
        $listResp = $this->getJson('/api/v1/finance/transfers?per_page=10');
        $listResp->assertOk();
        $items = $listResp->json('data.items');
        $found = collect($items)->firstWhere('transaction_id', $transferId);
        $this->assertNotNull($found, 'Created transfer must appear in history');

        // 3. Verify summary now reflects the new transfer
        $this->assertSame(5_000.00, (float) $listResp->json('data.summary.total_amount'));

        // 4. Verify balances updated
        $this->assertSame(195_000.00, (float) $this->cashbox->fresh()->balance);
        $this->assertSame(105_000.00, (float) $this->bank->fresh()->balance);
    }
}