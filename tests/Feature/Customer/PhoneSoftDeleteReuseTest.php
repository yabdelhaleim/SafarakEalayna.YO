<?php

namespace Tests\Feature\Customer;

use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * اختبارات إعادة استخدام رقم الهاتف بعد حذف العميل
 *
 * تتحقق من أن:
 * 1. يمكن تسجيل عميل جديد بنفس هاتف عميل محذوف (soft-deleted)
 * 2. لا يُنشأ حساب مالي مكرر عند إعادة الاستخدام
 * 3. كل عميل له حساب مالي (Account) مستقل
 */
class PhoneSoftDeleteReuseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    /**
     * عميل بنفس هاتف عميل محذوف يتسجل بنجاح (201)
     */
    public function test_can_register_customer_with_phone_of_soft_deleted_customer(): void
    {
        // أنشئ عميلاً ثم احذفه
        $deleted = Customer::factory()->create([
            'phone'       => '01011112222',
            'national_id' => '29501011234567',
            'type'        => 'individual',
        ]);
        $deleted->delete(); // soft delete

        $this->assertSoftDeleted('customers', ['id' => $deleted->id]);

        // سجّل عميل جديد بنفس الهاتف لكن رقم قومي مختلف (تجنباً لـ DB unique index المركّب)
        $response = $this->postJson('/api/v1/customers', [
            'full_name'      => 'عميل جديد بنفس الهاتف',
            'phone'          => '01011112222',
            'national_id'    => '30001015556789', // رقم قومي مختلف
            'travel_country' => 'مصر',
            'type'           => 'individual',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('customers', [
            'phone'      => '01011112222',
            'full_name'  => 'عميل جديد بنفس الهاتف',
            'deleted_at' => null,
        ]);
    }

    /**
     * العميل الجديد يحصل على حساب مالي مستقل — لا يوجد تكرار
     */
    public function test_soft_deleted_customer_phone_reuse_does_not_create_duplicate_account(): void
    {
        // أنشئ عميلاً أولاً ثم احذفه
        $deleted = Customer::factory()->create([
            'phone'       => '01033334444',
            'national_id' => '29601021234567',
            'type'        => 'individual',
        ]);
        $deleted->refresh();
        $deletedAccountId = $deleted->account_id;
        $deleted->delete();

        // سجّل عميل جديد بنفس الهاتف
        $response = $this->postJson('/api/v1/customers', [
            'full_name'      => 'عميل بديل',
            'phone'          => '01033334444',
            'national_id'    => '30101025556789', // مختلف لتجنب قيد قاعدة البيانات المركب الحالي
            'travel_country' => 'السعودية',
            'type'           => 'individual',
        ]);

        $response->assertCreated();
        $newCustomerId = $response->json('data.id') ?? $response->json('id');
        $newCustomer   = Customer::findOrFail($newCustomerId);

        // العميل الجديد له حساب مستقل
        $newAccountId = $newCustomer->account_id;
        $this->assertNotNull($newAccountId, 'العميل الجديد يجب أن يكون له حساب مالي');

        $newAccount = Account::find($newAccountId);
        $this->assertNotNull($newAccount, 'سجل الحساب المالي يجب أن يكون موجوداً في قاعدة البيانات');

        // الحساب الجديد مختلف عن حساب العميل المحذوف
        if ($deletedAccountId) {
            $this->assertNotEquals(
                $deletedAccountId,
                $newAccountId,
                'يجب أن يكون للعميل الجديد حساب منفصل وليس نفس حساب العميل المحذوف'
            );
        }

        // لا يوجد سوى عميل نشط واحد بهذا الهاتف
        $activeCustomersWithPhone = Customer::where('phone', '01033334444')->count();
        $this->assertEquals(1, $activeCustomersWithPhone, 'يجب أن يكون هناك عميل نشط واحد فقط بهذا الهاتف');
    }

    /**
     * الاختبار الذهبي: عميل شركة (counter) بنفس هاتف عميل فرد محذوف
     */
    public function test_company_can_register_with_phone_of_deleted_individual(): void
    {
        $deleted = Customer::factory()->create([
            'phone'       => '01055556666',
            'national_id' => '29701031234567',
            'type'        => 'individual',
        ]);
        $deleted->delete();

        // شركة بنفس الهاتف — بدون national_id لأن الشركات لا تحتاجه
        $response = $this->postJson('/api/v1/customers', [
            'full_name' => 'شركة النيل للسياحة',
            'phone'     => '01055556666',
            'type'      => 'counter',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('customers', [
            'phone'      => '01055556666',
            'type'       => 'company',
            'deleted_at' => null,
        ]);
    }

    /**
     * عميل بنفس الهاتف ونفس الرقم القومي لعميل محذوف يتسجل بنجاح (201)
     * بعد تطبيق الـ migration الذي يراعي deleted_at في الفهرس الفريد
     */
    public function test_can_register_customer_with_exact_same_phone_and_national_id_of_soft_deleted_customer(): void
    {
        $deleted = Customer::factory()->create([
            'phone'       => '01099990001',
            'national_id' => '29901011234567',
            'type'        => 'individual',
        ]);
        $deleted->delete();

        // تسجيل عميل جديد بنفس الهاتف ونفس الرقم القومي
        $response = $this->postJson('/api/v1/customers', [
            'full_name'      => 'عميل جديد بنفس البيانات تماماً',
            'phone'          => '01099990001',
            'national_id'    => '29901011234567',
            'travel_country' => 'مصر',
            'type'           => 'individual',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('customers', [
            'phone'       => '01099990001',
            'national_id' => '29901011234567',
            'full_name'   => 'عميل جديد بنفس البيانات تماماً',
            'deleted_at'  => null,
        ]);

        // والتأكد أن العميل المحذوف لا يزال موجوداً ومحذوفاً
        $this->assertSoftDeleted('customers', ['id' => $deleted->id]);

        // والتأكد أن محاولة تسجيل عميل نشط آخر بنفس البيانات تفشل (422)
        $duplicateResponse = $this->postJson('/api/v1/customers', [
            'full_name'      => 'عميل ثالث مكرر',
            'phone'          => '01099990001',
            'national_id'    => '29901011234567',
            'travel_country' => 'مصر',
            'type'           => 'individual',
        ]);
        $duplicateResponse->assertStatus(422);
    }
}
