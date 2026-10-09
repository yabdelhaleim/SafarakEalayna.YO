<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Employee;
use App\Models\Transaction;
use App\Models\Transfer;
use App\Models\User;
use App\Services\Finance\TransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Treasury Transfers — Cross-Currency FX Deep Test
 *
 * Date:   2026-08-29
 * Scope:  Cross-currency inter-account transfers (FX path)
 *
 * ──────────────────────────────────────────────────────────────────────
 * Modules covered:
 *   FX01.  EGP → USD with explicit converted_amount + exchange_rate
 *   FX02.  EGP → USD with auto-derived exchange_rate (rate auto-computed)
 *   FX03.  USD → EGP with explicit values
 *   FX04.  USD → KWD (triple-currency pair)
 *   FX05.  KWD → EGP
 *   FX06.  EGP → USD WITHOUT converted_amount → 422
 *   FX07.  Same-currency transfer with mismatched converted_amount → 422
 *   FX08.  Same-currency transfer with matching converted_amount → success
 *   FX09.  Cross-currency with exchange_rate=0 → 422
 *   FX10.  exchange_rate precision: 6-decimal value preserved
 *   FX11.  Cross-currency with multiple sequential transfers (FX sequence)
 *   FX12.  converted_amount larger than amount (inverse direction)
 *
 * Every assertion is at DB level — HTTP 201 ≠ correct accounting.
 */
