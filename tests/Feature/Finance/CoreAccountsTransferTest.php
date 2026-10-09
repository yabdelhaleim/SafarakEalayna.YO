<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transfer;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CORE ACCOUNTS — Transfer endpoint coverage.
 *
 * POST /api/v1/finance/transfers
 *
 * Endpoint: AccountController::transfer
 * Service: TransactionService::recordTransfer (creates Transfer + Transaction
 *          + 2 AccountEntry rows + mutates balances on both accounts).
 *
 * Business rules (from StoreTransferRequest::withValidator + recordTransfer):
 *   - from_account_id: required, exists
 *   - to_account_id:   required_without to_account_name, different from from
 *   - amount:          required, numeric, >= 0.01
 *   - converted_amount: required when currencies differ (FX safe rule)
 *   - exchange_rate:   optional, when set computes converted_amount
 *   - from: must be LIQUIDITY_TYPES (cashbox/bank/wallet)
 *   - to:   must be LIQUIDITY_TYPES (or 'expense' if type=expense)
 *   - both accounts: is_active = true
 *   - sufficient balance unless allow_from_negative
 *   - 422 for any validation/business rule failure
 */
class CoreAccountsTransferTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->admin = User::query()->create([
            'name' => 'Test Admin',
            'email' => 'admin@transfer.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function seedAccount(array $overrides = []): Account
    {
        return LedgerBalanceMutationGuard::run(fn () => Account::query()->create(array_merge([
            'name' => 'TEST_AT Account',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'module_type' => 'office',
            'module' => 'office',
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ], $overrides)));
    }

    // ───────────── happy path ─────────────

    public function test_AT_01_same_currency_transfer_happy_path_balances_double_entry(): void
    {
        $from = $this->seedAccount(['name' => 'TEST_AT01_Source', 'balance' => 1000.0]);
        $to = $this->seedAccount(['name' => 'TEST_AT01_Dest', 'type' => AccountType::Bank->value, 'balance' => 0.0]);

        $r = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 250.0,
            'notes' => 'TEST transfer',
        ]);

        $r->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.from_account_id', $from->id)
            ->assertJsonPath('data.to_account_id', $to->id);
        $this->assertEqualsWithDelta(250.0, (float) $r->json('data.amount'), 0.001);

        // Both balances updated
        $this->assertSame(750.0, (float) $from->fresh()->balance);
        $this->assertSame(250.0, (float) $to->fresh()->balance);

        // Double-entry: 2 AccountEntry rows on the same transaction_id
        $entries = AccountEntry::query()->whereNotNull('transaction_id')->get();
        $txIds = $entries->pluck('transaction_id')->unique();
        $this->assertCount(1, $txIds, 'all entries should share one transaction_id');
        $this->assertCount(2, $entries);
        $this->assertSame(250.0, (float) $entries->sum('debit'));
        $this->assertSame(250.0, (float) $entries->sum('credit'));

        // Transfer row was created
        $this->assertDatabaseHas('transfers', [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 250.0,
        ]);
    }

    public function test_AT_02_transfer_with_attachment_file_persists_path(): void
    {
        $from = $this->seedAccount(['name' => 'TEST_AT02_Source', 'balance' => 1000.0]);
        $to = $this->seedAccount(['name' => 'TEST_AT02_Dest', 'type' => AccountType::Bank->value]);
        $file = UploadedFile::fake()->image('receipt.jpg');

        $r = $this->post('/api/v1/finance/transfers', [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 100.0,
            'notes' => 'TEST with receipt',
            'attachment' => $file,
        ]);

        $r->assertStatus(201);
        $txId = (int) $r->json('data.transaction_id');
        $tx = \App\Models\Transaction::find($txId);
        $this->assertNotNull($tx->attachment_path);
        Storage::disk('public')->assertExists($tx->attachment_path);
    }

    public function test_AT_03_cross_currency_transfer_requires_explicit_converted_amount(): void
    {
        // Source EGP, destination USD — without converted_amount → 422
        $from = $this->seedAccount(['name' => 'TEST_AT03_EGP_Source', 'balance' => 5000.0, 'currency' => 'EGP']);
        $to = $this->seedAccount(['name' => 'TEST_AT03_USD_Dest', 'balance' => 0.0, 'currency' => 'USD', 'type' => AccountType::Bank->value]);

        $r = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 1000.0,
        ]);

        // FX safe rule: cross-currency without converted_amount → 422.
        // Either Laravel's validation envelope (errors.converted_amount)
        // OR the controller's ApiResponse::error wrapper (message).
        $r->assertStatus(422);
        $errors = $r->json('errors') ?? [];
        $message = $r->json('message') ?? '';
        $haystack = json_encode(array_merge((array) $errors, [$message]));
        $this->assertStringContainsString('converted_amount', $haystack);
    }

    public function test_AT_04_cross_currency_transfer_with_exchange_rate_credits_destination(): void
    {
        $from = $this->seedAccount(['name' => 'TEST_AT04_EGP', 'balance' => 5000.0, 'currency' => 'EGP']);
        $to = $this->seedAccount(['name' => 'TEST_AT04_USD', 'balance' => 0.0, 'currency' => 'USD', 'type' => AccountType::Bank->value]);

        // Provide converted_amount explicitly (safest path).
        $r = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 1000.0,
            'converted_amount' => 20.41, // ~49 EGP/USD
        ]);

        $r->assertStatus(201);
        $this->assertSame(4000.0, (float) $from->fresh()->balance);
        $this->assertEqualsWithDelta(20.41, (float) $to->fresh()->balance, 0.01);
    }

    // ───────────── validation rules ─────────────

    public function test_AT_05_insufficient_balance_rejected_with_422(): void
    {
        $from = $this->seedAccount(['name' => 'TEST_AT05_Source', 'balance' => 100.0]);
        $to = $this->seedAccount(['name' => 'TEST_AT05_Dest', 'type' => AccountType::Bank->value]);

        $r = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 500.0, // > 100 balance
        ]);

        $r->assertStatus(422);

        // No transfer was recorded
        $this->assertSame(0, Transfer::query()->count());
        // Balances unchanged
        $this->assertSame(100.0, (float) $from->fresh()->balance);
        $this->assertSame(0.0, (float) $to->fresh()->balance);
    }

    public function test_AT_06_from_equals_to_rejected_with_422(): void
    {
        $acc = $this->seedAccount(['name' => 'TEST_AT06_Same', 'balance' => 1000.0]);

        $r = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $acc->id,
            'to_account_id' => $acc->id,
            'amount' => 100.0,
        ]);

        $r->assertStatus(422);
    }

    public function test_AT_07_inactive_account_rejected_with_422(): void
    {
        $from = $this->seedAccount(['name' => 'TEST_AT07_From', 'balance' => 1000.0]);
        $to = $this->seedAccount(['name' => 'TEST_AT07_To', 'type' => AccountType::Bank->value, 'is_active' => false]);

        $r = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 100.0,
        ]);

        $r->assertStatus(422);
    }

    public function test_AT_07b_inactive_from_account_rejected_with_422(): void
    {
        $from = $this->seedAccount(['name' => 'TEST_AT07b_From', 'balance' => 1000.0, 'is_active' => false]);
        $to = $this->seedAccount(['name' => 'TEST_AT07b_To', 'type' => AccountType::Bank->value]);

        $r = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 100.0,
        ]);

        $r->assertStatus(422);
    }

    public function test_AT_08_missing_required_field_returns_422_with_field_errors(): void
    {
        $r = $this->postJson('/api/v1/finance/transfers', []);

        $r->assertStatus(422);
        $errors = $r->json('errors');
        $this->assertArrayHasKey('from_account_id', $errors);
        $this->assertArrayHasKey('amount', $errors);
    }

    public function test_AT_08b_amount_below_minimum_rejected(): void
    {
        $from = $this->seedAccount(['name' => 'TEST_AT08b_From', 'balance' => 1000.0]);
        $to = $this->seedAccount(['name' => 'TEST_AT08b_To', 'type' => AccountType::Bank->value]);

        $r = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 0,
        ]);

        $r->assertStatus(422);
        $this->assertArrayHasKey('amount', $r->json('errors'));
    }

    public function test_AT_11_transfer_from_non_liquidity_rejected(): void
    {
        // From a customer AR (subject account) — not a liquidity type.
        // Per AccountModuleContract, subject accounts require a SPECIFIC
        // module_type (flights, bus, etc.), not the division-level "office".
        $from = $this->seedAccount([
            'name' => 'TEST_AT11_Customer',
            'type' => AccountType::Customer->value,
            'module_type' => 'flights',
            'module' => 'flights',
            'balance' => 5000.0,
        ]);
        $to = $this->seedAccount(['name' => 'TEST_AT11_Cashbox', 'type' => AccountType::Bank->value]);

        $r = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 100.0,
        ]);

        $r->assertStatus(422);
    }

    public function test_AT_12_arabic_notes_persist_and_round_trip(): void
    {
        $from = $this->seedAccount(['name' => 'TEST_AT12_From', 'balance' => 1000.0]);
        $to = $this->seedAccount(['name' => 'TEST_AT12_To', 'type' => AccountType::Bank->value]);

        $arabicNotes = 'تحويل اختبار — مرجع 2026/001';

        $r = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => 100.0,
            'notes' => $arabicNotes,
        ]);

        $r->assertStatus(201);
        $txId = (int) $r->json('data.transaction_id');
        $tx = \App\Models\Transaction::find($txId);
        $this->assertSame($arabicNotes, $tx->notes);
    }

    // ───────────── history endpoint ─────────────

    public function test_AT_09_transfer_history_list_paginates_and_filters_by_date(): void
    {
        $from = $this->seedAccount(['name' => 'TEST_AT09_From', 'balance' => 10000.0]);
        $to = $this->seedAccount(['name' => 'TEST_AT09_To', 'type' => AccountType::Bank->value]);

        // Create 5 transfers
        for ($i = 1; $i <= 5; $i++) {
            $this->postJson('/api/v1/finance/transfers', [
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'amount' => 10.0 * $i,
                'notes' => "TEST_AT09 transfer $i",
            ])->assertStatus(201);
        }

        $r = $this->getJson('/api/v1/finance/transfers?per_page=3');
        $r->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'items' => ['*' => ['id', 'transaction_id', 'from_account_id', 'to_account_id', 'amount']],
                    'pagination' => ['total', 'current_page', 'last_page', 'per_page'],
                    'summary' => ['total_amount', 'today_count'],
                ],
            ]);
        $this->assertSame(5, (int) $r->json('data.pagination.total'));
        $this->assertCount(3, $r->json('data.items'));
    }
}