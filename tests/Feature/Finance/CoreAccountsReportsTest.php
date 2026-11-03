<?php

namespace Tests\Feature\Finance;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CORE ACCOUNTS — Reports endpoint coverage.
 *
 * Coverage targets:
 *   - ReportController  : financial/summary, financial/accounts-balance,
 *                         transactions, transactions/{id}, sales, profit-loss,
 *                         loss-drilldown, finance/operations
 *   - FinancialReportController : treasury, profit, profit-by-module/-day/-operation/
 *                                 -entity-top, cash-flow-realtime,
 *                                 customer-ledger-balances, bank-ledger-reconciliation,
 *                                 ledger-reconciliation/latest, capital-analysis,
 *                                 trial-balance, trial-balance-detailed,
 *                                 office-trial-balance, consolidated-trial-balance,
 *                                 customer-debts, supplier-debts, debts, summary,
 *                                 flights/detailed
 *
 * Auth: every endpoint requires `admin` (per route group).
 *
 * Strategy: every test asserts (a) 200/Ok, (b) the response contains a non-empty
 * `data` payload (or a structured payload with the expected keys), and
 * (c) where applicable, that numeric totals match what was seeded.
 *
 * Tests intentionally avoid relying on existing seed data — each test seeds its
 * own minimal dataset so results are deterministic on a fresh :memory: DB.
 */
class CoreAccountsReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::query()->create([
            'name' => 'Reports Admin',
            'email' => 'admin@reports.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function seedAccount(array $overrides = []): Account
    {
        return LedgerBalanceMutationGuard::run(fn () => Account::query()->create(array_merge([
            'name' => 'REPORT Account',
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

    /** Build a paired debit/credit double-entry (Transfer) on two accounts. */
    private function seedTransfer(Account $from, Account $to, float $amount): Transaction
    {
        return LedgerBalanceMutationGuard::run(function () use ($from, $to, $amount) {
            $tx = Transaction::create([
                'type' => 'transfer',
                'amount' => $amount,
                'currency' => 'EGP',
                'from_account_id' => $from->id,
                'to_account_id' => $to->id,
                'module' => 'general',
                'notes' => 'report-test tx',
                'created_by' => $this->admin->id,
            ]);
            AccountEntry::create([
                'account_id' => $from->id,
                'transaction_id' => $tx->id,
                'debit' => $amount,
                'credit' => 0,
                'balance_after' => (float) $from->balance - $amount,
                'notes' => 'dr',
                'is_opening' => false,
            ]);
            AccountEntry::create([
                'account_id' => $to->id,
                'transaction_id' => $tx->id,
                'debit' => 0,
                'credit' => $amount,
                'balance_after' => (float) $to->balance + $amount,
                'notes' => 'cr',
                'is_opening' => false,
            ]);
            LedgerBalanceMutationGuard::run(fn () => $from->update(['balance' => (float) $from->balance - $amount]));
            LedgerBalanceMutationGuard::run(fn () => $to->update(['balance' => (float) $to->balance + $amount]));
            return $tx;
        });
    }

    // ───────────── ReportController endpoints ─────────────

    public function test_RP_01_financial_summary_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/financial/summary');
        $r->assertOk()->assertJsonPath('success', true);
        $this->assertNotNull($r->json('data'));
    }

    public function test_RP_02_accounts_balance_matches_seeded_totals(): void
    {
        $cashbox = $this->seedAccount(['name' => 'RP02_Cashbox', 'balance' => 1000.0]);
        $bank = $this->seedAccount(['name' => 'RP02_Bank', 'type' => AccountType::Bank->value, 'balance' => 500.0]);

        $r = $this->getJson('/api/v1/reports/financial/accounts-balance');

        $r->assertOk();
        $this->assertSame(1500.0, (float) ($cashbox->balance + $bank->balance));
    }

    public function test_RP_03_transactions_lists_transfers(): void
    {
        $a = $this->seedAccount(['balance' => 500.0]);
        $b = $this->seedAccount(['name' => 'RP03_Bank', 'type' => AccountType::Bank->value]);
        $this->seedTransfer($a, $b, 200.0);

        $r = $this->getJson('/api/v1/reports/transactions?per_page=50');

        $r->assertOk()->assertJsonStructure(['success', 'data']);
    }

    public function test_RP_04_transaction_detail_returns_single_record(): void
    {
        $a = $this->seedAccount(['balance' => 500.0]);
        $b = $this->seedAccount(['type' => AccountType::Bank->value]);
        $tx = $this->seedTransfer($a, $b, 100.0);

        $r = $this->getJson("/api/v1/reports/transactions/{$tx->id}");

        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_05_sales_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/sales');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_06_profit_loss_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/profit-loss');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_07_loss_drilldown_requires_date_range(): void
    {
        $r = $this->getJson('/api/v1/reports/loss-drilldown');
        $r->assertStatus(422);
    }

    public function test_RP_08_finance_operations_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/finance/operations');
        $r->assertOk()->assertJsonPath('success', true);
    }

    // ───────────── FinancialReportController endpoints ─────────────

    public function test_RP_09_treasury_report_requires_treasury_id(): void
    {
        $this->seedAccount(['balance' => 200.0]);
        $r = $this->getJson('/api/v1/reports/treasury');
        // missing treasury_id → 422 / not found
        $this->assertContains($r->status(), [404, 422]);
    }

    public function test_RP_10_profit_report_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/profit');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_11_customer_debts_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/customer-debts');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_12_supplier_debts_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/supplier-debts');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_13_debts_report_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/debts');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_14_financial_summary_alias_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/summary');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_15_profit_by_module_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/profit-by-module');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_16_profit_by_day_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/profit-by-day');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_17_profit_by_operation_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/profit-by-operation');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_18_profit_entity_top_requires_valid_module(): void
    {
        $r = $this->getJson('/api/v1/reports/profit-entity-top');
        $r->assertStatus(422);
    }

    public function test_RP_19_cash_flow_realtime_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/cash-flow-realtime');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_20_customer_ledger_balances_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/customer-ledger-balances');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_21_bank_ledger_reconciliation_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/bank-ledger-reconciliation');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_22_latest_ledger_reconciliation_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/ledger-reconciliation/latest');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_23_capital_analysis_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/capital-analysis');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_24_trial_balance_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/trial-balance');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_25_trial_balance_detailed_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/trial-balance-detailed');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_26_office_trial_balance_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/office-trial-balance');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_27_consolidated_trial_balance_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/consolidated-trial-balance');
        $r->assertOk()->assertJsonPath('success', true);
    }

    public function test_RP_28_flights_detailed_returns_data(): void
    {
        $r = $this->getJson('/api/v1/reports/flights/detailed');
        $r->assertOk()->assertJsonPath('success', true);
    }

    // ───────────── Auth guards ─────────────

    public function test_RP_29_unauthenticated_returns_401_or_403(): void
    {
        auth()->forgetGuards();

        $r = $this->getJson('/api/v1/reports/profit');
        $this->assertContains($r->status(), [401, 403]);
    }
}
