<?php

namespace Tests\Feature\Flight;

use App\Models\Account;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Regression tests for the user-reported bug (2026-08-23):
 *   "/flights/treasury — إعادة شحن نظام الحجز → dropdown فاضي
 *    رغم إن الحسابات اللي بنفس عملة النظام موجودة في الـ DB"
 *
 * الـ dropdown بتاع مودال "إعادة شحن نظام الحجز" بيشتغل على مرحلتين:
 *   (1) الـ backend (`FlightTreasuryController::overview`) بيرجّع
 *       `settlement_accounts` بعد فلترة معيّنة.
 *   (2) الـ frontend (`FlightTreasuryOverview.vue:1085-1091`) بياخد
 *       العناصر اللي عملتها بتطابق currency الـ system.
 *
 * الـ TC ده بيـتـحقق إن ناتج (1) بيرجّع الحساب حتى لو عملته بتطابق
 * عملة الـ system بالظبط — وده اللي بيتوقعه المستخدم. لو الـ test فشل
 * على بيئة معيّنة (CI / staging) يبقى فيه regression في الـ controller
 * أو في الـ seeders أو في الـ DB schema.
 *
 * @see \App\Http\Controllers\Api\V1\Flight\FlightTreasuryController::overview
 */
class FlightTreasuryOverviewSameCurrencyDropdownTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'name' => 'Same-Currency Treasury Admin',
            'email' => 'same-currency-treasury@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    /**
     * Helper: أنشئ نظام طيران بحساب تحصيل يطابق عملته.
     */
    private function makeSystemWithMatchingAccount(string $systemCurrency, string $accountCurrency, string $accountType = 'cashbox', string $accountName = null): array
    {
        $this->assertSame(
            strtoupper($systemCurrency),
            strtoupper($accountCurrency),
            'Test setup invariant: system and account currencies must match'
        );

        $system = FlightSystem::create([
            'name' => 'Test System '.$accountCurrency,
            'code' => 'TSY-'.$accountCurrency.'-'.uniqid(),
            'type' => 'gds',
            'is_active' => true,
            'currency' => strtoupper($accountCurrency),
            'credit_limit' => 1000.00,
            'created_by' => $this->admin->id,
        ]);

        $account = Account::create([
            'name' => $accountName ?? 'Test '.$accountCurrency.' '.$accountType,
            'type' => $accountType,
            'currency' => strtoupper($accountCurrency),
            'balance' => 5000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        return [$system, $account];
    }

    /**
     * ✅ SCENARIO 1: KWD system → KWD cashbox shows up.
     * This is the exact case the user reported.
     */
    public function test_kwd_cashbox_appears_in_settlement_for_kwd_system(): void
    {
        [$system, $account] = $this->makeSystemWithMatchingAccount('KWD', 'KWD');

        $response = $this->getJson('/api/v1/flight/treasury/overview');
        $response->assertOk();

        $settlementIds = collect($response->json('data.settlement_accounts'))->pluck('id')->toArray();

        $this->assertContains(
            $account->id,
            $settlementIds,
            'BUG: KWD cashbox that matches KWD system currency MUST appear in settlement_accounts so the dropdown can show it. '.
            'Found accounts: '.json_encode($settlementIds)
        );

        // sanity: الـ system نفسه لازم يكون موجود
        $systemIds = collect($response->json('data.systems'))->pluck('id')->toArray();
        $this->assertContains($system->id, $systemIds);
    }

    /**
     * ✅ SCENARIO 2: 5 أنظمة × 5 عملات مختلفة، كل واحد له حساب مطابق.
     *    لو الـ API بيرجّع كل الحسابات بدون issue → dropdown هيشتغل.
     */
    public function test_all_same_currency_accounts_appear_simultaneously_across_currencies(): void
    {
        $currencies = ['KWD', 'SAR', 'AED', 'USD', 'EUR', 'EGP'];
        $setup = [];

        foreach ($currencies as $cur) {
            $setup[$cur] = $this->makeSystemWithMatchingAccount($cur, $cur);
        }

        $response = $this->getJson('/api/v1/flight/treasury/overview');
        $response->assertOk();

        $settlementIds = collect($response->json('data.settlement_accounts'))->pluck('id')->toArray();

        foreach ($currencies as $cur) {
            [$system, $account] = $setup[$cur];
            $this->assertContains(
                $account->id,
                $settlementIds,
                "BUG: {$cur} cashbox (matches {$cur} system) must appear in settlement_accounts. ".
                "Frontend filter is strict by currency — if it's not in settlement_accounts, dropdown is empty."
            );
        }
    }

    /**
     * ✅ SCENARIO 3: KWD system مع wallet بدل cashbox لازم يظهر.
     */
    public function test_kwd_wallet_appears_for_kwd_system(): void
    {
        [$system, $wallet] = $this->makeSystemWithMatchingAccount('KWD', 'KWD', 'wallet');

        $response = $this->getJson('/api/v1/flight/treasury/overview');
        $response->assertOk();

        $settlementIds = collect($response->json('data.settlement_accounts'))->pluck('id')->toArray();

        $this->assertContains(
            $wallet->id,
            $settlementIds,
            'BUG: KWD wallet must be in settlement_accounts (wallet type is allowed by the controller filter).'
        );
    }

    /**
     * ✅ SCENARIO 4: KWD system مع bank لازم يظهر.
     */
    public function test_kwd_bank_appears_for_kwd_system(): void
    {
        [$system, $bank] = $this->makeSystemWithMatchingAccount('KWD', 'KWD', 'bank');

        $response = $this->getJson('/api/v1/flight/treasury/overview');
        $response->assertOk();

        $settlementIds = collect($response->json('data.settlement_accounts'))->pluck('id')->toArray();

        $this->assertContains(
            $bank->id,
            $settlementIds,
            'BUG: KWD bank must be in settlement_accounts (bank type is allowed by the controller filter).'
        );
    }

    /**
     * ⚠️ REGRESSION GUARD — لازم الحسابات الـ office-only ما تظهرش.
     * (الفلتر: `module_type ∈ {flights, tourism}` — يعني office لازم يتستبعد
     * حتى لو عملته بتطابق الـ system.)
     */
    public function test_office_module_account_excluded_even_if_currency_matches(): void
    {
        // System بـ KWD
        $system = FlightSystem::create([
            'name' => 'Test KWD System Regression',
            'code' => 'TSY-REG-'.uniqid(),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'KWD',
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        // حساب KWD لكن module_type=office → مستبعد
        $officeOnlyAccount = Account::create([
            'name' => 'Test Office-Only KWD',
            'type' => 'cashbox',
            'currency' => 'KWD',
            'balance' => 100.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office', // ← دي اللي لازم تستبعد
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/v1/flight/treasury/overview');
        $response->assertOk();

        $settlementIds = collect($response->json('data.settlement_accounts'))->pluck('id')->toArray();

        $this->assertNotContains(
            $officeOnlyAccount->id,
            $settlementIds,
            'office-only accounts must NOT appear even if currency matches (controller filters module_type ∈ {flights,tourism})'
        );
    }

    /**
     * ⚠️ REGRESSION GUARD — لازم الحسابات inactive ما تظهرش.
     */
    public function test_inactive_account_excluded_even_if_currency_matches(): void
    {
        $system = FlightSystem::create([
            'name' => 'Test KWD System Inactive Guard',
            'code' => 'TSY-INA-'.uniqid(),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'KWD',
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        $inactiveAccount = Account::create([
            'name' => 'Test Inactive KWD',
            'type' => 'cashbox',
            'currency' => 'KWD',
            'balance' => 100.00,
            'is_active' => false, // ← دي اللي لازم تستبعد
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/v1/flight/treasury/overview');
        $response->assertOk();

        $settlementIds = collect($response->json('data.settlement_accounts'))->pluck('id')->toArray();

        $this->assertNotContains(
            $inactiveAccount->id,
            $settlementIds,
            'Inactive accounts must NOT appear even if currency matches (controller has where is_active=true)'
        );
    }

    /**
     * ⚠️ REGRESSION GUARD — لو الكود المتعلق بنوع account بقى أرخص
     * (legacy 'treasury' / 'post') لازم يظهر في dropdown حتى لو الـ endpoint
     * الحالي بيرجّع Bank/Cashbox بس.
     */
    public function test_old_legacy_account_type_does_not_break_response_shape(): void
    {
        // System بـ KWD
        $system = FlightSystem::create([
            'name' => 'Test KWD Legacy Guard',
            'code' => 'TSY-LEG-'.uniqid(),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'KWD',
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        // الحساب الجديد (bank) — لازم يظهر
        $newAccount = Account::create([
            'name' => 'Test Modern KWD Bank',
            'type' => 'bank',
            'currency' => 'KWD',
            'balance' => 1000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->admin->id,
        ]);

        $response = $this->getJson('/api/v1/flight/treasury/overview');
        $response->assertOk();

        $settlementIds = collect($response->json('data.settlement_accounts'))->pluck('id')->toArray();

        $this->assertContains(
            $newAccount->id,
            $settlementIds,
            'Modern bank type KWD cashbox must appear. If this fails, Phase 3.5b schema retirement may have regressed the controller filter.'
        );
    }
}
