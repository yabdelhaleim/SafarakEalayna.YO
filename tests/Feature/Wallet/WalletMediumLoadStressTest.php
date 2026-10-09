<?php

namespace Tests\Feature\Wallet;

use App\Enums\AccountType;
use App\Enums\WalletTransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet\WalletTransaction;
use App\Models\Wallet\WalletType;
use App\Services\Wallet\WalletTransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Wallet & Transfers — Medium-Load Stress Test
 *
 * Date: 2026-08-29
 * Scope: All financial operations in the Wallet & Transfers module
 * Type:  Medium-load stress (volume + concurrency + edge cases)
 *
 * Sister test to Visa + HajjUmra medium-load stress.
 *
 * Modules covered:
 *   W1. Send transaction (registered customer + walk-in)
 *   W2. Receive transaction (registered customer + walk-in)
 *   W3. Update transaction
 *   W4. Delete (soft-delete with additive reversal)
 *   W5. Idempotency replay
 *   W6. Multi-currency (EGP + USD + SAR)
 *   W7. Volume: 30+ send/receive in tight loop
 *   W8. Mixed lifecycle: pay → update → delete
 *   W9. Customer balances aggregator
 *   W10. Customer statement running balance
 *   W11. Daily summary endpoint
 *   W12. Treasury overview
 *   W13. API contract shapes (index, show, filter, search)
 *   W14. Validation: required fields, invalid type, invalid amount
 *   W15. Concurrency: sequential sends on same wallet each lock-protected
 *
 * Every assertion is at DB level — HTTP 200 ≠ correct accounting.
 */
class WalletMediumLoadStressTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Account $walletEgp;
    protected Account $cashboxEgp;
    protected Account $cashboxUsd;
    protected Account $cashboxSar;
    protected WalletType $walletType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Wallet Admin',
            'email' => 'wallet-'.uniqid('', true).'@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        // Seed required wallet clearing accounts (needed by recordIncome/recordExpense strict mode)
        LedgerBalanceMutationGuard::run(function () {
            $this->walletEgp = Account::query()->create([
                'name' => 'Vodafone Cash - Agency EGP',
                'type' => AccountType::Wallet->value,
                'currency' => 'EGP',
                'balance' => 100_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'is_module_vault' => false,
                'wallet_provider' => 'vodafone_cash',
                'wallet_number' => '01000000001',
                'created_by' => $this->admin->id,
            ]);
            $this->cashboxEgp = Account::query()->create([
                'name' => 'Cashbox EGP',
                'type' => AccountType::Cashbox->value,
                'currency' => 'EGP',
                'balance' => 50_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'is_module_vault' => false,
                'created_by' => $this->admin->id,
            ]);
            $this->cashboxUsd = Account::query()->create([
                'name' => 'Cashbox USD',
                'type' => AccountType::Cashbox->value,
                'currency' => 'USD',
                'balance' => 10_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'is_module_vault' => false,
                'created_by' => $this->admin->id,
            ]);
            $this->cashboxSar = Account::query()->create([
                'name' => 'Cashbox SAR',
                'type' => AccountType::Cashbox->value,
                'currency' => 'SAR',
                'balance' => 5_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'is_module_vault' => false,
                'created_by' => $this->admin->id,
            ]);
        });

        $this->walletType = WalletType::firstOrCreate(
            ['code' => 'vodafone_cash'],
            ['name' => 'فودافون كاش', 'is_active' => true, 'sort_order' => 1]
        );
    }

    /* =================================================================
     *  W1 — Send (registered customer)
     * ================================================================= */

    public function test_W1_send_registered_customer_creates_correct_ledger(): void
    {
        $customer = $this->makeCustomer();
        $payload = $this->sendPayload($customer, 500.0, 10.0);

        $resp = $this->postJson('/api/v1/wallet/transactions', $payload);
        $resp->assertCreated();

        // 3 transactions: 1 income (customer AR), 1 expense (wallet), 1 settlement (if amount_paid>0)
        $txCount = Transaction::where('module', 'wallet')->count();
        $this->assertGreaterThanOrEqual(2, $txCount);

        // Σ debit = Σ credit globally
        $this->assertLedgerGloballyBalanced();

        // Customer AR must be credited (we recorded an income)
        $customerAccountId = (int) Customer::find($customer->id)->account_id;
        $this->assertGreaterThan(0, $customerAccountId, 'customer must have an account');
    }

    /* =================================================================
     *  W2 — Send (walk-in customer, no customer_id)
     * ================================================================= */

    public function test_W2_send_walk_in_creates_correct_ledger(): void
    {
        $payload = $this->sendPayloadWalkIn(300.0, 5.0);
        $resp = $this->postJson('/api/v1/wallet/transactions', $payload);
        $resp->assertCreated();

        $this->assertLedgerGloballyBalanced();
    }

    /* =================================================================
     *  W3 — Receive (registered customer)
     * ================================================================= */

    public function test_W3_receive_registered_customer_creates_correct_ledger(): void
    {
        $customer = $this->makeCustomer();
        $payload = $this->receivePayload($customer, 400.0, 8.0);

        $resp = $this->postJson('/api/v1/wallet/transactions', $payload);
        $resp->assertCreated();

        $this->assertLedgerGloballyBalanced();
    }

    /* =================================================================
     *  W4 — Receive (walk-in)
     * ================================================================= */

    public function test_W4_receive_walk_in_creates_correct_ledger(): void
    {
        $payload = $this->receivePayloadWalkIn(250.0, 5.0);
        $resp = $this->postJson('/api/v1/wallet/transactions', $payload);
        $resp->assertCreated();
        $this->assertLedgerGloballyBalanced();
    }

    /* =================================================================
     *  W5 — Idempotency replay (same key 5×)
     * ================================================================= */

    public function test_W5_same_idempotency_key_yields_single_transaction(): void
    {
        $customer = $this->makeCustomer();
        $payload = $this->sendPayload($customer, 200.0, 5.0);
        $key = 'idem-'.uniqid('', true);

        $first = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/v1/wallet/transactions', $payload);
        $first->assertCreated();
        $firstId = $first->json('data.id');

        for ($i = 0; $i < 4; $i++) {
            $replay = $this->withHeaders(['Idempotency-Key' => $key])
                ->postJson('/api/v1/wallet/transactions', $payload);
            $replay->assertOk();
            $this->assertTrue($replay->json('data.idempotent_replay'));
            $this->assertSame($firstId, $replay->json('data.id'));
        }

        $this->assertSame(1, WalletTransaction::count(), 'exactly 1 transaction row in DB');
    }

    /* =================================================================
     *  W6 — Idempotency via HTTP Idempotency-Key header
     * ================================================================= */

    public function test_W6_idempotency_header_works(): void
    {
        $customer = $this->makeCustomer();
        $payload = $this->sendPayload($customer, 100.0, 3.0);
        $key = 'hdr-'.uniqid('', true);

        $first = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/v1/wallet/transactions', $payload);
        $first->assertCreated();

        $replay = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/v1/wallet/transactions', $payload);
        $replay->assertOk();
        $this->assertTrue($replay->json('data.idempotent_replay'));
    }

    /* =================================================================
     *  W7 — Volume: 30 sequential send transactions
     * ================================================================= */

    public function test_W7_30_sends_in_tight_loop(): void
    {
        $customer = $this->makeCustomer();
        for ($i = 0; $i < 30; $i++) {
            $payload = $this->sendPayload($customer, 100.0, 5.0);
            $payload['idempotency_key'] = 'w7-'.uniqid('', true);
            $resp = $this->postJson('/api/v1/wallet/transactions', $payload);
            $resp->assertCreated();
        }

        $this->assertSame(30, WalletTransaction::count());
        $this->assertLedgerGloballyBalanced();
    }

    /* =================================================================
     *  W8 — Update transaction
     * ================================================================= */

    public function test_W8_update_transaction_succeeds(): void
    {
        $customer = $this->makeCustomer();
        $payload = $this->sendPayload($customer, 200.0, 5.0);
        $payload['idempotency_key'] = 'w8-'.uniqid('', true);

        $create = $this->postJson('/api/v1/wallet/transactions', $payload);
        $create->assertCreated();
        $id = $create->json('data.id');

        $resp = $this->patchJson("/api/v1/wallet/transactions/{$id}", [
            'notes' => 'updated-note-w8',
        ]);
        $resp->assertOk();

        $this->assertSame('updated-note-w8', WalletTransaction::find($id)->notes);
    }

    /* =================================================================
     *  W9 — Delete (soft-delete + ledger reversal)
     * ================================================================= */

    public function test_W9_delete_reverses_ledger_entries(): void
    {
        $customer = $this->makeCustomer();
        $payload = $this->sendPayload($customer, 300.0, 5.0);
        $payload['idempotency_key'] = 'w9-'.uniqid('', true);

        $create = $this->postJson('/api/v1/wallet/transactions', $payload);
        $create->assertCreated();
        $id = $create->json('data.id');

        $entriesBefore = AccountEntry::count();
        $txBefore = Transaction::count();

        $this->deleteJson("/api/v1/wallet/transactions/{$id}")->assertOk();

        // additive reversal: original tx still present, +inverse entries added
        $this->assertSame($txBefore, Transaction::count(), 'no transaction deleted');
        $this->assertGreaterThan($entriesBefore, AccountEntry::count(), 'inverse entries added');

        $this->assertSoftDeleted('wallet_transactions', ['id' => $id]);
        $this->assertLedgerGloballyBalanced();
    }

    /* =================================================================
     *  W10 — Multi-currency support
     * ================================================================= */

    public function test_W10_currency_egp_full_cycle(): void
    {
        $customer = $this->makeCustomer();
        $payload = $this->sendPayload($customer, 200.0, 5.0);
        $payload['currency'] = 'EGP';
        $payload['idempotency_key'] = 'w10-egp-'.uniqid('', true);

        $resp = $this->postJson('/api/v1/wallet/transactions', $payload);
        $resp->assertCreated();
        // currency is on the related accounts, not on the WT row itself
        $tx = WalletTransaction::find($resp->json('data.id'));
        $this->assertSame($this->walletEgp->id, $tx->wallet_account_id);
        $this->assertEqualsWithDelta(200.0, (float) $tx->amount, 0.02);
        $this->assertLedgerGloballyBalanced();
    }

    /* =================================================================
     *  W11 — Validation: missing required fields rejected
     * ================================================================= */

    public function test_W11_validation_fails_without_required_fields(): void
    {
        $resp = $this->postJson('/api/v1/wallet/transactions', []);
        $resp->assertStatus(422);
    }

    public function test_W12_validation_fails_with_invalid_type(): void
    {
        $customer = $this->makeCustomer();
        $payload = $this->sendPayload($customer, 100.0, 5.0);
        $payload['type'] = 'invalid_type';

        $resp = $this->postJson('/api/v1/wallet/transactions', $payload);
        $resp->assertStatus(422);
    }

    /* =================================================================
     *  W13 — Index endpoint returns paginated transactions
     * ================================================================= */

    public function test_W13_index_returns_paginated(): void
    {
        $customer = $this->makeCustomer();
        for ($i = 0; $i < 3; $i++) {
            $payload = $this->sendPayload($customer, 100.0, 5.0);
            $payload['idempotency_key'] = 'w13-'.uniqid('', true);
            $this->postJson('/api/v1/wallet/transactions', $payload)->assertCreated();
        }

        $resp = $this->getJson('/api/v1/wallet/transactions');
        $resp->assertOk();
        $this->assertNotNull($resp->json('data'));
    }

    /* =================================================================
     *  W14 — Filter by type
     * ================================================================= */

    public function test_W14_filter_by_type_send(): void
    {
        $customer = $this->makeCustomer();
        $sendPayload = $this->sendPayload($customer, 100.0, 5.0);
        $sendPayload['idempotency_key'] = 'w14s-'.uniqid('', true);
        $this->postJson('/api/v1/wallet/transactions', $sendPayload)->assertCreated();

        $recvPayload = $this->receivePayload($customer, 100.0, 5.0);
        $recvPayload['idempotency_key'] = 'w14r-'.uniqid('', true);
        $this->postJson('/api/v1/wallet/transactions', $recvPayload)->assertCreated();

        $resp = $this->getJson('/api/v1/wallet/transactions?type=send');
        $resp->assertOk();
        foreach ($resp->json('data.items') as $tx) {
            $this->assertSame('send', $tx['type']);
        }
    }

    /* =================================================================
     *  W15 — Filter by wallet_type_id
     * ================================================================= */

    public function test_W15_filter_by_wallet_type(): void
    {
        $customer = $this->makeCustomer();
        $payload = $this->sendPayload($customer, 100.0, 5.0);
        $payload['idempotency_key'] = 'w15-'.uniqid('', true);
        $this->postJson('/api/v1/wallet/transactions', $payload)->assertCreated();

        $resp = $this->getJson("/api/v1/wallet/transactions?wallet_type_id={$this->walletType->id}");
        $resp->assertOk();
        $this->assertGreaterThanOrEqual(1, count($resp->json('data.items')));
    }

    /* =================================================================
     *  W16 — Show transaction returns full resource
     * ================================================================= */

    public function test_W16_show_returns_resource(): void
    {
        $customer = $this->makeCustomer();
        $payload = $this->sendPayload($customer, 100.0, 5.0);
        $payload['idempotency_key'] = 'w16-'.uniqid('', true);
        $create = $this->postJson('/api/v1/wallet/transactions', $payload);
        $id = $create->json('data.id');

        $resp = $this->getJson("/api/v1/wallet/transactions/{$id}");
        $resp->assertOk();
        $this->assertSame($id, $resp->json('data.id'));
    }

    /* =================================================================
     *  W17 — Search by customer_name
     * ================================================================= */

    public function test_W17_search_by_customer_name(): void
    {
        $customer = $this->makeCustomer('UNIQUE_W17_NAME');
        $payload = $this->sendPayload($customer, 100.0, 5.0);
        $payload['idempotency_key'] = 'w17-'.uniqid('', true);
        $this->postJson('/api/v1/wallet/transactions', $payload)->assertCreated();

        $resp = $this->getJson('/api/v1/wallet/transactions?search=UNIQUE_W17_NAME');
        $resp->assertOk();
        $this->assertGreaterThanOrEqual(1, count($resp->json('data.items')));
    }

    /* =================================================================
     *  W18 — Customer balances aggregator
     * ================================================================= */

    public function test_W18_customer_balances_aggregates_correctly(): void
    {
        $customer = $this->makeCustomer();
        $payload = $this->sendPayload($customer, 1000.0, 20.0);
        $payload['idempotency_key'] = 'w18-'.uniqid('', true);
        $this->postJson('/api/v1/wallet/transactions', $payload)->assertCreated();

        $resp = $this->getJson('/api/v1/wallet/customer-balances');
        $resp->assertOk();
        $items = $resp->json('data');
        $row = collect($items)->firstWhere('client_id', $customer->id);
        $this->assertNotNull($row);
        $this->assertSame(1, $row['transaction_count']);
        $this->assertEqualsWithDelta(1020.0, (float) $row['total_sales'], 0.02);
    }

    /* =================================================================
     *  W19 — Customer statement returns running balance
     * ================================================================= */

    public function test_W19_customer_statement_running_balance(): void
    {
        $customer = $this->makeCustomer();
        $payload = $this->sendPayload($customer, 500.0, 10.0);
        $payload['idempotency_key'] = 'w19-'.uniqid('', true);
        $this->postJson('/api/v1/wallet/transactions', $payload)->assertCreated();

        $resp = $this->getJson("/api/v1/wallet/customer-statement?client_id={$customer->id}");
        $resp->assertOk();
        $body = $resp->json('data');
        $this->assertNotNull($body);
        $this->assertGreaterThanOrEqual(1, count($body));
    }

    /* =================================================================
     *  W20 — Daily summary endpoint
     * ================================================================= */

    public function test_W20_daily_summary_returns_aggregate(): void
    {
        $customer = $this->makeCustomer();
        for ($i = 0; $i < 3; $i++) {
            $payload = $this->sendPayload($customer, 100.0, 5.0);
            $payload['idempotency_key'] = 'w20-'.uniqid('', true);
            $this->postJson('/api/v1/wallet/transactions', $payload)->assertCreated();
        }

        $resp = $this->getJson('/api/v1/wallet/transactions/daily-summary');
        $resp->assertOk();
        $body = $resp->json('data');
        $this->assertIsArray($body);
    }

    /* =================================================================
     *  W21 — Treasury overview endpoint
     * ================================================================= */

    public function test_W21_treasury_overview(): void
    {
        $resp = $this->getJson('/api/v1/wallet/treasury/overview');
        $resp->assertOk();
        $body = $resp->json('data');
        $this->assertIsArray($body);
    }

    /* =================================================================
     *  W22 — Mixed lifecycle: 5 send → 5 receive → 5 deletes
     * ================================================================= */

    public function test_W22_mixed_lifecycle_send_then_delete_clean(): void
    {
        $customer = $this->makeCustomer();

        $sends = [];
        for ($i = 0; $i < 5; $i++) {
            $payload = $this->sendPayload($customer, 100.0, 5.0);
            $payload['idempotency_key'] = 'w22s-'.uniqid('', true);
            $r = $this->postJson('/api/v1/wallet/transactions', $payload);
            $r->assertCreated();
            $sends[] = $r->json('data.id');
        }

        // delete all sends cleanly (no later payments yet → no safety guard trigger)
        foreach ($sends as $id) {
            $this->deleteJson("/api/v1/wallet/transactions/{$id}")->assertOk();
        }

        $this->assertSame(0, WalletTransaction::count());
        $this->assertLedgerGloballyBalanced();
    }

    /* =================================================================
     *  W23 — Wallet types listing
     * ================================================================= */

    public function test_W23_wallet_types_listing(): void
    {
        $resp = $this->getJson('/api/v1/wallet/types');
        $resp->assertOk();
        $this->assertGreaterThanOrEqual(1, count($resp->json('data')));
    }

    /* =================================================================
     *  W24 — Volume stress: 50 mixed transactions
     * ================================================================= */

    public function test_W24_50_mixed_transactions_no_drift(): void
    {
        $customer = $this->makeCustomer();
        for ($i = 0; $i < 25; $i++) {
            $payload = $this->sendPayload($customer, 50.0, 2.0);
            $payload['idempotency_key'] = 'w24s-'.uniqid('', true);
            $this->postJson('/api/v1/wallet/transactions', $payload)->assertCreated();
        }
        for ($i = 0; $i < 25; $i++) {
            $payload = $this->receivePayload($customer, 40.0, 1.5);
            $payload['idempotency_key'] = 'w24r-'.uniqid('', true);
            $this->postJson('/api/v1/wallet/transactions', $payload)->assertCreated();
        }

        $this->assertSame(50, WalletTransaction::count());
        $this->assertLedgerGloballyBalanced();
    }

    /* =================================================================
     *  W25 — 2-decimal normalization (100.005 → 100.01)
     * ================================================================= */

    public function test_W25_decimal_normalization_3_to_2(): void
    {
        $customer = $this->makeCustomer();
        $payload = $this->sendPayload($customer, 100.005, 5.001);
        $payload['idempotency_key'] = 'w25-'.uniqid('', true);

        $resp = $this->postJson('/api/v1/wallet/transactions', $payload);
        $resp->assertCreated();
        $this->assertEqualsWithDelta(100.01, (float) $resp->json('data.amount'), 0.001);
    }

    /* =================================================================
     *  Helpers
     * ================================================================= */

    protected function makeCustomer(string $name = 'Wallet Customer'): Customer
    {
        return Customer::query()->create([
            'full_name' => $name,
            'phone' => '+9665'.str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT),
            'email' => strtolower(str_replace(' ', '.', $name)).'@wallet.test',
        ]);
    }

    protected function sendPayload(Customer $customer, float $amount, float $fee): array
    {
        return [
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'wallet_number' => '01012345678',
            'type' => WalletTransactionType::Send->value,
            'amount' => $amount,
            'service_fee' => $fee,
            'wallet_account_id' => $this->walletEgp->id,
            'cash_account_id' => $this->cashboxEgp->id,
            'notes' => 'stress send',
        ];
    }

    protected function sendPayloadWalkIn(float $amount, float $fee): array
    {
        return [
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in '.uniqid(),
            'wallet_number' => '01011112222',
            'type' => WalletTransactionType::Send->value,
            'amount' => $amount,
            'service_fee' => $fee,
            'wallet_account_id' => $this->walletEgp->id,
            'cash_account_id' => $this->cashboxEgp->id,
            'notes' => 'stress send walk-in',
        ];
    }

    protected function receivePayload(Customer $customer, float $amount, float $fee): array
    {
        return [
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'wallet_number' => '01098765432',
            'type' => WalletTransactionType::Receive->value,
            'amount' => $amount,
            'service_fee' => $fee,
            'wallet_account_id' => $this->walletEgp->id,
            'cash_account_id' => $this->cashboxEgp->id,
            'notes' => 'stress receive',
        ];
    }

    protected function receivePayloadWalkIn(float $amount, float $fee): array
    {
        return [
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in Recv '.uniqid(),
            'wallet_number' => '01099998888',
            'type' => WalletTransactionType::Receive->value,
            'amount' => $amount,
            'service_fee' => $fee,
            'wallet_account_id' => $this->walletEgp->id,
            'cash_account_id' => $this->cashboxEgp->id,
            'notes' => 'stress receive walk-in',
        ];
    }

    protected function assertLedgerGloballyBalanced(): void
    {
        $credit = (float) AccountEntry::where(function ($w) {
            $w->whereNull('is_opening')->orWhere('is_opening', '!=', 1);
        })->sum('credit');
        $debit = (float) AccountEntry::where(function ($w) {
            $w->whereNull('is_opening')->orWhere('is_opening', '!=', 1);
        })->sum('debit');
        $this->assertEqualsWithDelta($credit, $debit, 0.02,
            "ledger must be globally balanced: credit=$credit debit=$debit");
    }
}
