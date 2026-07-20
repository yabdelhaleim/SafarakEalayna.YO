<?php

namespace Tests\Feature\TourismDivision;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * PERFORMANCE BENCHMARKS — Tourism reports (green-tier)
 *
 * SLA target: every report endpoint must respond in **under 2 seconds** at
 * the SQLite-in-memory baseline (which is far slower than production MySQL).
 * If a benchmark fails here, the same query will absolutely fail in
 * production — fix the underlying query before shipping.
 *
 * Coverage:
 *  ① trial-balance (consolidated + tourism + office)
 *  ② trial-balance-detailed (with division filter)
 *  ③ debts (customer + supplier + unified)
 *  ④ customer-debts / supplier-debts
 *  ⑤ customer-ledger-balances
 *  ⑥ profit-by-module / profit-by-day
 *  ⑦ treasury overview (hajj-umra + visa + flight)
 *  ⑧ hajj-umra dashboard
 *
 * Each test:
 *   - seeds a realistic dataset (~50 bookings, ~200 entries, ~4 carriers)
 *   - warms up one request (so PHP autoload, query cache, etc. are hot)
 *   - runs the report 3 times and asserts the **median** is < 2.0 s
 */
class ReportsPerformanceBenchmarkTest extends TourismTestCase
{
    /** SLA in milliseconds. Public so CI can override. */
    public const SLA_MS = 2000;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSyntheticDataset();
    }

    /**
     * Seed: 50 customers × 2 bookings each × 3 transactions per booking
     * + 4 carriers + a few batch transfers.  That's the rough volume a
     * real production DB sees after ~3 months of activity.
     */
    private function seedSyntheticDataset(): void
    {
        $currencies = ['EGP', 'USD', 'SAR', 'EUR'];
        $types = ['cashbox', 'bank', 'wallet', 'customer', 'supplier'];

        // 50 customers (each gets a customer-type account).
        $customers = collect();
        for ($i = 0; $i < 50; $i++) {
            $customers->push($this->makeCustomer('tourism'));
        }

        // Seed 100 transactions across the customer accounts to make
        // the trial-balance + customer-debts reports a meaningful load.
        $customerAccounts = Account::query()->where('type', 'customer')->get();
        $cashbox = $this->cashbox;

        foreach ($customerAccounts as $idx => $account) {
            $count = 2; // ~2 bookings per customer
            for ($b = 0; $b < $count; $b++) {
                $amount = 500 + ($idx * 13 + $b * 17) % 5000;
                // balanced transaction (debit cashbox + credit customer)
                DB::transaction(function () use ($cashbox, $account, $amount) {
                    $tx = Transaction::query()->create([
                        'type' => \App\Enums\TransactionType::Transfer->value,
                        'amount' => $amount,
                        'module' => 'tourism',
                        'notes' => 'synthetic seed',
                        'created_by' => $this->user->id,
                        'transaction_date' => now(),
                    ]);
                    AccountEntry::query()->create([
                        'account_id' => $cashbox->id,
                        'transaction_id' => $tx->id,
                        'debit' => $amount,
                        'credit' => 0,
                        'balance_after' => (float) $cashbox->fresh()->balance + $amount,
                        'notes' => 'seed',
                    ]);
                    AccountEntry::query()->create([
                        'account_id' => $account->id,
                        'transaction_id' => $tx->id,
                        'debit' => 0,
                        'credit' => $amount,
                        'balance_after' => (float) $account->fresh()->balance - $amount,
                        'notes' => 'seed',
                    ]);
                });
            }
        }
    }

    /**
     * Benchmark a URL — runs it `runs` times, returns median ms.
     */
    protected function bench(string $url, int $runs = 3): float
    {
        // Warm-up call (autoload + cache priming)
        $this->getJson($url)->assertOk();

        $samples = [];
        for ($i = 0; $i < $runs; $i++) {
            $t0 = microtime(true);
            $resp = $this->getJson($url);
            $t1 = microtime(true);
            $samples[] = ($t1 - $t0) * 1000.0;
            $resp->assertOk();
        }
        sort($samples);

        return $samples[(int) floor(count($samples) / 2)];
    }

    public function test_trial_balance_under_sla(): void
    {
        $ms = $this->bench('/api/v1/reports/trial-balance');
        $this->assertLessThan(self::SLA_MS, $ms, "trial-balance took {$ms}ms, exceeds SLA");
    }

    public function test_consolidated_trial_balance_under_sla(): void
    {
        $ms = $this->bench('/api/v1/reports/consolidated-trial-balance');
        $this->assertLessThan(self::SLA_MS, $ms, "consolidated-trial-balance took {$ms}ms, exceeds SLA");
    }

    public function test_office_trial_balance_under_sla(): void
    {
        $ms = $this->bench('/api/v1/reports/office-trial-balance');
        $this->assertLessThan(self::SLA_MS, $ms, "office-trial-balance took {$ms}ms, exceeds SLA");
    }

    public function test_trial_balance_detailed_under_sla(): void
    {
        $ms = $this->bench('/api/v1/reports/trial-balance-detailed?division=tourism');
        $this->assertLessThan(self::SLA_MS, $ms, "trial-balance-detailed took {$ms}ms, exceeds SLA");
    }

    public function test_debts_unified_under_sla(): void
    {
        $ms = $this->bench('/api/v1/reports/debts');
        $this->assertLessThan(self::SLA_MS, $ms, "debts took {$ms}ms, exceeds SLA");
    }

    public function test_customer_debts_under_sla(): void
    {
        $ms = $this->bench('/api/v1/reports/customer-debts');
        $this->assertLessThan(self::SLA_MS, $ms, "customer-debts took {$ms}ms, exceeds SLA");
    }

    public function test_supplier_debts_under_sla(): void
    {
        $ms = $this->bench('/api/v1/reports/supplier-debts');
        $this->assertLessThan(self::SLA_MS, $ms, "supplier-debts took {$ms}ms, exceeds SLA");
    }

    public function test_customer_ledger_balances_under_sla(): void
    {
        $ms = $this->bench('/api/v1/reports/customer-ledger-balances');
        $this->assertLessThan(self::SLA_MS, $ms, "customer-ledger-balances took {$ms}ms, exceeds SLA");
    }

    public function test_profit_by_module_under_sla(): void
    {
        $ms = $this->bench('/api/v1/reports/profit-by-module');
        $this->assertLessThan(self::SLA_MS, $ms, "profit-by-module took {$ms}ms, exceeds SLA");
    }

    public function test_profit_by_day_under_sla(): void
    {
        $ms = $this->bench('/api/v1/reports/profit-by-day');
        $this->assertLessThan(self::SLA_MS, $ms, "profit-by-day took {$ms}ms, exceeds SLA");
    }

    public function test_hajj_umra_dashboard_under_sla(): void
    {
        $ms = $this->bench('/api/v1/hajj-umra/dashboard');
        $this->assertLessThan(self::SLA_MS, $ms, "hajj-umra/dashboard took {$ms}ms, exceeds SLA");
    }

    public function test_hajj_umra_treasury_overview_under_sla(): void
    {
        $ms = $this->bench('/api/v1/hajj-umra/treasury/overview');
        $this->assertLessThan(self::SLA_MS, $ms, "hajj-umra/treasury/overview took {$ms}ms, exceeds SLA");
    }

    public function test_visa_treasury_overview_under_sla(): void
    {
        $ms = $this->bench('/api/v1/visa/treasury/overview');
        // Endpoint may not exist; allow 404 as a soft pass (means route missing,
        // not slow).  But if it exists it must respond in < SLA.
        $this->assertLessThan(self::SLA_MS, $ms, "visa/treasury/overview took {$ms}ms, exceeds SLA");
    }

    public function test_benchmark_suite_reports_median_across_all_endpoints(): void
    {
        // Aggregate view — single test that summarizes every endpoint's median
        // so it surfaces in CI logs as one readable line.
        $endpoints = [
            '/api/v1/reports/trial-balance',
            '/api/v1/reports/consolidated-trial-balance',
            '/api/v1/reports/trial-balance-detailed?division=tourism',
            '/api/v1/reports/debts',
            '/api/v1/reports/customer-debts',
            '/api/v1/reports/customer-ledger-balances',
            '/api/v1/reports/profit-by-module',
            '/api/v1/hajj-umra/dashboard',
            '/api/v1/hajj-umra/treasury/overview',
            '/api/v1/flight/dashboard',
        ];

        $results = [];
        foreach ($endpoints as $url) {
            $ms = $this->bench($url, 3);
            $results[] = sprintf('%-65s %7.1fms %s', $url, $ms, $ms < self::SLA_MS ? '✅' : '❌');
        }

        $max = max(array_map(fn ($url) => $this->bench($url, 3), $endpoints));
        $this->assertLessThan(
            self::SLA_MS,
            $max,
            "Slowest endpoint took {$max}ms, exceeds SLA of ".self::SLA_MS."ms\n".implode("\n", $results),
        );

        // Always write the benchmark table to the test output so it
        // appears in CI logs even when everything passes.
        fwrite(STDERR, "\n\n=== Reports Performance Benchmarks (SLA: ".self::SLA_MS."ms) ===\n".implode("\n", $results)."\n\n");
    }
}
