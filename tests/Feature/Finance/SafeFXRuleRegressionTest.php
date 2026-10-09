<?php

namespace Tests\Feature\Finance;

use App\Enums\TransactionModule;
use App\Exceptions\BusinessLogicException;
use App\Models\Account;
use App\Services\Finance\TransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression tests for the SAFE FX RULE (FIX 2026-08-21).
 *
 * Audit verified (TOURISM_FX_AUDIT_REPORT_20260821.md) that 7 production
 * paths in Visa + HajjUmra carried a silent `$rate = $data['exchange_rate'] ?? 1.0`
 * fallback that masked cross-currency mismatches by coercing a missing rate
 * to 1.0. The fix replaces every silent fallback with an explicit rule:
 *   - Same-currency transfers: unchanged (no FX data required).
 *   - Cross-currency transfers: caller MUST supply
 *     (a) `converted_amount` > 0, OR
 *     (b) `exchange_rate` > 0.
 *   - Anything else → BusinessLogicException (HTTP 409) or, at the
 *     controller boundary, HTTP 422 with a clear Arabic message.
 *
 * These tests exercise the contract directly so future refactors cannot
 * silently reintroduce the `?? 1.0` fallback.
 */
class SafeFXRuleRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // FIX FIN-AUDIT-2026-08-27: create the user referenced by
        // makeAccount's `created_by => 1`. The accounts.created_by
        // foreign key requires the user to exist; without this seed
        // SQLite rejects the insert with a constraint violation.
        \App\Models\User::query()->firstOrCreate(
            ['id' => 1],
            [
                'name' => 'FX Test User',
                'email' => 'fx-test-'.uniqid().'@example.com',
                'password' => bcrypt('password'),
                'role' => 'admin',
                'is_active' => true,
            ]
        );
    }

    /**
     * Helper: create two accounts with controlled currencies and balances.
     */
    private function makeAccount(string $currency, float $balance = 10000.0): Account
    {
        return LedgerBalanceMutationGuard::run(fn () => Account::create([
            'name' => 'SafeFX Test '.$currency.' '.uniqid(),
            'type' => 'cashbox',
            'currency' => $currency,
            'balance' => $balance,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'tourism',
            'created_by' => 1,
        ]));
    }

    // ─── Path A: recordJournalTransfer direct ────────────────────────────────

    public function test_record_journal_transfer_same_currency_succeeds_without_fx_data(): void
    {
        $egp = $this->makeAccount('EGP');
        $egp2 = $this->makeAccount('EGP');

        $tx = app(TransactionService::class)->recordJournalTransfer([
            'amount' => 1000.0,
            'from_account_id' => $egp->id,
            'to_account_id' => $egp2->id,
            'module' => TransactionModule::General->value,
            'created_by' => 1,
        ]);

        $this->assertNotNull($tx->id);
        $this->assertEquals(9000.0, (float) $egp->fresh()->balance);
        $this->assertEquals(11000.0, (float) $egp2->fresh()->balance);
    }

    public function test_record_journal_transfer_cross_currency_without_fx_data_throws_business_logic_exception(): void
    {
        $usd = $this->makeAccount('USD');
        $egp = $this->makeAccount('EGP');

        $this->expectException(BusinessLogicException::class);
        $this->expectExceptionMessageMatches('/لا يمكن تنفيذ تحويل عبر عملات مختلفة/');

        app(TransactionService::class)->recordJournalTransfer([
            'amount' => 100.0,
            'from_account_id' => $usd->id,
            'to_account_id' => $egp->id,
            'module' => TransactionModule::General->value,
            'created_by' => 1,
        ]);
    }

    public function test_record_journal_transfer_cross_currency_with_explicit_rate_succeeds(): void
    {
        $usd = $this->makeAccount('USD');
        $egp = $this->makeAccount('EGP');

        $tx = app(TransactionService::class)->recordJournalTransfer([
            'amount' => 100.0,
            'from_account_id' => $usd->id,
            'to_account_id' => $egp->id,
            'exchange_rate' => 50.0,
            'module' => TransactionModule::General->value,
            'created_by' => 1,
        ]);

        $this->assertNotNull($tx->id);
        $this->assertEquals(9900.0, (float) $usd->fresh()->balance);
        // 100 USD * 50 rate = 5000 EGP credit
        $this->assertEquals(15000.0, (float) $egp->fresh()->balance);
    }

    public function test_record_journal_transfer_cross_currency_with_explicit_converted_amount_succeeds(): void
    {
        $usd = $this->makeAccount('USD');
        $egp = $this->makeAccount('EGP');

        $tx = app(TransactionService::class)->recordJournalTransfer([
            'amount' => 100.0,
            'converted_amount' => 5000.0,
            'from_account_id' => $usd->id,
            'to_account_id' => $egp->id,
            'module' => TransactionModule::General->value,
            'created_by' => 1,
        ]);

        $this->assertNotNull($tx->id);
        $this->assertEquals(9900.0, (float) $usd->fresh()->balance);
        $this->assertEquals(15000.0, (float) $egp->fresh()->balance);
    }

    public function test_record_journal_transfer_cross_currency_with_zero_rate_rejects(): void
    {
        $usd = $this->makeAccount('USD');
        $egp = $this->makeAccount('EGP');

        $this->expectException(BusinessLogicException::class);

        app(TransactionService::class)->recordJournalTransfer([
            'amount' => 100.0,
            'exchange_rate' => 0,
            'from_account_id' => $usd->id,
            'to_account_id' => $egp->id,
            'module' => TransactionModule::General->value,
            'created_by' => 1,
        ]);
    }

    public function test_record_journal_transfer_cross_currency_with_negative_rate_rejects(): void
    {
        $usd = $this->makeAccount('USD');
        $egp = $this->makeAccount('EGP');

        $this->expectException(BusinessLogicException::class);

        app(TransactionService::class)->recordJournalTransfer([
            'amount' => 100.0,
            'exchange_rate' => -5.0,
            'from_account_id' => $usd->id,
            'to_account_id' => $egp->id,
            'module' => TransactionModule::General->value,
            'created_by' => 1,
        ]);
    }

    // ─── Path B: ensure no `?? 1.0` for FX survives anywhere ─────────────────

    public function test_no_silent_fx_fallback_remains_in_finance_services(): void
    {
        // Scan the production code for any new "?? 1.0" pattern that touches
        // exchange_rate / converted_amount — the audit found 7 of them; the
        // safe-FX rule fixes 6. The 7th (the original in
        // TransactionService::recordJournalTransfer) was removed by the
        // Phase 3 fix. This test is a regression sentinel: if anyone
        // reintroduces a silent `?? 1.0` for FX anywhere in the Finance
        // services tree, the audit can run this grep to find it.
        $financeDir = app_path('Services/Finance');
        $forbidden = [];
        $pattern = '/exchange_rate.*\?\?\s*1\.0|converted_amount.*\?\?\s*1\.0/i';

        foreach (glob($financeDir.'/*.php') as $file) {
            $contents = file_get_contents($file);
            if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $m) {
                    $line = substr_count(substr($contents, 0, $m[1]), "\n") + 1;
                    $forbidden[] = $file.':'.$line.' → '.trim($m[0]);
                }
            }
        }

        $this->assertEmpty($forbidden, "Silent ?? 1.0 FX fallback still present:\n  ".implode("\n  ", $forbidden));
    }

    public function test_no_silent_fx_fallback_remains_in_tourism_services(): void
    {
        $serviceFiles = [
            app_path('Services/Visa/VisaBookingService.php'),
            app_path('Services/HajjUmra/HajjUmraBookingService.php'),
        ];
        $forbidden = [];
        $pattern = '/exchange_rate.*\?\?\s*1\.0|converted_amount.*\?\?\s*1\.0/i';

        foreach ($serviceFiles as $file) {
            if (! file_exists($file)) {
                continue;
            }
            $contents = file_get_contents($file);
            if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $m) {
                    $line = substr_count(substr($contents, 0, $m[1]), "\n") + 1;
                    $forbidden[] = $file.':'.$line.' → '.trim($m[0]);
                }
            }
        }

        $this->assertEmpty($forbidden, "Silent ?? 1.0 FX fallback in Tourism service:\n  ".implode("\n  ", $forbidden));
    }

    public function test_no_silent_fx_fallback_remains_in_controllers(): void
    {
        $controllerFiles = [
            app_path('Http/Controllers/Api/V1/VisaController.php'),
            app_path('Http/Controllers/Api/V1/CustomerController.php'),
            app_path('Http/Controllers/Api/V1/HajjUmra/HajjUmraExecutingCompanyFinanceController.php'),
        ];
        $forbidden = [];
        $pattern = '/exchange_rate.*\?\?\s*1\.0|converted_amount.*\?\?\s*1\.0/i';

        foreach ($controllerFiles as $file) {
            if (! file_exists($file)) {
                continue;
            }
            $contents = file_get_contents($file);
            if (preg_match_all($pattern, $contents, $matches, PREG_OFFSET_CAPTURE)) {
                foreach ($matches[0] as $m) {
                    $line = substr_count(substr($contents, 0, $m[1]), "\n") + 1;
                    $forbidden[] = $file.':'.$line.' → '.trim($m[0]);
                }
            }
        }

        $this->assertEmpty($forbidden, "Silent ?? 1.0 FX fallback in controller:\n  ".implode("\n  ", $forbidden));
    }
}
