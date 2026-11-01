<?php

namespace Tests\Feature\Customer;

use App\Models\Account;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * اختبارات تفرد الهاتف والرقم القومي مع دعم Soft Delete
 *
 * تتحقق من:
 * 1. لا يُنشئ عميلان نشطان بنفس الهاتف (سواء اتفق الرقم القومي أم اختلف)
 * 2. لا يُنشئ عميلان نشطان بنفس الرقم القومي (سواء اتفق الهاتف أم اختلف)
 * 3. يمكن إعادة استخدام هاتف العميل المحذوف (soft-deleted) بنفس الرقم القومي
 * 4. يمكن إعادة استخدام الرقم القومي للعميل المحذوف بنفس الهاتف
 * 5. يُسمح بتعدد العملاء النشطين بـ national_id = NULL
 * 6. تحديث العميل ببياناته الحالية يُقبل — لا يعدّ تعارضاً
 * 7. تحديث العميل لهاتف أو رقم قومي عميل آخر نشط يُرفض (422)
 * 8. الفهرس يمنع التكرار على مستوى MySQL مباشرة (يستقل عن Laravel Validation)
 */
class PhoneSoftDeleteReuseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
    }

    // =========================================================
    // 1. منع التكرار بين العملاء النشطين
    // =========================================================

    /**
     * [TC-01] عميلان نشطان بنفس الهاتف — يُرفض حتى لو الرقم القومي مختلف
     */
    public function test_two_active_customers_same_phone_different_national_id_rejected(): void
    {
        Customer::factory()->create([
            'phone'       => '01011112222',
            'national_id' => '29501011111111',
            'type'        => 'individual',
        ]);

        $response = $this->postJson('/api/v1/customers', [
            'full_name'      => 'عميل مكرر',
            'phone'          => '01011112222',   // نفس الهاتف
            'national_id'    => '30001019999999', // رقم قومي مختلف
            'travel_country' => 'مصر',
            'type'           => 'individual',
        ]);

        $response->assertStatus(422);
    }

    /**
     * [TC-02] عميلان نشطان بنفس الرقم القومي — يُرفض حتى لو الهاتف مختلف
     */
    public function test_two_active_customers_same_national_id_different_phone_rejected(): void
    {
        Customer::factory()->create([
            'phone'       => '01022223333',
            'national_id' => '29601011111111',
            'type'        => 'individual',
        ]);

        $response = $this->postJson('/api/v1/customers', [
            'full_name'      => 'عميل مكرر الرقم القومي',
            'phone'          => '01099998888',   // هاتف مختلف
            'national_id'    => '29601011111111', // نفس الرقم القومي
            'travel_country' => 'مصر',
            'type'           => 'individual',
        ]);

        $response->assertStatus(422);
    }

    // =========================================================
    // 2. إعادة استخدام بيانات العميل المحذوف
    // =========================================================

    /**
     * [TC-03] عميل جديد بنفس هاتف عميل محذوف — يُقبل (201)
     */
    public function test_can_register_customer_with_phone_of_soft_deleted_customer(): void
    {
        $deleted = Customer::factory()->create([
            'phone'       => '01011112222',
            'national_id' => '29501011234567',
            'type'        => 'individual',
        ]);
        $deleted->delete();

        $this->assertSoftDeleted('customers', ['id' => $deleted->id]);

        $response = $this->postJson('/api/v1/customers', [
            'full_name'      => 'عميل جديد بنفس الهاتف',
            'phone'          => '01011112222',
            'national_id'    => '30001015556789',
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
     * [TC-04] عميل جديد بنفس هاتف ونفس رقم قومي لعميل محذوف — يُقبل (201)
     * (الاختبار الحرج: يثبت أن الفهرس يراعي deleted_at)
     */
    public function test_can_register_customer_with_exact_same_phone_and_national_id_of_soft_deleted_customer(): void
    {
        $deleted = Customer::factory()->create([
            'phone'       => '01099990001',
            'national_id' => '29901011234567',
            'type'        => 'individual',
        ]);
        $deleted->delete();

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
            'deleted_at'  => null,
        ]);
        $this->assertSoftDeleted('customers', ['id' => $deleted->id]);

        // عميل ثالث بنفس البيانات يُرفض — لأن هناك نشط
        $this->postJson('/api/v1/customers', [
            'full_name'      => 'عميل ثالث مكرر',
            'phone'          => '01099990001',
            'national_id'    => '29901011234567',
            'travel_country' => 'مصر',
            'type'           => 'individual',
        ])->assertStatus(422);
    }

    /**
     * [TC-05] عميل شركة بنفس هاتف عميل فرد محذوف — يُقبل (201)
     */
    public function test_company_can_register_with_phone_of_deleted_individual(): void
    {
        $deleted = Customer::factory()->create([
            'phone'       => '01055556666',
            'national_id' => '29701031234567',
            'type'        => 'individual',
        ]);
        $deleted->delete();

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

    // =========================================================
    // 3. NULL في الرقم القومي — يُسمح بتكرار NULL
    // =========================================================

    /**
     * [TC-06] عدة عملاء نشطين بـ national_id = NULL — يُقبل جميعهم
     */
    public function test_multiple_active_customers_with_null_national_id_allowed(): void
    {
        // عميل أول بدون رقم قومي
        $this->postJson('/api/v1/customers', [
            'full_name' => 'شركة ألفا',
            'phone'     => '01011110001',
            'type'      => 'counter',
        ])->assertCreated();

        // عميل ثانٍ بدون رقم قومي — يجب أن يُقبل
        $this->postJson('/api/v1/customers', [
            'full_name' => 'شركة بيتا',
            'phone'     => '01011110002',
            'type'      => 'counter',
        ])->assertCreated();

        // عميل ثالث بدون رقم قومي — يجب أن يُقبل
        $this->postJson('/api/v1/customers', [
            'full_name' => 'شركة جاما',
            'phone'     => '01011110003',
            'type'      => 'counter',
        ])->assertCreated();

        $this->assertEquals(3, Customer::whereNull('national_id')->count());
    }

    // =========================================================
    // 4. تحديث العميل
    // =========================================================

    /**
     * [TC-07] تحديث العميل ببياناته الحالية — يُقبل (200)
     */
    public function test_update_customer_with_same_data_accepted(): void
    {
        $customer = Customer::factory()->create([
            'phone'       => '01044445555',
            'national_id' => '29801011111111',
            'type'        => 'individual',
        ]);

        $response = $this->putJson("/api/v1/customers/{$customer->id}", [
            'full_name'      => $customer->full_name,
            'phone'          => '01044445555', // نفس الهاتف
            'national_id'    => '29801011111111', // نفس الرقم القومي
            'travel_country' => 'مصر',
            'type'           => 'individual',
        ]);

        $response->assertStatus(200);
    }

    /**
     * [TC-08] تحديث العميل لهاتف عميل آخر نشط — يُرفض (422)
     */
    public function test_update_customer_to_another_active_customer_phone_rejected(): void
    {
        Customer::factory()->create([
            'phone'       => '01077778888',
            'national_id' => '29901011111111',
            'type'        => 'individual',
        ]);

        $customer = Customer::factory()->create([
            'phone'       => '01011113333',
            'national_id' => '30001011111111',
            'type'        => 'individual',
        ]);

        $response = $this->putJson("/api/v1/customers/{$customer->id}", [
            'full_name'      => $customer->full_name,
            'phone'          => '01077778888', // هاتف عميل آخر نشط
            'national_id'    => '30001011111111',
            'travel_country' => 'مصر',
            'type'           => 'individual',
        ]);

        $response->assertStatus(422);
    }

    // =========================================================
    // 5. إثبات التطبيق على مستوى MySQL (بدون Laravel Validation)
    // =========================================================

    /**
     * [TC-09] INSERT مباشر لهاتف نشط مكرر — يثير QueryException (MySQL level)
     */
    public function test_direct_mysql_insert_duplicate_active_phone_throws_query_exception(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Customer::factory()->create([
            'phone'       => '01066660001',
            'national_id' => '29601011234567',
            'type'        => 'individual',
        ]);

        // INSERT مباشر يتجاوز Laravel Validation تماماً
        DB::table('customers')->insert([
            'full_name'    => 'مكرر مباشر',
            'phone'        => '01066660001', // نفس الهاتف
            'national_id'  => '30001019999999',
            'type'         => 'individual',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    /**
     * [TC-10] INSERT مباشر لرقم قومي نشط مكرر — يثير QueryException (MySQL level)
     */
    public function test_direct_mysql_insert_duplicate_active_national_id_throws_query_exception(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Customer::factory()->create([
            'phone'       => '01066662222',
            'national_id' => '29701015556789',
            'type'        => 'individual',
        ]);

        // INSERT مباشر يتجاوز Laravel Validation تماماً
        DB::table('customers')->insert([
            'full_name'    => 'مكرر رقم قومي مباشر',
            'phone'        => '01066663333', // هاتف مختلف
            'national_id'  => '29701015556789', // نفس الرقم القومي النشط
            'type'         => 'individual',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    // =========================================================
    // 6. الحساب المالي المستقل
    // =========================================================

    /**
     * [TC-11] العميل الجديد يحصل على حساب مالي مستقل عن حساب المحذوف
     */
    public function test_soft_deleted_customer_phone_reuse_does_not_create_duplicate_account(): void
    {
        $deleted = Customer::factory()->create([
            'phone'       => '01033334444',
            'national_id' => '29601021234567',
            'type'        => 'individual',
        ]);
        $deleted->refresh();
        $deletedAccountId = $deleted->account_id;
        $deleted->delete();

        $response = $this->postJson('/api/v1/customers', [
            'full_name'      => 'عميل بديل',
            'phone'          => '01033334444',
            'national_id'    => '30101025556789',
            'travel_country' => 'السعودية',
            'type'           => 'individual',
        ]);

        $response->assertCreated();
        $newCustomerId = $response->json('data.id') ?? $response->json('id');
        $newCustomer   = Customer::findOrFail($newCustomerId);

        $newAccountId = $newCustomer->account_id;
        $this->assertNotNull($newAccountId, 'العميل الجديد يجب أن يكون له حساب مالي');

        $newAccount = Account::find($newAccountId);
        $this->assertNotNull($newAccount, 'سجل الحساب المالي يجب أن يكون موجوداً');

        if ($deletedAccountId) {
            $this->assertNotEquals(
                $deletedAccountId,
                $newAccountId,
                'يجب أن يكون للعميل الجديد حساب منفصل'
            );
        }

        $this->assertEquals(1, Customer::where('phone', '01033334444')->count());
    }
}
