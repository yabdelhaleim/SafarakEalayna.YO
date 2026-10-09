<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CORE ACCOUNTS — Statement endpoint coverage.
 *
 * GET /api/v1/finance/accounts/{id}/statement
 *
 * The statement endpoint returns AccountEntry rows (paginated) for an
 * account, with summary statistics (opening/closing balance, period credit,
 * period debit). Filters: search, from_date, to_date, type (credit|debit).
 *
 * Implementation note: AccountService::getAccountStatement excludes
 * `transaction_id IS NULL` rows (the auto-seeded opening-balance entries)
 * from period stats per FIN-AUDIT-2026-08-27. Tests use Transaction-backed
 * entries to avoid that exclusion.
 */
class CoreAccountsStatementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::query()->create([
            'name' => 'Test Admin',
            'email' => 'admin@statement.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function seedAccount(array $overrides = []): Account
    {
        return LedgerBalanceMutationGuard::run(fn () => Account::query()->create(array_merge([
            'name' => 'TEST_AS Cashbox',
            'type' => AccountType::Cashbox->value,
            'currency' => 'EGP',
            'balance' => 1000.00,
            'is_active' => true,
            'module_type' => 'office',
            'module' => 'office',
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'created_by' => $this->admin->id,
        ], $overrides)));
    }

    /**
     * Insert N journal entries on the account, with controllable credit
     * values and created_at timestamps. Uses Transaction-backed entries so
     * they ARE included in period stats (the FIN-AUDIT exclusion only
     * applies to transaction_id IS NULL rows).
     */
    private function seedEntries(Account $account, array $rows): void
    {
        LedgerBalanceMutationGuard::run(function () use ($account, $rows) {
            foreach ($rows as $r) {
                $debit = (float) ($r['debit'] ?? 0.00);
                $credit = (float) ($r['credit'] ?? 0.00);
                $amount = $r['amount'] ?? max($debit, $credit);
                $at = $r['at'] ?? now();

                // Use forceFill + timestamps=false so Eloquent does not
                // overwrite our explicit created_at. The default
                // ::create() respects explicit values, but in some PHP/DB
                // combos the auto-timestamp triggers before the values are
                // merged; forceFill is bullet-proof.
                $tx = new Transaction();
                $tx->timestamps = false;
                $tx->forceFill([
                    'type' => $r['type'] ?? TransactionType::Transfer->value,
                    'amount' => $amount,
                    'currency' => $account->currency,
                    'module' => $r['module'] ?? TransactionModule::General->value,
                    'from_account_id' => $account->id,
                    'to_account_id' => null,
                    'created_by' => $this->admin->id,
                    'notes' => $r['notes'] ?? 'TEST journal entry',
                    'created_at' => $at,
                    'updated_at' => $at,
                ])->save();

                $entry = new AccountEntry();
                $entry->timestamps = false;
                $entry->forceFill([
                    'account_id' => $account->id,
                    'transaction_id' => $tx->id,
                    'debit' => $debit,
                    'credit' => $credit,
                    'balance_after' => $r['balance_after'] ?? 0.00,
                    'notes' => $r['notes'] ?? 'TEST journal entry',
                    'is_opening' => false,
                    'created_at' => $at,
                    'updated_at' => $at,
                ])->save();
            }
        });
    }

    // ───────────── basic shape ─────────────

    public function test_AS_01_statement_returns_entries_with_pagination(): void
    {
        $acc = $this->seedAccount();

        $rows = [];
        for ($i = 1; $i <= 25; $i++) {
            $rows[] = [
                'type' => TransactionType::Income->value,
                'amount' => 10.0,
                'credit' => 10.0,
                'debit' => 0.0,
                'balance_after' => $i * 10,
                'notes' => "TEST_AS01 entry $i",
                'at' => now()->subMinutes(25 - $i),
            ];
        }
        $this->seedEntries($acc, $rows);

        $r = $this->getJson("/api/v1/finance/accounts/{$acc->id}/statement?per_page=10");

        $r->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'items' => ['*' => ['id', 'account_id', 'transaction_id', 'debit', 'credit', 'balance_after', 'notes', 'created_at']],
                    'pagination' => ['total', 'per_page', 'current_page', 'last_page', 'from', 'to'],
                    'stats' => ['opening_balance', 'period_credit', 'period_debit', 'closing_balance', 'account_balance'],
                ],
            ]);

        $this->assertSame(25, (int) $r->json('data.pagination.total'));
        $this->assertCount(10, $r->json('data.items'));
    }

    public function test_AS_02_statement_filters_by_date_range(): void
    {
        $acc = $this->seedAccount();
        $this->seedEntries($acc, [
            ['credit' => 100.0, 'balance_after' => 100.0, 'at' => now()->subDays(10)],
            ['credit' => 200.0, 'balance_after' => 300.0, 'at' => now()->subDays(5)],
            ['credit' => 300.0, 'balance_after' => 600.0, 'at' => now()->subDays(1)],
        ]);

        $r = $this->getJson("/api/v1/finance/accounts/{$acc->id}/statement?from_date=".now()->subDays(7)->toDateString().'&to_date='.now()->subDays(2)->toDateString());
        $r->assertOk();
        // 3 entries total — only the middle one (5 days ago) is in window
        $this->assertSame(1, (int) $r->json('data.pagination.total'));
        $this->assertSame(200.0, (float) $r->json('data.stats.period_credit'));
    }

    public function test_AS_03_statement_filters_by_entry_type_credit_or_debit(): void
    {
        $acc = $this->seedAccount();
        $this->seedEntries($acc, [
            ['credit' => 500.0, 'debit' => 0.0, 'balance_after' => 500.0, 'at' => now()->subMinutes(5)],
            ['credit' => 0.0, 'debit' => 100.0, 'balance_after' => 400.0, 'at' => now()->subMinutes(4)],
            ['credit' => 300.0, 'debit' => 0.0, 'balance_after' => 700.0, 'at' => now()->subMinutes(3)],
            ['credit' => 0.0, 'debit' => 50.0, 'balance_after' => 650.0, 'at' => now()->subMinutes(2)],
        ]);

        $rCredit = $this->getJson("/api/v1/finance/accounts/{$acc->id}/statement?type=credit");
        $rCredit->assertOk();
        $this->assertSame(2, (int) $rCredit->json('data.pagination.total'));
        $this->assertSame(800.0, (float) $rCredit->json('data.stats.period_credit'));

        $rDebit = $this->getJson("/api/v1/finance/accounts/{$acc->id}/statement?type=debit");
        $rDebit->assertOk();
        $this->assertSame(2, (int) $rDebit->json('data.pagination.total'));
        $this->assertSame(150.0, (float) $rDebit->json('data.stats.period_debit'));
    }

    public function test_AS_04_statement_summary_includes_opening_credit_debit_closing(): void
    {
        // Seed account with balance 1000.0 — this triggers the Account::created
        // observer which posts an opening credit entry of 1000.0.
        $acc = $this->seedAccount();
        // Add 2 credits + 1 debit so the period stats are populated:
        // period_credit = 400, period_debit = 100.
        $this->seedEntries($acc, [
            ['credit' => 200.0, 'debit' => 0.0, 'balance_after' => 200.0, 'at' => now()->subMinutes(5)],
            ['credit' => 200.0, 'debit' => 0.0, 'balance_after' => 400.0, 'at' => now()->subMinutes(4)],
            ['credit' => 0.0, 'debit' => 100.0, 'balance_after' => 300.0, 'at' => now()->subMinutes(3)],
        ]);

        $r = $this->getJson("/api/v1/finance/accounts/{$acc->id}/statement");
        $r->assertOk();

        $stats = $r->json('data.stats');
        // Opening balance = account.balance − (period_credit − period_debit)
        //                   = 1000 − (400 − 100) = 700 (the pre-existing
        //                   equity / opening capital before any TX-backed
        //                   movements occurred).
        $this->assertSame(700.0, (float) $stats['opening_balance']);
        $this->assertSame(400.0, (float) $stats['period_credit']);
        $this->assertSame(100.0, (float) $stats['period_debit']);
        // closing = opening + credit − debit = 700 + 400 − 100 = 1000
        $this->assertSame(1000.0, (float) $stats['closing_balance']);
        $this->assertSame(1000.0, (float) $stats['account_balance']);
    }

    public function test_AS_05_statement_for_nonexistent_account_404(): void
    {
        $r = $this->getJson('/api/v1/finance/accounts/999999/statement');
        $r->assertStatus(404);
    }

    public function test_AS_06_statement_excludes_opening_entries_from_period_stats(): void
    {
        // Per FIN-AUDIT-2026-08-27, opening entries (transaction_id IS NULL)
        // must NOT contribute to period_credit / period_debit. Only
        // Transaction-backed entries count.
        $acc = $this->seedAccount(['balance' => 1000.0]);
        // The seedAccount helper above triggers Account::created which auto-
        // posts an opening entry of 1000.0 (credit) per FIN-1.

        // Add a Transaction-backed debit of 250.
        $this->seedEntries($acc, [
            ['debit' => 250.0, 'credit' => 0.0, 'balance_after' => 750.0, 'at' => now()->subMinutes(1)],
        ]);

        $r = $this->getJson("/api/v1/finance/accounts/{$acc->id}/statement");
        $r->assertOk();

        $stats = $r->json('data.stats');
        $this->assertSame(0.0, (float) $stats['period_credit'], 'opening credit should be excluded');
        $this->assertSame(250.0, (float) $stats['period_debit']);
    }

    public function test_AS_07_non_admin_gets_403_on_statement(): void
    {
        $emp = User::query()->create([
            'name' => 'Emp', 'email' => 'emp@stmt.test',
            'password' => Hash::make('password'),
            'role' => 'employee', 'is_active' => true,
        ]);
        $acc = $this->seedAccount();

        // Admin acts first (setUp), then switch to employee.
        auth()->forgetGuards();
        Sanctum::actingAs($emp, ['*']);

        $r = $this->getJson("/api/v1/finance/accounts/{$acc->id}/statement");
        $r->assertStatus(403);
    }
}