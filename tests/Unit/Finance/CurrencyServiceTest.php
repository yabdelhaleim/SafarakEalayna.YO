<?php

namespace Tests\Unit\Finance;

use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\Finance\CurrencyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CurrencyServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected CurrencyService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::query()->create([
            'name' => 'Currency Tester',
            'email' => 'currency-tester@example.com',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        $this->service = app(CurrencyService::class);
        Sanctum::actingAs($this->user, ['*']);
    }

    /**
     * Test direct currency conversion (e.g. USD to EGP).
     * Tests Line 39: $convertedAmount = $amount * $rate->rate;
     */
    public function test_convert_direct_rate(): void
    {
        ExchangeRate::query()->create([
            'from_currency' => 'USD',
            'to_currency' => 'EGP',
            'rate' => 50.00,
            'effective_date' => now(),
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $result = $this->service->convert(100.00, 'USD', 'EGP');

        $this->assertEquals(5000.00, $result['to_amount']);
        $this->assertEquals(50.00, $result['rate']);
    }

    /**
     * Test inverse currency conversion (e.g. EGP to USD using USD to EGP rate).
     * Tests Line 60: $rateValue = 1.0 / $inverseRate->rate;
     * Tests Line 61: $convertedAmount = $amount * $rateValue;
     */
    public function test_convert_inverse_rate(): void
    {
        ExchangeRate::query()->create([
            'from_currency' => 'USD',
            'to_currency' => 'EGP',
            'rate' => 50.00,
            'effective_date' => now(),
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $result = $this->service->convert(5000.00, 'EGP', 'USD');

        $this->assertEquals(100.00, $result['to_amount']);
        $this->assertEquals(0.02, $result['rate']);
    }

    /**
     * Test cross currency conversion (e.g. USD to EUR using EGP as intermediary).
     * Tests Line 86: 'rate' => $amount > 0 ? ($fromEgp['to_amount'] / $amount) : 0.0
     */
    public function test_convert_cross_rate(): void
    {
        // USD -> EGP is 50.00
        ExchangeRate::query()->create([
            'from_currency' => 'USD',
            'to_currency' => 'EGP',
            'rate' => 50.00,
            'effective_date' => now(),
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        // EUR -> EGP is 60.00
        ExchangeRate::query()->create([
            'from_currency' => 'EUR',
            'to_currency' => 'EGP',
            'rate' => 60.00,
            'effective_date' => now(),
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        // Convert 120 USD to EUR
        // 120 USD = 120 * 50 = 6000 EGP
        // 6000 EGP = 6000 * (1/60) = 100 EUR
        // Cross rate = 100 / 120 = 0.8333
        $result = $this->service->convert(120.00, 'USD', 'EUR');

        $this->assertEquals(100.00, round($result['to_amount'], 2));
        $this->assertEquals(0.8333, round($result['rate'], 4));
    }

    /**
     * Test conversion throws exception if no rate is available.
     */
    public function test_convert_throws_exception_if_no_rate(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('لا يوجد سعر صرف متاح');

        $this->service->convert(100.00, 'USD', 'EUR');
    }
}
