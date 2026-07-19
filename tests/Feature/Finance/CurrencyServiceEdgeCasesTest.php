<?php

namespace Tests\Feature\Finance;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\Finance\CurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Edge-case tests for CurrencyService::convert() and setExchangeRate().
 *
 * Covers:
 *   Bug #16 — convert() used to accept negative amounts silently.
 *   Bug #8  — setExchangeRate() used to accept rate=0 or rate=-1 silently.
 *   Bug #17 — convert(0, ...) returns rate=0 (documented / safe behavior).
 */
class CurrencyServiceEdgeCasesTest extends TestCase
{
    use RefreshDatabase;

    protected CurrencyService $cs;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'role'      => 'admin',
            'is_active' => true,
            'password'  => Hash::make('password'),
        ]);
        Sanctum::actingAs($this->user, ['*']);

        $this->cs = app(CurrencyService::class);

        // Seed a USD<->EGP rate so convert() can resolve it
        ExchangeRate::create([
            'from_currency'  => 'USD',
            'to_currency'    => 'EGP',
            'rate'           => 50.0,
            'effective_date' => now()->toDateString(),
            'is_active'      => true,
            'created_by'     => $this->user->id,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bug #16 — negative amount guard
    // ─────────────────────────────────────────────────────────────────────────

    public function test_convert_rejects_negative_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/non-negative|negative/i');

        $this->cs->convert(-100.0, 'USD', 'EGP');
    }

    public function test_convert_rejects_negative_amount_same_currency(): void
    {
        // Even for same-currency path the guard must fire before the early return
        $this->expectException(\InvalidArgumentException::class);

        $this->cs->convert(-1.0, 'EGP', 'EGP');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bug #17 — zero amount: returns rate=0 (documented, no exception)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_convert_zero_amount_returns_zero_to_amount(): void
    {
        $result = $this->cs->convert(0.0, 'USD', 'EGP');

        $this->assertEquals(0.0, $result['to_amount'],
            'Converting 0 should produce 0 in target currency');
        // rate = 0 is documented behavior for zero-amount calls (no division possible)
        $this->assertArrayHasKey('rate', $result);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Normal positive conversion
    // ─────────────────────────────────────────────────────────────────────────

    public function test_convert_positive_amount_uses_exchange_rate(): void
    {
        $result = $this->cs->convert(100.0, 'USD', 'EGP');

        $this->assertEqualsWithDelta(5000.0, $result['to_amount'], 0.01,
            '100 USD x 50 EGP/USD = 5000 EGP');
        $this->assertEquals(50.0, $result['rate']);
    }

    public function test_convert_same_currency_returns_same_amount(): void
    {
        $result = $this->cs->convert(250.0, 'EGP', 'EGP');

        $this->assertEquals(250.0, $result['to_amount']);
        $this->assertEquals(1.0, $result['rate']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Bug #8 — setExchangeRate rate validation (already fixed, pinned here)
    // ─────────────────────────────────────────────────────────────────────────

    public function test_set_exchange_rate_rejects_zero_rate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/positive/i');

        $this->cs->setExchangeRate([
            'from_currency'  => 'EUR',
            'to_currency'    => 'EGP',
            'rate'           => 0,
            'effective_date' => now()->toDateString(),
        ]);
    }

    public function test_set_exchange_rate_rejects_negative_rate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->cs->setExchangeRate([
            'from_currency'  => 'EUR',
            'to_currency'    => 'EGP',
            'rate'           => -1,
            'effective_date' => now()->toDateString(),
        ]);
    }

    public function test_set_exchange_rate_rejects_null_rate(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->cs->setExchangeRate([
            'from_currency'  => 'EUR',
            'to_currency'    => 'EGP',
            'rate'           => null,
            'effective_date' => now()->toDateString(),
        ]);
    }

    public function test_set_exchange_rate_accepts_valid_positive_rate(): void
    {
        $rate = $this->cs->setExchangeRate([
            'from_currency'  => 'EUR',
            'to_currency'    => 'EGP',
            'rate'           => 54.5,
            'effective_date' => now()->toDateString(),
        ]);

        $this->assertEquals(54.5, (float) $rate->rate);
        $this->assertTrue((bool) $rate->is_active);
    }
}
