<?php

namespace Tests\Unit\Models\Fawry;

use App\Models\Fawry\FawryOperationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FawryOperationTypeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear migration-seeded data that conflicts with test codes
        \App\Models\Fawry\FawryPaymentMethod::query()->forceDelete();
        \App\Models\Fawry\FawryOperationType::query()->forceDelete();
    }

    public function test_fawry_operation_type_can_be_created()
    {
        $operationType = FawryOperationType::create([
            'code' => 'bill_payment',
            'name_ar' => 'دفع فواتير',
            'name_en' => 'Bill Payment',
            'color' => '#FF5733',
            'icon' => 'heroicon-o-receipt',
            'description_ar' => 'خدمة دفع الفواتير',
            'description_en' => 'Bill payment service',
            'is_active' => true,
            'order' => 1,
        ]);

        $this->assertDatabaseHas('fawry_operation_types', [
            'code' => 'bill_payment',
            'name_ar' => 'دفع فواتير',
        ]);
    }

    public function test_scope_active_returns_only_active_operation_types()
    {
        FawryOperationType::factory()->create([
            'code' => 'active1',
            'is_active' => true,
            'order' => 1,
        ]);

        FawryOperationType::factory()->create([
            'code' => 'active2',
            'is_active' => true,
            'order' => 2,
        ]);

        FawryOperationType::factory()->create([
            'code' => 'inactive',
            'is_active' => false,
            'order' => 3,
        ]);

        $activeTypes = FawryOperationType::active()->get();

        $this->assertCount(2, $activeTypes);
        $this->assertTrue($activeTypes->every('is_active'));
    }

    public function test_scope_active_orders_by_order_field()
    {
        FawryOperationType::factory()->create([
            'code' => 'second',
            'is_active' => true,
            'order' => 2,
        ]);

        FawryOperationType::factory()->create([
            'code' => 'first',
            'is_active' => true,
            'order' => 1,
        ]);

        FawryOperationType::factory()->create([
            'code' => 'third',
            'is_active' => true,
            'order' => 3,
        ]);

        $activeTypes = FawryOperationType::active()->get();

        $this->assertEquals('first', $activeTypes->first()->code);
        $this->assertEquals('third', $activeTypes->last()->code);
    }

    public function test_get_label_attribute_returns_arabic_name()
    {
        $operationType = FawryOperationType::factory()->create([
            'name_ar' => 'دفع فواتير',
            'name_en' => 'Bill Payment',
        ]);

        $this->assertEquals('دفع فواتير', $operationType->label);
    }

    public function test_get_label_en_attribute_returns_english_name()
    {
        $operationType = FawryOperationType::factory()->create([
            'name_ar' => 'دفع فواتير',
            'name_en' => 'Bill Payment',
        ]);

        $this->assertEquals('Bill Payment', $operationType->label_en);
    }

    public function test_is_active_is_cast_to_boolean()
    {
        $operationType = FawryOperationType::factory()->create([
            'is_active' => 1,
        ]);

        $this->assertIsBool($operationType->is_active);
        $this->assertTrue($operationType->is_active);
    }

    public function test_fawry_operation_type_uses_soft_deletes()
    {
        $operationType = FawryOperationType::factory()->create();

        $operationType->delete();

        $this->assertSoftDeleted('fawry_operation_types', [
            'id' => $operationType->id,
        ]);

        $this->assertNotNull(FawryOperationType::withTrashed()->find($operationType->id));
    }

    public function test_unique_code_constraint()
    {
        FawryOperationType::factory()->create([
            'code' => 'bill_payment',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        FawryOperationType::factory()->create([
            'code' => 'bill_payment',
        ]);
    }
}
