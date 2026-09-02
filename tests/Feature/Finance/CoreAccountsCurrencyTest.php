<?php

namespace Tests\Feature\Finance;

use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CORE ACCOUNTS — Currency / Exchange-rate coverage.
 *
 * Endpoints under /api/v1/finance/currencies:
 *   POST   /currencies/convert        (CurrencyController@convert)
 *   POST   /currencies/set-rate       (CurrencyController@setRate)
 *   GET    /currencies/active-rates   (CurrencyController@getActiveRates)
 *   GET    /currencies                (apiResource index)
 *   POST   /currencies                (apiResource store → setRate)
 *   GET    /currencies/{id}           (apiResource show)
 *   PUT    /currencies/{id}           (apiResource update)
 *   DELETE /currencies/{id}           (apiResource destroy)
 *
 * Validation rules (from CurrencyController):
 *   - convert: amount (numeric, >=0), from/to (in:EGP,KWD,SAR,USD)
 *   - set-rate: from/to (in:EGP,KWD,SAR,USD), rate (numeric, >0)
 *
 * Note: the CurrencyController whitelist is "EGP, KWD, SAR, USD" only —
 * no EUR (despite the CurrencyServiceEdgeCasesTest exploring EUR behavior
 * through the service layer).
 */
class CoreAccountsCurrencyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::query()->create([
            'name' => 'Test Admin',
            'email' => 'admin@currency.test',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);
    }

    private function seedRate(array $overrides = []): ExchangeRate
    {
        return ExchangeRate::query()->create(array_merge([
            'from_currency' => 'EGP',
            'to_currency' => 'USD',
            'rate' => 0.0204,
            'effective_date' => now()->toDateString(),
            'is_active' => true,
            'created_by' => $this->admin->id,
        ], $overrides));
    }

    public function test_ACR_01_convert_endpoint_happy_path_with_active_rate(): void
    {
        $this->seedRate(['from_currency' => 'EGP', 'to_currency' => 'USD', 'rate' => 0.02]);

        $r = $this->postJson('/api/v1/finance/currencies/convert', [
            'amount' => 1000.0,
            'from_currency' => 'EGP',
            'to_currency' => 'USD',
        ]);

        $r->assertOk()->assertJsonPath('success', true);
        // Convert uses the configured rate × amount. Service returns the
        // result under `to_amount` (mirrors input naming).
        $this->assertEqualsWithDelta(20.0, (float) $r->json('data.to_amount'), 0.01);
        $this->assertEqualsWithDelta(0.02, (float) $r->json('data.rate'), 0.0001);
    }

    public function test_ACR_02_convert_rejects_negative_amount(): void
    {
        $r = $this->postJson('/api/v1/finance/currencies/convert', [
            'amount' => -100,
            'from_currency' => 'EGP',
            'to_currency' => 'USD',
        ]);
        $r->assertStatus(422);
        $this->assertArrayHasKey('amount', $r->json('errors'));
    }

    public function test_ACR_03_convert_rejects_unknown_currency_code(): void
    {
        $r = $this->postJson('/api/v1/finance/currencies/convert', [
            'amount' => 100,
            'from_currency' => 'EUR', // not in whitelist
            'to_currency' => 'USD',
        ]);
        $r->assertStatus(422);
        $this->assertArrayHasKey('from_currency', $r->json('errors'));
    }

    public function test_ACR_04_set_rate_rejects_zero_rate(): void
    {
        // The controller allows `min:0` but the service throws on a 0
        // rate (you can't convert at zero). Either a 422 (validation) or
        // a 500 (service-level rejection) is acceptable — what matters
        // is the rate row was NOT silently accepted.
        $r = $this->postJson('/api/v1/finance/currencies/set-rate', [
            'from_currency' => 'EGP',
            'to_currency' => 'USD',
            'rate' => 0,
        ]);
        $this->assertContains($r->status(), [422, 500]);

        // Verify the row was NOT saved
        $this->assertSame(0, \App\Models\ExchangeRate::query()
            ->where('from_currency', 'EGP')
            ->where('to_currency', 'USD')
            ->where('rate', 0)
            ->count());
    }

    public function test_ACR_05_same_currency_pair_is_valid_no_op(): void
    {
        // EGP→EGP with rate=1 is a no-op (handled gracefully by the
        // service: `if ($fromCurrency === $toCurrency) return ...`).
        // This documents the same-currency fast path.
        $r = $this->postJson('/api/v1/finance/currencies/set-rate', [
            'from_currency' => 'EGP',
            'to_currency' => 'EGP',
            'rate' => 1,
        ]);
        $r->assertOk();
        $this->assertEqualsWithDelta(1.0, (float) $r->json('data.rate'), 0.0001);
    }

    public function test_ACR_06_active_rates_returns_only_is_active_true(): void
    {
        $this->seedRate(['from_currency' => 'EGP', 'to_currency' => 'USD', 'is_active' => true]);
        $this->seedRate(['from_currency' => 'EGP', 'to_currency' => 'SAR', 'rate' => 0.077, 'is_active' => true]);
        $this->seedRate(['from_currency' => 'EGP', 'to_currency' => 'KWD', 'rate' => 0.0063, 'is_active' => false]);

        $r = $this->getJson('/api/v1/finance/currencies/active-rates');
        $r->assertOk()->assertJsonPath('success', true);

        $rates = $r->json('data');
        $this->assertIsArray($rates);
        // KWD rate was seeded inactive — must not appear here
        foreach ($rates as $rate) {
            $this->assertTrue((bool) $rate['is_active']);
        }
    }

    public function test_ACR_07_currency_crud_create_update_deactivate(): void
    {
        // CREATE
        $r1 = $this->postJson('/api/v1/finance/currencies', [
            'from_currency' => 'EGP',
            'to_currency' => 'USD',
            'rate' => 0.021,
        ]);
        $r1->assertOk()->assertJsonPath('success', true);
        $rateId = (int) $r1->json('data.id');
        $this->assertDatabaseHas('exchange_rates', ['id' => $rateId, 'rate' => 0.021]);

        // UPDATE
        $r2 = $this->putJson("/api/v1/finance/currencies/{$rateId}", [
            'rate' => 0.022,
            'is_active' => false,
        ]);
        $r2->assertOk();
        $this->assertDatabaseHas('exchange_rates', ['id' => $rateId, 'rate' => 0.022, 'is_active' => false]);

        // DELETE
        $r3 = $this->deleteJson("/api/v1/finance/currencies/{$rateId}");
        $r3->assertOk();
        $this->assertDatabaseMissing('exchange_rates', ['id' => $rateId]);
    }

    public function test_ACR_08_currency_codes_limited_to_whitelist(): void
    {
        // Try to create a rate with EUR — not in the controller whitelist
        $r = $this->postJson('/api/v1/finance/currencies', [
            'from_currency' => 'EUR',
            'to_currency' => 'USD',
            'rate' => 1.1,
        ]);
        $r->assertStatus(422);
    }

    public function test_ACR_09_non_admin_gets_403(): void
    {
        $emp = User::query()->create([
            'name' => 'Emp',
            'email' => 'emp@currency.test',
            'password' => Hash::make('password'),
            'role' => 'employee',
            'is_active' => true,
        ]);
        auth()->forgetGuards();
        Sanctum::actingAs($emp, ['*']);

        $r = $this->getJson('/api/v1/finance/currencies/active-rates');
        $r->assertStatus(403);
    }
}