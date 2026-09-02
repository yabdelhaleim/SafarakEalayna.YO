<?php

namespace Tests\Feature\Wallet;

use App\Enums\AccountType;
use App\Enums\WalletTransactionType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use App\Models\Wallet\WalletType;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Wallet & Transfers — Frontend HTTP E2E Test
 *
 * Date: 2026-08-29
 * Purpose: Verify the API contracts consumed by the Wallet Vue pages
 *          (Index, Create, Show, CustomerBalances) + Pinia store actions.
 */
class WalletFrontendE2ETest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Account $walletEgp;
    protected Account $cashboxEgp;
    protected WalletType $walletType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'FE Wallet Admin',
            'email' => 'few-'.uniqid('', true).'@test.local',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        LedgerBalanceMutationGuard::run(function () {
            $this->walletEgp = Account::query()->create([
                'name' => 'Wallet EGP',
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
        });

        $this->walletType = WalletType::firstOrCreate(
            ['code' => 'vodafone_cash'],
            ['name' => 'فودافون كاش', 'is_active' => true, 'sort_order' => 1]
        );
    }

    /* =========================================================
     *  WalletIndex.vue — list + filters + pagination
     * ========================================================= */

    public function test_FE_01_index_returns_paginated(): void
    {
        $customer = $this->makeCustomer();
        for ($i = 0; $i < 3; $i++) {
            $this->createSend($customer, 100.0, 5.0);
        }

        $resp = $this->getJson('/api/v1/wallet/transactions');
        $resp->assertOk();
        $body = $resp->json('data');
        $this->assertArrayHasKey('items', $body);
        $this->assertGreaterThanOrEqual(3, count($body['items']));
    }

    public function test_FE_02_filter_by_type(): void
    {
        $customer = $this->makeCustomer();
        $this->createSend($customer, 100.0, 5.0);
        $this->createReceive($customer, 100.0, 5.0, 0.0);

        $resp = $this->getJson('/api/v1/wallet/transactions?type=send');
        $resp->assertOk();
        foreach ($resp->json('data.items') as $tx) {
            $this->assertSame('send', $tx['type']);
        }
    }

    public function test_FE_03_search_by_customer_name(): void
    {
        $customer = $this->makeCustomer('UNIQUE_FE_NAME');
        $this->createSend($customer, 100.0, 5.0);

        $resp = $this->getJson('/api/v1/wallet/transactions?search=UNIQUE_FE_NAME');
        $resp->assertOk();
        $this->assertGreaterThanOrEqual(1, count($resp->json('data.items')));
    }

    /* =========================================================
     *  WalletCreate.vue — POST send/receive
     * ========================================================= */

    public function test_FE_04_create_send_returns_201_with_resource(): void
    {
        $customer = $this->makeCustomer();
        $resp = $this->postJson('/api/v1/wallet/transactions', [
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'wallet_number' => '01012345678',
            'type' => WalletTransactionType::Send->value,
            'amount' => 500.0,
            'service_fee' => 10.0,
            'wallet_account_id' => $this->walletEgp->id,
            'cash_account_id' => $this->cashboxEgp->id,
            'notes' => 'fe-test',
        ]);
        $resp->assertCreated();
        $body = $resp->json('data');
        $this->assertSame('send', $body['type']);
        $this->assertEqualsWithDelta(500.0, (float) $body['amount'], 0.01);
        $this->assertEqualsWithDelta(510.0, (float) $body['total_amount'], 0.01);
        $this->assertSame($customer->full_name, $body['customer_name']);
    }

    public function test_FE_05_create_receive_returns_201(): void
    {
        $customer = $this->makeCustomer();
        $resp = $this->postJson('/api/v1/wallet/transactions', [
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'wallet_number' => '01098765432',
            'type' => WalletTransactionType::Receive->value,
            'amount' => 300.0,
            'service_fee' => 8.0,
            'amount_paid' => 0.0,
            'wallet_account_id' => $this->walletEgp->id,
            'cash_account_id' => $this->cashboxEgp->id,
            'notes' => 'fe-receive',
        ]);
        $resp->assertCreated();
        $body = $resp->json('data');
        $this->assertSame('receive', $body['type']);
    }

    public function test_FE_06_walk_in_send(): void
    {
        $resp = $this->postJson('/api/v1/wallet/transactions', [
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => null,
            'customer_name' => 'Walk-in',
            'wallet_number' => '01000000099',
            'type' => WalletTransactionType::Send->value,
            'amount' => 100.0,
            'service_fee' => 3.0,
            'wallet_account_id' => $this->walletEgp->id,
            'cash_account_id' => $this->cashboxEgp->id,
            'notes' => 'walk-in',
        ]);
        $resp->assertCreated();
    }

    /* =========================================================
     *  WalletShow.vue — show + update + delete
     * ========================================================= */

    public function test_FE_07_show_returns_full_resource(): void
    {
        $customer = $this->makeCustomer();
        $tx = $this->createSend($customer, 100.0, 5.0);

        $resp = $this->getJson("/api/v1/wallet/transactions/{$tx->id}");
        $resp->assertOk();
        $body = $resp->json('data');
        $this->assertSame($tx->id, $body['id']);
        $this->assertSame('send', $body['type']);
    }

    public function test_FE_08_update_transaction(): void
    {
        $customer = $this->makeCustomer();
        $tx = $this->createSend($customer, 100.0, 5.0);

        $resp = $this->patchJson("/api/v1/wallet/transactions/{$tx->id}", [
            'notes' => 'updated-by-fe-test',
        ]);
        $resp->assertOk();
        $this->assertSame('updated-by-fe-test', $resp->json('data.notes'));
    }

    public function test_FE_09_delete_returns_200(): void
    {
        $customer = $this->makeCustomer();
        $tx = $this->createSend($customer, 100.0, 5.0);

        $resp = $this->deleteJson("/api/v1/wallet/transactions/{$tx->id}");
        $resp->assertOk();
        $this->assertSoftDeleted('wallet_transactions', ['id' => $tx->id]);
    }

    /* =========================================================
     *  WalletCustomerBalances.vue
     * ========================================================= */

    public function test_FE_10_customer_balances_returns_aggregates(): void
    {
        $customer = $this->makeCustomer();
        $this->createSend($customer, 1000.0, 20.0);

        $resp = $this->getJson('/api/v1/wallet/customer-balances');
        $resp->assertOk();
        $items = $resp->json('data');
        $row = collect($items)->firstWhere('client_id', $customer->id);
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(1020.0, (float) $row['total_sales'], 0.02);
    }

    public function test_FE_11_customer_balances_debtors_filter(): void
    {
        $customer = $this->makeCustomer();
        $this->createSend($customer, 500.0, 10.0);

        $resp = $this->getJson('/api/v1/wallet/customer-balances?status=debtors');
        $resp->assertOk();
        foreach ($resp->json('data') as $row) {
            $this->assertGreaterThan(0.009, (float) $row['total_debt']);
        }
    }

    /* =========================================================
     *  Customer statement
     * ========================================================= */

    public function test_FE_12_customer_statement(): void
    {
        $customer = $this->makeCustomer();
        $this->createSend($customer, 500.0, 10.0);

        $resp = $this->getJson("/api/v1/wallet/customer-statement?client_id={$customer->id}");
        $resp->assertOk();
        $body = $resp->json('data');
        $this->assertGreaterThanOrEqual(1, count($body));
    }

    /* =========================================================
     *  Daily summary + treasury overview
     * ========================================================= */

    public function test_FE_13_daily_summary(): void
    {
        $customer = $this->makeCustomer();
        $this->createSend($customer, 200.0, 5.0);

        $resp = $this->getJson('/api/v1/wallet/transactions/daily-summary');
        $resp->assertOk();
        $this->assertIsArray($resp->json('data'));
    }

    public function test_FE_14_treasury_overview(): void
    {
        $resp = $this->getJson('/api/v1/wallet/treasury/overview');
        $resp->assertOk();
        $this->assertIsArray($resp->json('data'));
    }

    /* =========================================================
     *  Wallet types
     * ========================================================= */

    public function test_FE_15_wallet_types_list(): void
    {
        $resp = $this->getJson('/api/v1/wallet/types');
        $resp->assertOk();
        $this->assertIsArray($resp->json('data'));
        $this->assertGreaterThanOrEqual(1, count($resp->json('data')));
    }

    /* =========================================================
     *  Idempotency via header
     * ========================================================= */

    public function test_FE_16_idempotency_header_replay(): void
    {
        $customer = $this->makeCustomer();
        $payload = [
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'wallet_number' => '01012345678',
            'type' => WalletTransactionType::Send->value,
            'amount' => 250.0,
            'service_fee' => 5.0,
            'wallet_account_id' => $this->walletEgp->id,
            'cash_account_id' => $this->cashboxEgp->id,
            'notes' => 'idem-fe',
        ];

        $key = 'feidem-'.uniqid('', true);
        $first = $this->withHeaders(['Idempotency-Key' => $key])->postJson('/api/v1/wallet/transactions', $payload);
        $first->assertCreated();
        $firstId = $first->json('data.id');

        $replay = $this->withHeaders(['Idempotency-Key' => $key])->postJson('/api/v1/wallet/transactions', $payload);
        $replay->assertOk();
        $this->assertTrue($replay->json('data.idempotent_replay'));
        $this->assertSame($firstId, $replay->json('data.id'));
    }

    /* =========================================================
     *  Validation
     * ========================================================= */

    public function test_FE_17_validation_missing_required(): void
    {
        $resp = $this->postJson('/api/v1/wallet/transactions', []);
        $resp->assertStatus(422);
    }

    public function test_FE_18_validation_amount_min(): void
    {
        $customer = $this->makeCustomer();
        $resp = $this->postJson('/api/v1/wallet/transactions', [
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'wallet_number' => '01012345678',
            'type' => WalletTransactionType::Send->value,
            'amount' => 0.50,  // below min of 1.00
            'service_fee' => 5.0,
            'wallet_account_id' => $this->walletEgp->id,
            'cash_account_id' => $this->cashboxEgp->id,
        ]);
        $resp->assertStatus(422);
    }

    public function test_FE_19_validation_cross_currency_rejected(): void
    {
        $customer = $this->makeCustomer();
        // wallet = EGP, cashbox = USD → should be rejected (VAL-1)
        $usdCashbox = LedgerBalanceMutationGuard::run(function () {
            return Account::query()->create([
                'name' => 'Cashbox USD',
                'type' => AccountType::Cashbox->value,
                'currency' => 'USD',
                'balance' => 100.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'office',
                'is_module_vault' => false,
                'created_by' => $this->admin->id,
            ]);
        });

        $resp = $this->postJson('/api/v1/wallet/transactions', [
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'wallet_number' => '01012345678',
            'type' => WalletTransactionType::Send->value,
            'amount' => 100.0,
            'service_fee' => 5.0,
            'wallet_account_id' => $this->walletEgp->id,
            'cash_account_id' => $usdCashbox->id,
        ]);
        $resp->assertStatus(422);
    }

    /* =========================================================
     *  Happy-user full flow
     * ========================================================= */

    public function test_FE_20_full_user_flow_send_show_update_delete(): void
    {
        $customer = $this->makeCustomer();

        // step 1: create send
        $create = $this->postJson('/api/v1/wallet/transactions', [
            'wallet_type_id' => $this->walletType->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'wallet_number' => '01012345678',
            'type' => WalletTransactionType::Send->value,
            'amount' => 1000.0,
            'service_fee' => 20.0,
            'wallet_account_id' => $this->walletEgp->id,
            'cash_account_id' => $this->cashboxEgp->id,
            'notes' => 'happy-flow',
        ]);
        $create->assertCreated();
        $id = $create->json('data.id');

        // step 2: list
        $this->getJson('/api/v1/wallet/transactions')->assertOk();

        // step 3: show
        $this->getJson("/api/v1/wallet/transactions/{$id}")->assertOk();

        // step 4: update
        $this->patchJson("/api/v1/wallet/transactions/{$id}", ['notes' => 'updated'])->assertOk();

        // step 5: customer balances
        $cb = $this->getJson('/api/v1/wallet/customer-balances')->json('data');
        $row = collect($cb)->firstWhere('client_id', $customer->id);
        $this->assertNotNull($row);

        // step 6: customer statement
        $this->getJson("/api/v1/wallet/customer-statement?client_id={$customer->id}")->assertOk();

        // step 7: delete
        $this->deleteJson("/api/v1/wallet/transactions/{$id}")->assertOk();

        // step 8: customer balances — debt cleared
        $cbAfter = $this->getJson('/api/v1/wallet/customer-balances')->json('data');
        $rowAfter = collect($cbAfter)->firstWhere('client_id', $customer->id);
        if ($rowAfter !== null) {
            $this->assertEqualsWithDelta(0.0, (float) $rowAfter['total_debt'], 0.02);
        }
    }

    /* =========================================================
     *  Helpers
     * ========================================================= */

    protected function makeCustomer(string $name = 'FE Wallet Cust'): Customer
    {
        return Customer::query()->create([
            'full_name' => $name,
            'phone' => '+9665'.str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT),
        ]);
    }

    protected function createSend(Customer $customer, float $amount, float $fee)
    {
        $resp = $this->withHeaders(['Idempotency-Key' => 's-'.uniqid('', true)])
            ->postJson('/api/v1/wallet/transactions', [
                'wallet_type_id' => $this->walletType->id,
                'customer_id' => $customer->id,
                'customer_name' => $customer->full_name,
                'wallet_number' => '01012345678',
                'type' => WalletTransactionType::Send->value,
                'amount' => $amount,
                'service_fee' => $fee,
                'wallet_account_id' => $this->walletEgp->id,
                'cash_account_id' => $this->cashboxEgp->id,
                'notes' => 'send',
            ]);
        $resp->assertCreated();
        return \App\Models\Wallet\WalletTransaction::findOrFail($resp->json('data.id'));
    }

    protected function createReceive(Customer $customer, float $amount, float $fee, float $amountPaid = 0.0)
    {
        $resp = $this->withHeaders(['Idempotency-Key' => 'r-'.uniqid('', true)])
            ->postJson('/api/v1/wallet/transactions', [
                'wallet_type_id' => $this->walletType->id,
                'customer_id' => $customer->id,
                'customer_name' => $customer->full_name,
                'wallet_number' => '01098765432',
                'type' => WalletTransactionType::Receive->value,
                'amount' => $amount,
                'service_fee' => $fee,
                'amount_paid' => $amountPaid,
                'wallet_account_id' => $this->walletEgp->id,
                'cash_account_id' => $this->cashboxEgp->id,
                'notes' => 'receive',
            ]);
        $resp->assertCreated();
        return \App\Models\Wallet\WalletTransaction::findOrFail($resp->json('data.id'));
    }
}
