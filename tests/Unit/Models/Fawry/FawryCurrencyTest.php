<?php

namespace Tests\Unit\Models\Fawry;

use App\Models\Fawry\FawryCurrency;
use App\Models\Setting\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FawryCurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currency = Currency::factory()->create([
            'code' => 'USD',
            'name_ar' => 'دولار أمريكي',
            'name_en' => 'US Dollar',
        ]);
    }

    public function test_fawry_currency_can_be_created()
    {
        $fawryCurrency = FawryCurrency::create([
            'currency_id' => $this->currency->id,
            'exchange_rate' => 48.50,
            'min_amount' => 10.00,
            'max_amount' => 10000.00,
            'fee_percent' => 2.50,
            'fixed_fee' => 5.00,
            'is_active' => true,
            'order' => 1,
        ]);

        $this->assertDatabaseHas('fawry_currencies', [
            'currency_id' => $this->currency->id,
            'exchange_rate' => 48.50,
        ]);
    }

    public function test_scope_active_returns_only_active_currencies()
    {
        FawryCurrency::factory()->create([
            'currency_id' => $this->currency->id,
            'is_active' => true,
            'order' => 1,
        ]);

        $currency2 = Currency::factory()->create();
        FawryCurrency::factory()->create([
            'currency_id' => $currency2->id,
            'is_active' => true,
            'order' => 2,
        ]);

        $currency3 = Currency::factory()->create();
        FawryCurrency::factory()->create([
            'currency_id' => $currency3->id,
            'is_active' => false,
            'order' => 3,
        ]);

        $activeCurrencies = FawryCurrency::active()->get();

        $this->assertCount(2, $activeCurrencies);
        $this->assertTrue($activeCurrencies->every('is_active'));
    }

    public function test_scope_active_orders_by_order_field()
    {
        $currency2 = Currency::factory()->create();
        $currency3 = Currency::factory()->create();

        FawryCurrency::factory()->create([
            'currency_id' => $currency2->id,
            'is_active' => true,
            'order' => 2,
        ]);

        FawryCurrency::factory()->create([
            'currency_id' => $this->currency->id,
            'is_active' => true,
            'order' => 1,
        ]);

        FawryCurrency::factory()->create([
            'currency_id' => $currency3->id,
            'is_active' => true,
            'order' => 3,
        ]);

        $activeCurrencies = FawryCurrency::active()->get();

        $this->assertEquals($this->currency->id, $activeCurrencies->first()->currency_id);
        $this->assertEquals($currency3->id, $activeCurrencies->last()->currency_id);
    }

    public function test_belongs_to_currency()
    {
        $fawryCurrency = FawryCurrency::factory()->create([
            'currency_id' => $this->currency->id,
        ]);

        $this->assertInstanceOf(Currency::class, $fawryCurrency->currency);
        $this->assertEquals($this->currency->id, $fawryCurrency->currency->id);
    }

    public function test_calculate_fee_with_percent_and_fixed_fee()
    {
        $fawryCurrency = FawryCurrency::factory()->create([
            'currency_id' => $this->currency->id,
            'fee_percent' => 2.50,
            'fixed_fee' => 5.00,
        ]);

        $amount = 1000.00;
        $expectedFee = (1000.00 * 0.025) + 5.00; // 25 + 5 = 30

        $this->assertEquals(30.00, $fawryCurrency->calculateFee($amount));
    }

    public function test_calculate_fee_with_only_percent()
    {
        $fawryCurrency = FawryCurrency::factory()->create([
            'currency_id' => $this->currency->id,
            'fee_percent' => 3.00,
            'fixed_fee' => 0.00,
        ]);

        $amount = 500.00;
        $expectedFee = 500.00 * 0.03; // 15

        $this->assertEquals(15.00, $fawryCurrency->calculateFee($amount));
    }

    public function test_calculate_fee_with_only_fixed_fee()
    {
        $fawryCurrency = FawryCurrency::factory()->create([
            'currency_id' => $this->currency->id,
            'fee_percent' => 0.00,
            'fixed_fee' => 10.00,
        ]);

        $amount = 1000.00;

        $this->assertEquals(10.00, $fawryCurrency->calculateFee($amount));
    }

    public function test_is_amount_valid_within_range()
    {
        $fawryCurrency = FawryCurrency::factory()->create([
            'currency_id' => $this->currency->id,
            'min_amount' => 10.00,
            'max_amount' => 10000.00,
        ]);

        $this->assertTrue($fawryCurrency->isAmountValid(100.00));
        $this->assertTrue($fawryCurrency->isAmountValid(10.00));
        $this->assertTrue($fawryCurrency->isAmountValid(10000.00));
    }

    public function test_is_amount_valid_below_minimum()
    {
        $fawryCurrency = FawryCurrency::factory()->create([
            'currency_id' => $this->currency->id,
            'min_amount' => 10.00,
            'max_amount' => 10000.00,
        ]);

        $this->assertFalse($fawryCurrency->isAmountValid(5.00));
    }

    public function test_is_amount_valid_above_maximum()
    {
        $fawryCurrency = FawryCurrency::factory()->create([
            'currency_id' => $this->currency->id,
            'min_amount' => 10.00,
            'max_amount' => 10000.00,
        ]);

        $this->assertFalse($fawryCurrency->isAmountValid(15000.00));
    }

    public function test_is_amount_valid_with_no_limits()
    {
        $fawryCurrency = FawryCurrency::factory()->create([
            'currency_id' => $this->currency->id,
            'min_amount' => null,
            'max_amount' => null,
        ]);

        $this->assertTrue($fawryCurrency->isAmountValid(0.01));
        $this->assertTrue($fawryCurrency->isAmountValid(999999.99));
    }

    public function test_is_active_is_cast_to_boolean()
    {
        $fawryCurrency = FawryCurrency::factory()->create([
            'currency_id' => $this->currency->id,
            'is_active' => 1,
        ]);

        $this->assertIsBool($fawryCurrency->is_active);
        $this->assertTrue($fawryCurrency->is_active);
    }

    public function test_decimal_fields_are_properly_cast()
    {
        $fawryCurrency = FawryCurrency::factory()->create([
            'currency_id' => $this->currency->id,
            'exchange_rate' => 48.5050,
            'min_amount' => 10.50,
            'max_amount' => 10000.75,
            'fee_percent' => 2.55,
            'fixed_fee' => 5.25,
        ]);

        $this->assertEquals(48.5050, $fawryCurrency->exchange_rate);
        $this->assertEquals(10.50, $fawryCurrency->min_amount);
        $this->assertEquals(10000.75, $fawryCurrency->max_amount);
        $this->assertEquals(2.55, $fawryCurrency->fee_percent);
        $this->assertEquals(5.25, $fawryCurrency->fixed_fee);
    }
}
