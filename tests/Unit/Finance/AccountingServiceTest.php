<?php

namespace Tests\Unit\Finance;

use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\User;
use App\Services\Finance\AccountingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AccountingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Account $account1;
    protected Account $account2;
    protected AccountingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Accounting Tester',
            'email' => 'accounting-tester@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->account1 = Account::query()->create([
            'name' => 'Cash Account 1',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 1000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'bus',
            'created_by' => $this->user->id,
        ]);

        $this->account2 = Account::query()->create([
            'name' => 'Cash Account 2',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 500.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'bus',
            'created_by' => $this->user->id,
        ]);

        $this->service = app(AccountingService::class);
        Sanctum::actingAs($this->user, ['*']);
    }

    /**
     * Test posting a balanced journal updates account balances correctly.
     */
    public function test_post_balanced_journal_updates_balances(): void
    {
        // Transfer 200 from account 1 to account 2
        // Account 1 debit 200, Account 2 credit 200
        $transaction = $this->service->postBalancedJournal(
            lines: [
                ['account_id' => $this->account1->id, 'debit' => 200.00, 'credit' => 0],
                ['account_id' => $this->account2->id, 'debit' => 0, 'credit' => 200.00],
            ],
            module: TransactionModule::General,
            relatedType: null,
            relatedId: null,
            notes: 'Test balanced journal transfer'
        );

        $this->assertNotNull($transaction);
        $this->assertEquals(200.00, (float) $transaction->amount);

        // Account 1 balance: 1000 - 200 = 800
        $this->assertEquals(800.00, (float) $this->account1->fresh()->balance);

        // Account 2 balance: 500 + 200 = 700
        $this->assertEquals(700.00, (float) $this->account2->fresh()->balance);
    }

    /**
     * Test posting an imbalanced journal throws exception.
     */
    public function test_post_imbalanced_journal_throws_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('القيد غير متزن');

        // Debit 200, Credit 150 (imbalanced)
        $this->service->postBalancedJournal(
            lines: [
                ['account_id' => $this->account1->id, 'debit' => 200.00, 'credit' => 0],
                ['account_id' => $this->account2->id, 'debit' => 0, 'credit' => 150.00],
            ],
            module: TransactionModule::General,
            relatedType: null,
            relatedId: null
        );
    }

    /**
     * Test invalid lines throw exception.
     */
    public function test_post_invalid_journal_lines_throw_exception(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ضع إما مدين أو دائن');

        // Line has both debit and credit > 0
        $this->service->postBalancedJournal(
            lines: [
                ['account_id' => $this->account1->id, 'debit' => 200.00, 'credit' => 200.00],
                ['account_id' => $this->account2->id, 'debit' => 0, 'credit' => 200.00],
            ],
            module: TransactionModule::General,
            relatedType: null,
            relatedId: null
        );
    }
}
