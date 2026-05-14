<?php

namespace Tests\Feature\Wallet;

use App\Enums\AccountType;
use App\Enums\WalletTransactionType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use App\Models\Wallet\WalletTransaction;
use App\Models\Wallet\WalletType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletTransactionCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Account $walletAccount;
    protected Account $cashAccount;
    protected Customer $customer;
    protected WalletType $walletType;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->walletAccount = Account::factory()->create([
            'type'    => AccountType::Wallet->value,
            'balance' => 10000,
            'name'    => 'فودافون كاش - الوكالة',
        ]);

        $this->cashAccount = Account::factory()->create([
            'type'    => AccountType::Cashbox->value,
            'balance' => 5000,
            'name'    => 'خزينة رئيسية',
        ]);

        $this->customer = Customer::factory()->create([
            'full_name' => 'أحمد محمود',
        ]);

        $this->walletType = WalletType::create([
            'name'       => 'فودافون كاش',
            'code'       => 'vodafone_cash',
            'is_active'  => true,
            'sort_order' => 1,
        ]);
    }

    // ────────────── Wallet Types ──────────────

    public function test_can_list_wallet_types(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/wallet/types');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status', 'message',
                'data' => [['id', 'name', 'code', 'is_active']],
            ]);
    }

    public function test_wallet_types_active_only_filter(): void
    {
        WalletType::create(['name' => 'معطل', 'code' => 'disabled', 'is_active' => false, 'sort_order' => 99]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/wallet/types?active_only=1');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);
        foreach ($data as $item) {
            $this->assertTrue($item['is_active']);
        }
    }

    // ────────────── Send Transaction ──────────────

    public function test_can_create_send_transaction(): void
    {
        $payload = $this->sendPayload();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/wallet/transactions', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'status', 'message',
                'data' => ['id', 'type', 'type_label', 'amount', 'service_fee', 'total_amount'],
            ]);

        $data = $response->json('data');
        $this->assertEquals('send', $data['type']);
        $this->assertEquals(500.00,  (float) $data['amount']);
        $this->assertEquals(10.00,   (float) $data['service_fee']);
        $this->assertEquals(510.00,  (float) $data['total_amount']);
        $this->assertEquals('أحمد محمود', $data['customer_name']);
    }

    public function test_send_updates_accounts_correctly(): void
    {
        $payload = $this->sendPayload(amount: 500, fee: 10);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/wallet/transactions', $payload);

        // wallet balance decreases by amount (500)
        $this->assertDatabaseHas('accounts', [
            'id'      => $this->walletAccount->id,
            'balance' => 10000 - 500,
        ]);

        // cash balance increases by amount+fee (510)
        $this->assertDatabaseHas('accounts', [
            'id'      => $this->cashAccount->id,
            'balance' => 5000 + 510,
        ]);
    }

    // ────────────── Receive Transaction ──────────────

    public function test_can_create_receive_transaction(): void
    {
        $payload = $this->receivePayload();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/wallet/transactions', $payload);

        $response->assertStatus(201);

        $data = $response->json('data');
        $this->assertEquals('receive',  $data['type']);
        $this->assertEquals(300.00,    (float) $data['amount']);
        $this->assertEquals(8.00,      (float) $data['service_fee']);
        $this->assertEquals(292.00,    (float) $data['total_amount']);
    }

    public function test_receive_updates_accounts_correctly(): void
    {
        $payload = $this->receivePayload(amount: 300, fee: 8);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/wallet/transactions', $payload);

        // wallet balance increases by amount (300)
        $this->assertDatabaseHas('accounts', [
            'id'      => $this->walletAccount->id,
            'balance' => 10000 + 300,
        ]);

        // cash balance decreases by (amount - fee) = 292
        $this->assertDatabaseHas('accounts', [
            'id'      => $this->cashAccount->id,
            'balance' => 5000 - 292,
        ]);
    }

    // ────────────── List & Show ──────────────

    public function test_can_list_transactions(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/wallet/transactions', $this->sendPayload());
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/wallet/transactions', $this->receivePayload());

        $response = $this->actingAs($this->user, 'sanctum')->getJson('/api/v1/wallet/transactions');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => [
                    'items',
                    'pagination' => ['total', 'per_page', 'current_page', 'last_page', 'has_more'],
                ],
            ]);
    }

    public function test_can_filter_by_type(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/wallet/transactions', $this->sendPayload());
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/wallet/transactions', $this->receivePayload());

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v1/wallet/transactions?type=send');

        $response->assertStatus(200);
        $items = $response->json('data.items') ?? $response->json('data');
        if (is_array($items)) {
            foreach ($items as $item) {
                $this->assertEquals('send', $item['type']);
            }
        }
    }

    public function test_can_show_transaction(): void
    {
        $createResp = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/wallet/transactions', $this->sendPayload());

        $id = $createResp->json('data.id');

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/wallet/transactions/{$id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.type', 'send');
    }

    // ────────────── Update ──────────────

    public function test_can_update_transaction_notes(): void
    {
        $createResp = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/wallet/transactions', $this->sendPayload());

        $id = $createResp->json('data.id');

        $response = $this->actingAs($this->user, 'sanctum')
            ->putJson("/api/v1/wallet/transactions/{$id}", [
                'notes' => 'ملاحظة محدثة',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('wallet_transactions', [
            'id'    => $id,
            'notes' => 'ملاحظة محدثة',
        ]);
    }

    // ────────────── Delete ──────────────

    public function test_can_delete_transaction_and_reverses_accounting(): void
    {
        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/wallet/transactions', $this->sendPayload(amount: 200, fee: 5));

        $walletAfterCreate = Account::find($this->walletAccount->id)->balance;
        $cashAfterCreate   = Account::find($this->cashAccount->id)->balance;

        $id = WalletTransaction::first()->id;

        $response = $this->actingAs($this->user, 'sanctum')
            ->deleteJson("/api/v1/wallet/transactions/{$id}");

        $response->assertStatus(200);

        $this->assertSoftDeleted('wallet_transactions', ['id' => $id]);

        // Balances should be reversed back
        $this->assertDatabaseHas('accounts', [
            'id'      => $this->walletAccount->id,
            'balance' => $walletAfterCreate + 200, // reversed: wallet goes back up
        ]);
        $this->assertDatabaseHas('accounts', [
            'id'      => $this->cashAccount->id,
            'balance' => $cashAfterCreate - 205, // reversed: cash goes back down
        ]);
    }

    // ────────────── Daily Summary ──────────────

    public function test_daily_summary(): void
    {
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/wallet/transactions', $this->sendPayload(amount: 500, fee: 10));
        $this->actingAs($this->user, 'sanctum')->postJson('/api/v1/wallet/transactions', $this->receivePayload(amount: 300, fee: 8));

        $today    = now()->toDateString();
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson("/api/v1/wallet/transactions/daily-summary?date={$today}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['total_transactions', 'send_count', 'receive_count', 'total_sent', 'total_received', 'total_fees'],
            ]);

        $this->assertEquals(2, $response->json('data.total_transactions'));
        $this->assertEquals(1, $response->json('data.send_count'));
        $this->assertEquals(1, $response->json('data.receive_count'));
    }

    // ────────────── Validation ──────────────

    public function test_validation_fails_without_required_fields(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/wallet/transactions', []);

        $response->assertStatus(422);
    }

    public function test_validation_fails_with_invalid_type(): void
    {
        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v1/wallet/transactions', array_merge(
                $this->sendPayload(),
                ['type' => 'invalid_type']
            ));

        $response->assertStatus(422);
    }

    // ────────────── Helpers ──────────────

    private function sendPayload(float $amount = 500, float $fee = 10): array
    {
        return [
            'wallet_type_id'    => $this->walletType->id,
            'customer_id'       => $this->customer->id,
            'customer_name'     => $this->customer->full_name,
            'wallet_number'     => '01012345678',
            'type'              => 'send',
            'amount'            => $amount,
            'service_fee'       => $fee,
            'wallet_account_id' => $this->walletAccount->id,
            'cash_account_id'   => $this->cashAccount->id,
            'notes'             => 'تيست إرسال رصيد',
        ];
    }

    private function receivePayload(float $amount = 300, float $fee = 8): array
    {
        return [
            'wallet_type_id'    => $this->walletType->id,
            'customer_id'       => $this->customer->id,
            'customer_name'     => $this->customer->full_name,
            'wallet_number'     => '01098765432',
            'type'              => 'receive',
            'amount'            => $amount,
            'service_fee'       => $fee,
            'wallet_account_id' => $this->walletAccount->id,
            'cash_account_id'   => $this->cashAccount->id,
            'notes'             => 'تيست استقبال رصيد',
        ];
    }
}
