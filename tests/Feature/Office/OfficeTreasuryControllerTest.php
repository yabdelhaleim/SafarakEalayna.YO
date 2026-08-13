<?php

namespace Tests\Feature\Office;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Feature tests for the new OfficeTreasuryController::accountTransactions
 * endpoint, added alongside the existing bus treasury endpoint.
 *
 * Backward-compatibility is checked at the bottom: the old bus endpoint
 * must keep its original behaviour (module=bus filter, same response
 * envelope, no envelope shape changes).
 */
class OfficeTreasuryControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Account $officeCashbox;

    protected Account $tourismCashbox;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Office Treasury Tester',
            'email' => 'office-treasury-test@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Sanctum::actingAs($this->user, ['*']);

        // Office-division unified cashbox (the case the new endpoint
        // exists to serve).
        $this->officeCashbox = Account::query()->create([
            'name' => 'خزينة مكتب موحدة',
            'type' => AccountType::Cashbox,
            'balance' => 1000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
        ]);

        // Tourism-division account — must be REJECTED by the new endpoint.
        $this->tourismCashbox = Account::query()->create([
            'name' => 'خزينة طيران',
            'type' => AccountType::Cashbox,
            'balance' => 500,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'module' => 'flight',
        ]);
    }

    public function test_office_account_returns_all_transactions_across_modules(): void
    {
        // Seed one transaction per office module.
        $targets = [
            ['type' => 'income',   'module' => 'bus',     'amount' => 100, 'notes' => 'bus booking'],
            ['type' => 'expense',  'module' => 'fawry',   'amount' => 200, 'notes' => 'fawry payout'],
            ['type' => 'income',   'module' => 'online',  'amount' => 300, 'notes' => 'online topup'],
            ['type' => 'transfer', 'module' => 'general', 'amount' => 400, 'notes' => 'general transfer'],
        ];

        foreach ($targets as $t) {
            Transaction::query()->create(array_merge($t, [
                'from_account_id' => $this->tourismCashbox->id, // some other account
                'to_account_id'   => $this->officeCashbox->id,
                'created_by'      => $this->user->id,
            ]));
        }

        $response = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions");

        $response->assertOk();
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'data' => [['id', 'type', 'module', 'amount', 'from_account_id', 'to_account_id']],
                'current_page',
                'last_page',
                'per_page',
                'total',
            ],
            'errors',
        ]);
        $this->assertTrue($response->json('success'));
        $this->assertCount(4, $response->json('data.data'));
        $this->assertEquals(4, $response->json('data.total'));
    }

    public function test_tourism_account_is_rejected(): void
    {
        $response = $this->getJson("/api/v1/office/treasury/accounts/{$this->tourismCashbox->id}/transactions");

        $response->assertStatus(422);
        $this->assertFalse($response->json('success'));
    }

    public function test_inactive_office_account_is_rejected(): void
    {
        $this->officeCashbox->update(['is_active' => false]);

        $response = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions");

        $response->assertStatus(422);
    }

    public function test_customer_account_is_rejected(): void
    {
        $customer = Account::query()->create([
            'name' => 'عميل اختبار',
            'type' => AccountType::Customer,
            'balance' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'bus',  // valid specific module for customer
        ]);

        $response = $this->getJson("/api/v1/office/treasury/accounts/{$customer->id}/transactions");

        $response->assertStatus(422);
    }

    public function test_module_filter_is_applied(): void
    {
        foreach ([
            ['module' => 'bus',    'amount' => 100],
            ['module' => 'fawry',  'amount' => 200],
            ['module' => 'online', 'amount' => 300],
        ] as $t) {
            Transaction::query()->create(array_merge($t, [
                'type' => 'income',
                'from_account_id' => $this->tourismCashbox->id,
                'to_account_id'   => $this->officeCashbox->id,
                'created_by'      => $this->user->id,
                'notes' => "module={$t['module']}",
            ]));
        }

        $response = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions?module=fawry");
        $response->assertOk();
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals('fawry', $response->json('data.data.0.module'));
    }

    public function test_invalid_module_returns_422(): void
    {
        $response = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions?module=foobar");
        $response->assertStatus(422);
    }

    public function test_wallet_transfer_module_filter_accepted_returns_empty(): void
    {
        // 'wallet_transfer' is a valid AccountModuleContract value (used as
        // a module_type on accounts) but it's NOT a TransactionModule enum
        // case and is never written to transactions.module. The controller
        // accepts the filter (forward-compat) but the result is empty
        // because no row matches.
        $response = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions?module=wallet_transfer");
        $response->assertOk();
        $this->assertEquals(0, $response->json('data.total'));
    }

    public function test_type_filter_is_applied(): void
    {
        foreach ([
            ['type' => 'income',  'amount' => 100],
            ['type' => 'expense', 'amount' => 200],
        ] as $t) {
            Transaction::query()->create(array_merge($t, [
                'module' => 'bus',
                'from_account_id' => $this->tourismCashbox->id,
                'to_account_id'   => $this->officeCashbox->id,
                'created_by'      => $this->user->id,
            ]));
        }

        $response = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions?type=expense");
        $response->assertOk();
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals('expense', $response->json('data.data.0.type'));
    }

    public function test_invalid_type_returns_422(): void
    {
        $response = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions?type=foobar");
        $response->assertStatus(422);
    }

    public function test_from_date_to_date_filters(): void
    {
        // created_at is NOT in Transaction::$fillable, so we have to
        // explicitly set it after creation.
        $old = Transaction::query()->create([
            'type' => 'income',
            'module' => 'bus',
            'amount' => 100,
            'from_account_id' => $this->tourismCashbox->id,
            'to_account_id'   => $this->officeCashbox->id,
            'created_by'      => $this->user->id,
        ]);
        $old->forceFill(['created_at' => '2025-01-01 00:00:00'])->save();

        $new = Transaction::query()->create([
            'type' => 'income',
            'module' => 'bus',
            'amount' => 200,
            'from_account_id' => $this->tourismCashbox->id,
            'to_account_id'   => $this->officeCashbox->id,
            'created_by'      => $this->user->id,
        ]);
        $new->forceFill(['created_at' => '2026-06-15 00:00:00'])->save();

        $response = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions?from_date=2026-01-01&to_date=2026-12-31");
        $response->assertOk();
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals($new->id, $response->json('data.data.0.id'));
    }

    public function test_invalid_date_format_returns_422(): void
    {
        $response = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions?from_date=not-a-date");
        $response->assertStatus(422);
    }

    public function test_to_date_before_from_date_returns_422(): void
    {
        $response = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions?from_date=2026-12-01&to_date=2026-01-01");
        $response->assertStatus(422);
    }

    public function test_per_page_clamped_to_100(): void
    {
        $response = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions?per_page=999");
        $response->assertOk();
        $this->assertEquals(100, $response->json('data.per_page'));
    }

    public function test_per_page_zero_returns_422(): void
    {
        $response = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions?per_page=0");
        $response->assertStatus(422);
    }

    public function test_per_page_negative_returns_422(): void
    {
        $response = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions?per_page=-1");
        $response->assertStatus(422);
    }

    public function test_pagination_works(): void
    {
        for ($i = 0; $i < 7; $i++) {
            Transaction::query()->create([
                'type' => 'income',
                'module' => 'bus',
                'amount' => 10 * ($i + 1),
                'from_account_id' => $this->tourismCashbox->id,
                'to_account_id'   => $this->officeCashbox->id,
                'created_by'      => $this->user->id,
            ]);
        }

        $r1 = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions?per_page=3&page=1");
        $r1->assertOk();
        $this->assertEquals(3, count($r1->json('data.data')));
        $this->assertEquals(7, $r1->json('data.total'));
        $this->assertEquals(3, $r1->json('data.last_page'));

        $r2 = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions?per_page=3&page=3");
        $r2->assertOk();
        $this->assertEquals(1, count($r2->json('data.data')));
    }

    public function test_empty_result_has_correct_envelope(): void
    {
        $response = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions");
        $response->assertOk();
        $this->assertTrue($response->json('success'));
        $this->assertEquals([], $response->json('data.data'));
        $this->assertEquals(0, $response->json('data.total'));
    }

    public function test_unauthenticated_request_returns_401(): void
    {
        // Drop the sanctum auth we set up in setUp.
        auth()->forgetGuards();

        $response = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions");
        $response->assertStatus(401);
    }

    public function test_module_filter_office_works(): void
    {
        Transaction::query()->create([
            'type' => 'income',
            'module' => 'office',
            'amount' => 500,
            'from_account_id' => $this->tourismCashbox->id,
            'to_account_id'   => $this->officeCashbox->id,
            'created_by'      => $this->user->id,
        ]);
        Transaction::query()->create([
            'type' => 'income',
            'module' => 'bus',
            'amount' => 200,
            'from_account_id' => $this->tourismCashbox->id,
            'to_account_id'   => $this->officeCashbox->id,
            'created_by'      => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions?module=office");
        $response->assertOk();
        $this->assertEquals(1, $response->json('data.total'));
    }

    public function test_module_filter_all_sentinel_means_no_filter(): void
    {
        foreach ([
            ['module' => 'bus',   'amount' => 100],
            ['module' => 'fawry', 'amount' => 200],
        ] as $t) {
            Transaction::query()->create(array_merge($t, [
                'type' => 'income',
                'from_account_id' => $this->tourismCashbox->id,
                'to_account_id'   => $this->officeCashbox->id,
                'created_by'      => $this->user->id,
            ]));
        }

        $response = $this->getJson("/api/v1/office/treasury/accounts/{$this->officeCashbox->id}/transactions?module=all");
        $response->assertOk();
        $this->assertEquals(2, $response->json('data.total'));
    }

    // ─── BACKWARD COMPATIBILITY — bus endpoint must not change ──────────

    public function test_bus_endpoint_still_filters_by_module_bus(): void
    {
        $this->busCashbox = Account::query()->create([
            'name' => 'خزينة باص',
            'type' => AccountType::Cashbox,
            'balance' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
        ]);

        // One bus tx, one fawry tx — the bus endpoint must return only the bus tx.
        $busTx = Transaction::query()->create([
            'type' => 'income',
            'module' => 'bus',
            'amount' => 100,
            'from_account_id' => $this->tourismCashbox->id,
            'to_account_id'   => $this->busCashbox->id,
            'created_by'      => $this->user->id,
        ]);
        Transaction::query()->create([
            'type' => 'income',
            'module' => 'fawry',
            'amount' => 200,
            'from_account_id' => $this->tourismCashbox->id,
            'to_account_id'   => $this->busCashbox->id,
            'created_by'      => $this->user->id,
        ]);

        $response = $this->getJson("/api/v1/bus/treasury/accounts/{$this->busCashbox->id}/bus-transactions");
        $response->assertOk();
        $this->assertEquals(1, $response->json('data.total'));
        $this->assertEquals('bus', $response->json('data.data.0.module'));
        $this->assertEquals($busTx->id, $response->json('data.data.0.id'));
    }

    public function test_bus_endpoint_envelope_unchanged(): void
    {
        $this->busCashbox = Account::query()->create([
            'name' => 'خزينة باص',
            'type' => AccountType::Cashbox,
            'balance' => 0,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
        ]);

        $response = $this->getJson("/api/v1/bus/treasury/accounts/{$this->busCashbox->id}/bus-transactions");
        $response->assertOk();
        // Verify the standard paginated envelope (Laravel default + ApiResponse)
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => ['data', 'current_page', 'last_page', 'per_page', 'total'],
            'errors',
        ]);
    }
}