class TreasuryTransferCrossCurrencyFXDeepTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Account $egpVault;
    protected Account $usdVault;
    protected Account $kwdVault;
    protected TransactionService $tx;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'FX Admin',
            'email' => 'fx-'.uniqid('', true).'@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Employee::query()->create([
            'user_id' => $this->admin->id,
            'status' => 'active',
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->tx = app(TransactionService::class);

        LedgerBalanceMutationGuard::run(function () {
            $this->egpVault = Account::query()->create([
                'name' => 'EGP Vault',
                'type' => AccountType::Cashbox->value,
                'currency' => 'EGP',
                'balance' => 1_000_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'is_module_vault' => false,
                'created_by' => $this->admin->id,
            ]);
            $this->usdVault = Account::query()->create([
                'name' => 'USD Vault',
                'type' => AccountType::Cashbox->value,
                'currency' => 'USD',
                'balance' => 10_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'is_module_vault' => false,
                'created_by' => $this->admin->id,
            ]);
            $this->kwdVault = Account::query()->create([
                'name' => 'KWD Vault',
                'type' => AccountType::Bank->value,
                'currency' => 'KWD',
                'balance' => 5_000.000,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'is_module_vault' => false,
                'created_by' => $this->admin->id,
            ]);
        });
    }

    // ──────────────────────────────────────────────────────────────────────
    //  FX01–FX05: Cross-currency happy paths
    // ──────────────────────────────────────────────────────────────────────

    public function test_FX01_egp_to_usd_with_explicit_values(): void
    {
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->egpVault->id,
            'to_account_id' => $this->usdVault->id,
            'amount' => 50_000.00,
            'converted_amount' => 1_000.00,
            'exchange_rate' => 50.0,
            'notes' => 'شراء دولار',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.from_currency', 'EGP')
            ->assertJsonPath('data.to_currency', 'USD')
            ->assertJsonPath('data.exchange_rate', 50)
            ->assertJsonPath('data.converted_amount', 1_000);

        $this->assertSame(950_000.00, (float) $this->egpVault->fresh()->balance);
        $this->assertSame(11_000.00, (float) $this->usdVault->fresh()->balance);

        $transfer = Transfer::query()->latest('id')->first();
        $this->assertSame('EGP', $transfer->from_currency);
        $this->assertSame('USD', $transfer->to_currency);
        $this->assertSame(50.0, (float) $transfer->exchange_rate);
        $this->assertSame(1_000.00, (float) $transfer->converted_amount);
    }

    public function test_FX02_egp_to_usd_with_auto_derived_exchange_rate(): void
    {
        // No exchange_rate provided → service derives = round(amount/converted_amount, 6)
        // 50,000 / 1,000 = 50.0
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->egpVault->id,
            'to_account_id' => $this->usdVault->id,
            'amount' => 50_000.00,
            'converted_amount' => 1_000.00,
            'notes' => 'تحويل بدون rate صريح',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.exchange_rate', 50)
            ->assertJsonPath('data.converted_amount', 1_000);

        $transfer = Transfer::query()->latest('id')->first();
        $this->assertSame(50.0, (float) $transfer->exchange_rate);
    }

    public function test_FX03_usd_to_egp_with_explicit_values(): void
    {
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->usdVault->id,
            'to_account_id' => $this->egpVault->id,
            'amount' => 1_000.00,
            'converted_amount' => 49_500.00,
            'exchange_rate' => 49.5,
            'notes' => 'بيع دولار',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.from_currency', 'USD')
            ->assertJsonPath('data.to_currency', 'EGP')
            ->assertJsonPath('data.exchange_rate', 49.5)
            ->assertJsonPath('data.converted_amount', 49_500);

        $this->assertSame(9_000.00, (float) $this->usdVault->fresh()->balance);
        $this->assertSame(1_049_500.00, (float) $this->egpVault->fresh()->balance);
    }

    public function test_FX04_usd_to_kwd_triple_currency_pair(): void
    {
        // USD → KWD via direct inter-account transfer
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->usdVault->id,
            'to_account_id' => $this->kwdVault->id,
            'amount' => 1_000.00,
            'converted_amount' => 305.000,
            'exchange_rate' => 0.305,
            'notes' => 'USD → KWD',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.from_currency', 'USD')
            ->assertJsonPath('data.to_currency', 'KWD')
            ->assertJsonPath('data.exchange_rate', 0.305)
            ->assertJsonPath('data.converted_amount', 305);

        $this->assertSame(9_000.00, (float) $this->usdVault->fresh()->balance);
        $this->assertSame(5_305.000, (float) $this->kwdVault->fresh()->balance);
    }

    public function test_FX05_kwd_to_egp(): void
    {
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->kwdVault->id,
            'to_account_id' => $this->egpVault->id,
            'amount' => 100.000,
            'converted_amount' => 12_500.00,
            'exchange_rate' => 125.0,
            'notes' => 'KWD → EGP',
        ]);

        $response->assertCreated();
        $this->assertSame(4_900.000, (float) $this->kwdVault->fresh()->balance);
        $this->assertSame(1_012_500.00, (float) $this->egpVault->fresh()->balance);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  FX06–FX09: Validation failures
    // ──────────────────────────────────────────────────────────────────────

    public function test_FX06_cross_currency_without_converted_amount_rejected(): void
    {
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->egpVault->id,
            'to_account_id' => $this->usdVault->id,
            'amount' => 1_000.00,
            // missing converted_amount
            'exchange_rate' => 50.0,
            'notes' => 'بدون converted_amount',
        ]);

        $response->assertStatus(422);
    }

    public function test_FX07_same_currency_with_mismatched_converted_amount_rejected(): void
    {
        $egp2 = LedgerBalanceMutationGuard::run(fn () => Account::query()->create([
            'name' => 'EGP Vault 2',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'office',
            'is_module_vault' => false,
            'created_by' => $this->admin->id,
        ]));

        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->egpVault->id,
            'to_account_id' => $egp2->id,
            'amount' => 1_000.00,
            'converted_amount' => 999.00, // mismatch
            'notes' => 'نفس العملة مع converted_amount مختلف',
        ]);

        $response->assertStatus(422);
    }

    public function test_FX08_same_currency_with_matching_converted_amount_succeeds(): void
    {
        $egp2 = LedgerBalanceMutationGuard::run(fn () => Account::query()->create([
            'name' => 'EGP Vault 2',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 0.00,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'office',
            'is_module_vault' => false,
            'created_by' => $this->admin->id,
        ]));

        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->egpVault->id,
            'to_account_id' => $egp2->id,
            'amount' => 1_000.00,
            'converted_amount' => 1_000.00, // matches exactly
            'notes' => 'نفس العملة مع converted_amount متطابق',
        ]);

        $response->assertCreated();
        $this->assertSame(999_000.00, (float) $this->egpVault->fresh()->balance);
        $this->assertSame(1_000.00, (float) $egp2->fresh()->balance);
    }

    public function test_FX09_cross_currency_with_zero_rate_rejected(): void
    {
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->egpVault->id,
            'to_account_id' => $this->usdVault->id,
            'amount' => 1_000.00,
            'converted_amount' => 20.00,
            'exchange_rate' => 0, // violates min:0.000001
            'notes' => 'rate = 0',
        ]);

        $response->assertStatus(422);
    }

    // ──────────────────────────────────────────────────────────────────────
    //  FX10–FX12: Precision + sequence + edge cases
    // ──────────────────────────────────────────────────────────────────────

    public function test_FX10_exchange_rate_precision_6_decimals(): void
    {
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->egpVault->id,
            'to_account_id' => $this->usdVault->id,
            'amount' => 100.00,
            'converted_amount' => 5.76923,
            'exchange_rate' => 17.333333, // 6-decimal precision
            'notes' => 'دقة 6 أرقام عشرية',
        ]);

        $response->assertCreated();

        $transfer = Transfer::query()->latest('id')->first();
        // exchange_rate column is DECIMAL(10,6) → preserves up to 6 decimals
        $this->assertSame(17.333333, (float) $transfer->exchange_rate);
    }

    public function test_FX11_sequence_of_cross_currency_transfers(): void
    {
        $egp0 = (float) $this->egpVault->balance;
        $usd0 = (float) $this->usdVault->balance;
        $kwd0 = (float) $this->kwdVault->balance;

        // 1. EGP → USD
        $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->egpVault->id,
            'to_account_id' => $this->usdVault->id,
            'amount' => 100_000.00,
            'converted_amount' => 2_000.00,
            'exchange_rate' => 50.0,
        ])->assertCreated();

        // 2. USD → KWD
        $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->usdVault->id,
            'to_account_id' => $this->kwdVault->id,
            'amount' => 500.00,
            'converted_amount' => 152.500,
            'exchange_rate' => 0.305,
        ])->assertCreated();

        // 3. KWD → EGP
        $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->kwdVault->id,
            'to_account_id' => $this->egpVault->id,
            'amount' => 50.000,
            'converted_amount' => 6_250.00,
            'exchange_rate' => 125.0,
        ])->assertCreated();

        // 3 transfers → 3 transactions, 6 AccountEntry rows, 3 Transfer rows.
        $this->assertSame(3, Transfer::query()->count());
        $this->assertSame(3, Transaction::query()->where('type', 'transfer')->count());
        $this->assertSame(6, AccountEntry::query()->whereNotNull('transaction_id')->count());

        // Sum of balances should not change (per-asset-class, accounting
        // invariant). EGP/USD/KWD are different currencies so we don't sum
        // them — instead verify each is the expected post-state.
        $this->assertSame($egp0 - 100_000.00 + 6_250.00, (float) $this->egpVault->fresh()->balance);
        $this->assertSame($usd0 + 2_000.00 - 500.00, (float) $this->usdVault->fresh()->balance);
        $this->assertSame($kwd0 + 152.500 - 50.000, (float) $this->kwdVault->fresh()->balance);
    }

    public function test_FX12_converted_amount_larger_than_amount_inverse_rate(): void
    {
        // KWD is much smaller than EGP numerically (1 KWD ≈ 125 EGP).
        // Sending 10 KWD should credit 1,250 EGP.
        $response = $this->postJson('/api/v1/finance/transfers', [
            'from_account_id' => $this->kwdVault->id,
            'to_account_id' => $this->egpVault->id,
            'amount' => 10.000,
            'converted_amount' => 1_250.00,
            'exchange_rate' => 125.0,
            'notes' => 'KWD → EGP (rate > 1)',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.exchange_rate', 125)
            ->assertJsonPath('data.converted_amount', 1_250);

        $this->assertSame(4_990.000, (float) $this->kwdVault->fresh()->balance);
        $this->assertSame(1_001_250.00, (float) $this->egpVault->fresh()->balance);

        // Audit: the two AccountEntry rows on this transfer must reflect
        // the asymmetric values (debit 10 on KWD, credit 1250 on EGP).
        $tx = Transaction::query()->latest('id')->first();
        $entries = AccountEntry::query()->where('transaction_id', $tx->id)->orderBy('id')->get();
        $debitEntry = $entries->where('debit', '>', 0)->first();
        $creditEntry = $entries->where('credit', '>', 0)->first();

        $this->assertSame($this->kwdVault->id, $debitEntry->account_id);
        $this->assertSame(10.000, (float) $debitEntry->debit);
        $this->assertSame($this->egpVault->id, $creditEntry->account_id);
        $this->assertSame(1_250.00, (float) $creditEntry->credit);
    }
}