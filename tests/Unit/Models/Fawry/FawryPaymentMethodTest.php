<?php

namespace Tests\Unit\Models\Fawry;

use App\Models\Account;
use App\Models\Fawry\FawryPaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FawryPaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    protected Account $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = Account::factory()->create();
    }

    public function test_fawry_payment_method_can_be_created()
    {
        $paymentMethod = FawryPaymentMethod::create([
            'code' => 'cash',
            'name_ar' => 'نقدي',
            'name_en' => 'Cash',
            'color' => '#28a745',
            'icon' => 'heroicon-o-currency-dollar',
            'description_ar' => 'دفع نقدي',
            'description_en' => 'Cash payment',
            'provider_name' => 'Fawry',
            'account_number' => '123456',
            'phone_number' => '01000000000',
            'bank_name' => 'Bank Misr',
            'branch_name' => 'Main Branch',
            'default_account_id' => $this->account->id,
            'is_active' => true,
            'order' => 1,
        ]);

        $this->assertDatabaseHas('fawry_payment_methods', [
            'code' => 'cash',
            'name_ar' => 'نقدي',
        ]);
    }

    public function test_scope_active_returns_only_active_payment_methods()
    {
        FawryPaymentMethod::factory()->create([
            'code' => 'active1',
            'is_active' => true,
            'order' => 1,
        ]);

        FawryPaymentMethod::factory()->create([
            'code' => 'active2',
            'is_active' => true,
            'order' => 2,
        ]);

        FawryPaymentMethod::factory()->create([
            'code' => 'inactive',
            'is_active' => false,
            'order' => 3,
        ]);

        $activeMethods = FawryPaymentMethod::active()->get();

        $this->assertCount(2, $activeMethods);
        $this->assertTrue($activeMethods->every('is_active'));
    }

    public function test_scope_active_orders_by_order_field()
    {
        FawryPaymentMethod::factory()->create([
            'code' => 'second',
            'is_active' => true,
            'order' => 2,
        ]);

        FawryPaymentMethod::factory()->create([
            'code' => 'first',
            'is_active' => true,
            'order' => 1,
        ]);

        FawryPaymentMethod::factory()->create([
            'code' => 'third',
            'is_active' => true,
            'order' => 3,
        ]);

        $activeMethods = FawryPaymentMethod::active()->get();

        $this->assertEquals('first', $activeMethods->first()->code);
        $this->assertEquals('third', $activeMethods->last()->code);
    }

    public function test_belongs_to_default_account()
    {
        $paymentMethod = FawryPaymentMethod::factory()->create([
            'default_account_id' => $this->account->id,
        ]);

        $this->assertInstanceOf(Account::class, $paymentMethod->defaultAccount);
        $this->assertEquals($this->account->id, $paymentMethod->defaultAccount->id);
    }

    public function test_get_label_attribute_returns_arabic_name()
    {
        $paymentMethod = FawryPaymentMethod::factory()->create([
            'name_ar' => 'نقدي',
            'name_en' => 'Cash',
        ]);

        $this->assertEquals('نقدي', $paymentMethod->label);
    }

    public function test_get_label_en_attribute_returns_english_name()
    {
        $paymentMethod = FawryPaymentMethod::factory()->create([
            'name_ar' => 'نقدي',
            'name_en' => 'Cash',
        ]);

        $this->assertEquals('Cash', $paymentMethod->label_en);
    }

    public function test_get_full_details_attribute()
    {
        $paymentMethod = FawryPaymentMethod::factory()->create([
            'name_ar' => 'محفظة كاش',
            'provider_name' => 'Fawry',
            'bank_name' => 'Bank Misr',
            'account_number' => '1234567890',
            'phone_number' => '01000000000',
        ]);

        $fullDetails = $paymentMethod->full_details;

        $this->assertStringContainsString('محفظة كاش', $fullDetails);
        $this->assertStringContainsString('Fawry', $fullDetails);
        $this->assertStringContainsString('Bank Misr', $fullDetails);
        $this->assertStringContainsString('حساب: 1234567890', $fullDetails);
        $this->assertStringContainsString('رقم: 01000000000', $fullDetails);
    }

    public function test_get_full_details_attribute_with_partial_data()
    {
        $paymentMethod = FawryPaymentMethod::factory()->create([
            'name_ar' => 'نقدي',
            'provider_name' => null,
            'bank_name' => null,
            'account_number' => null,
            'phone_number' => null,
        ]);

        $fullDetails = $paymentMethod->full_details;

        $this->assertEquals('نقدي', $fullDetails);
    }

    public function test_is_active_is_cast_to_boolean()
    {
        $paymentMethod = FawryPaymentMethod::factory()->create([
            'is_active' => 1,
        ]);

        $this->assertIsBool($paymentMethod->is_active);
        $this->assertTrue($paymentMethod->is_active);
    }

    public function test_metadata_is_cast_to_array()
    {
        $metadata = [
            'processing_time' => 'instant',
            'fees' => 0.0,
        ];

        $paymentMethod = FawryPaymentMethod::factory()->create([
            'metadata' => $metadata,
        ]);

        $this->assertIsArray($paymentMethod->metadata);
        $this->assertEquals('instant', $paymentMethod->metadata['processing_time']);
    }

    public function test_fawry_payment_method_uses_soft_deletes()
    {
        $paymentMethod = FawryPaymentMethod::factory()->create();

        $paymentMethod->delete();

        $this->assertSoftDeleted('fawry_payment_methods', [
            'id' => $paymentMethod->id,
        ]);

        $this->assertNotNull(FawryPaymentMethod::withTrashed()->find($paymentMethod->id));
    }
}
