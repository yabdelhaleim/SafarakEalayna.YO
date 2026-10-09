<?php

namespace Tests\Feature\Online;

use App\Enums\AccountType;
use App\Enums\OnlineTransactionStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Online\OnlineServiceType;
use App\Models\Online\OnlineTransaction;
use App\Models\Setting\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Online\OnlineTransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/**
 * Advanced audit tests for Online transaction operations.
 *
 * Covers:
 *   - Operation 26: POST /online/transactions — all valid variations
 *   - Operation 28: PUT/PATCH /online/transactions/{id} — every field change + every status transition
 *   - Operation 27: GET /online/transactions/{id}
 *   - Operation 25: GET /online/transactions (filters)
 *   - Operation 29: DELETE /online/transactions/{id} (admin-only)
 *   - Walk-in AR edge cases
 *   - EGP-only guard
 *   - Customer-balance / customer-statement reports
 *
 * Methodology: every variation the real code supports is exercised; the
 * financial invariant (per-transaction debit=credit, cached balance tracks
 * the GL) is asserted after every mutation.
 */
class OnlineTransactionAdvancedAuditTest extends OnlineTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // DELETE /online/transactions/{id} is gated by role:admin middleware.
        // The OnlineTestCase base creates a plain User::factory() user without
        // a role; promote it to admin so DELETE passes.
        $this->user->role = 'admin';
        $this->user->is_active = true;
        $this->user->save();

        Sanctum::actingAs($this->user, ['*']);
    }

    // ============================================================
    // Operation 26 — POST /online/transactions (valid variations)
    // ============================================================

    public function test_create_with_registered_customer_full_payment(): void
    {
        $customer = $this->makeCustomer('عميل مسجل', '01000000001');

        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'customer_phone' => $customer->phone,
            'purchase_price' => 100,
            'selling_price' => 200,
            'amount_paid' => 200,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame(100.0, (float) $response->json('data.profit'));
        $this->assertOnlineLedgerBalanced();
    }

    public function test_create_with_registered_customer_partial_payment(): void
    {
        $customer = $this->makeCustomer('عميل جزئي', '01000000002');

        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_id' => $customer->id,
            'purchase_price' => 50,
            'selling_price' => 200,
            'amount_paid' => 60,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(201);
        $this->assertOnlineLedgerBalanced();
    }

    public function test_create_walk_in_auto_creates_customer(): void
    {
        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'ولوك إن جديد',
            'customer_phone' => '01000000003',
            'purchase_price' => 0,
            'selling_price' => 150,
            'amount_paid' => 150,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(201);
        $this->assertNotNull($response->json('data.customer_id'));
        $this->assertDatabaseHas('customers', [
            'full_name' => 'ولوك إن جديد',
            'module_type' => 'online',
        ]);
    }

    public function test_create_walk_in_partial_creates_ar_debt(): void
    {
        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'ولوك إن جزئي',
            'customer_phone' => '01000000004',
            'purchase_price' => 0,
            'selling_price' => 400,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(201);
        $this->assertOnlineLedgerBalanced();
    }

    public function test_create_without_provider_is_allowed(): void
    {
        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'customer_name' => 'بدون مزود',
            'customer_phone' => '01000000005',
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(201);
        $this->assertNull($response->json('data.provider'));
    }

    public function test_create_with_bank_payment_method_and_bank_account(): void
    {
        $bankMethod = PaymentMethod::firstOrCreate(
            ['code' => 'bank_transfer'],
            ['name_ar' => 'تحويل بنكي', 'name_en' => 'Bank Transfer', 'is_active' => true, 'order' => 2],
        );

        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'بنك',
            'customer_phone' => '01000000006',
            'purchase_price' => 50,
            'selling_price' => 150,
            'amount_paid' => 150,
            'payment_method' => 'bank_transfer',
            'account_id' => $this->bank->id,
        ]);

        $response->assertStatus(201);
        $this->assertOnlineLedgerBalanced();
    }

    public function test_create_with_wallet_payment_method_and_wallet_account(): void
    {
        $walletMethod = PaymentMethod::firstOrCreate(
            ['code' => 'cash_wallet'],
            ['name_ar' => 'محفظة', 'name_en' => 'Cash Wallet', 'is_active' => true, 'order' => 3],
        );

        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'محفظة',
            'customer_phone' => '01000000007',
            'purchase_price' => 50,
            'selling_price' => 150,
            'amount_paid' => 150,
            'payment_method' => 'cash_wallet',
            'account_id' => $this->wallet->id,
        ]);

        $response->assertStatus(201);
        $this->assertOnlineLedgerBalanced();
    }

    public function test_create_with_pending_status_does_not_post_gl(): void
    {
        $vaultBefore = $this->accountBalance($this->cashbox->id);

        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'معلق',
            'customer_phone' => '01000000008',
            'purchase_price' => 50,
            'selling_price' => 150,
            'amount_paid' => 150,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'pending',
        ]);

        $response->assertStatus(201);
        $this->assertSame('pending', $response->json('data.status'));
        // Pending tx: vault should NOT have changed
        $this->assertEqualsWithDelta(
            $vaultBefore,
            $this->accountBalance($this->cashbox->id),
            0.01,
            'Pending tx must NOT move vault money.',
        );
    }

    public function test_create_with_zero_selling_and_zero_purchase(): void
    {
        // Edge: zero-priced service (e.g. free attestation)
        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'customer_name' => 'مجاني',
            'customer_phone' => '01000000009',
            'purchase_price' => 0,
            'selling_price' => 0,
            'amount_paid' => 0,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(201);
        $this->assertOnlineLedgerBalanced();
    }

    public function test_create_rejects_inactive_service_type(): void
    {
        // Post-2026-08-30: service_type_code is a free-text field (see migration
        // 2026_08_28_000000_convert_online_service_type_and_provider_to_text).
        // The is_active flag on online_service_types is now a SOFT signal only —
        // the create() service no longer rejects inactive codes (it stores the
        // string verbatim). Verify the new contract: an inactive code is
        // accepted and the row is created, with the code preserved as-is.
        $inactive = OnlineServiceType::firstOrCreate(
            ['code' => 'inactive_type'],
            ['name_ar' => 'معطل', 'name_en' => 'Inactive', 'is_active' => false],
        );

        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $inactive->code,
            'customer_name' => 'X',
            'customer_phone' => '01000000010',
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(201);
        $this->assertSame('inactive_type', $response->json('data.service_type_code'));
    }

    public function test_create_rejects_nonexistent_account(): void
    {
        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'customer_name' => 'X',
            'customer_phone' => '01000000011',
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => 999999,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('account_id', (array) $response->json('errors'));
    }

    public function test_create_rejects_walkin_without_phone(): void
    {
        // Phase 11 walk-in guard: customer_phone required when no customer_id
        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'customer_name' => 'بدون هاتف',
            // no customer_phone
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('customer_phone', (array) $response->json('errors'));
    }

    public function test_create_rejects_walkin_without_name(): void
    {
        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'customer_phone' => '01000000099',
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('customer_name', (array) $response->json('errors'));
    }

    public function test_create_rejects_negative_prices(): void
    {
        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'customer_name' => 'سالب',
            'customer_phone' => '01000000100',
            'purchase_price' => -10,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('purchase_price', (array) $response->json('errors'));
    }

    public function test_create_rejects_inactive_payment_method(): void
    {
        $badMethod = PaymentMethod::firstOrCreate(
            ['code' => 'inactive_method'],
            ['name_ar' => 'معطل', 'name_en' => 'Inactive', 'is_active' => false],
        );

        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'customer_name' => 'X',
            'customer_phone' => '01000000101',
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'inactive_method',
            'account_id' => $this->cashbox->id,
        ]);

        $response->assertStatus(422);
    }

    public function test_create_rejects_payment_method_account_type_mismatch(): void
    {
        // cash requires cashbox; bank requires bank. Mismatching → 422.
        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'customer_name' => 'X',
            'customer_phone' => '01000000102',
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->bank->id, // ← bank with cash
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('account_id', (array) $response->json('errors'));
    }

    public function test_create_rejects_tourism_account(): void
    {
        $tourism = Account::factory()->active()->create([
            'name' => 'سياحة',
            'type' => AccountType::Cashbox,
            'currency' => 'EGP',
            'module_type' => 'tourism',
        ]);

        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'customer_name' => 'X',
            'customer_phone' => '01000000103',
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $tourism->id,
        ]);

        $response->assertStatus(422);
        $this->assertArrayHasKey('account_id', (array) $response->json('errors'));
    }

    public function test_create_rejects_usd_vault(): void
    {
        // The service's assertCurrencyCompatible throws InvalidArgumentException
        // BEFORE the FormRequest validation runs the OnlineLiquidityAccount
        // rule — so the controller's try/catch wraps it into a 422 with the
        // raw message (no structured errors). We assert the Arabic message
        // contains the EGP guard word.
        $response = $this->postJson('/api/v1/online/transactions', [
            'service_type_code' => $this->serviceType->code,
            'customer_name' => 'X',
            'customer_phone' => '01000000104',
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->usdCashbox->id,
        ]);

        $response->assertStatus(422);
        $message = (string) $response->json('message');
        $this->assertStringContainsString('EGP', $message);
    }

    // ============================================================
    // Operation 28 — PATCH /online/transactions/{id} (every variation)
    // ============================================================

    public function test_patch_selling_price_reposts_income(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Patch Test',
            'customer_phone' => '01000000200',
            'purchase_price' => 50,
            'selling_price' => 200,
            'amount_paid' => 200,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);
        $oldIncomeId = $tx->income_transaction_id;

        $response = $this->patchJson("/api/v1/online/transactions/{$tx->id}", [
            'selling_price' => 400,
        ]);

        $response->assertOk();
        $tx->refresh();
        $this->assertSame(400.0, (float) $tx->selling_price);
        $this->assertSame(350.0, (float) $tx->profit);
        $this->assertNotSame($oldIncomeId, $tx->income_transaction_id);
        $this->assertOnlineLedgerBalanced();
    }

    public function test_patch_purchase_price_reposts_expense(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Patch Purchase',
            'customer_phone' => '01000000201',
            'purchase_price' => 100,
            'selling_price' => 300,
            'amount_paid' => 300,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);
        $oldExpenseId = $tx->expense_transaction_id;

        $response = $this->patchJson("/api/v1/online/transactions/{$tx->id}", [
            'purchase_price' => 200,
        ]);

        $response->assertOk();
        $tx->refresh();
        $this->assertSame(200.0, (float) $tx->purchase_price);
        $this->assertSame(100.0, (float) $tx->profit);
        $this->assertNotSame($oldExpenseId, $tx->expense_transaction_id);
        $this->assertOnlineLedgerBalanced();
    }

    public function test_patch_amount_paid_reposts_cash_settlement(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Patch Paid',
            'customer_phone' => '01000000202',
            'purchase_price' => 0,
            'selling_price' => 300,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);
        $vaultBefore = $this->accountBalance($this->cashbox->id);

        $response = $this->patchJson("/api/v1/online/transactions/{$tx->id}", [
            'amount_paid' => 250,
        ]);

        $response->assertOk();
        $vaultAfter = $this->accountBalance($this->cashbox->id);
        $this->assertEqualsWithDelta(150.0, $vaultAfter - $vaultBefore, 0.01);
        $this->assertOnlineLedgerBalanced();
    }

    public function test_patch_notes_does_not_repost(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Patch Notes',
            'customer_phone' => '01000000203',
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);
        $oldIncomeId = $tx->income_transaction_id;
        $oldExpenseId = $tx->expense_transaction_id;

        $response = $this->patchJson("/api/v1/online/transactions/{$tx->id}", [
            'notes' => 'تحديث الملاحظات',
        ]);

        $response->assertOk();
        $tx->refresh();
        $this->assertSame('تحديث الملاحظات', $tx->notes);
        // Notes only — no financial repost should occur.
        $this->assertSame($oldIncomeId, $tx->income_transaction_id);
        $this->assertSame($oldExpenseId, $tx->expense_transaction_id);
    }

    public function test_patch_reference_number_does_not_repost(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Patch Ref',
            'customer_phone' => '01000000204',
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response = $this->patchJson("/api/v1/online/transactions/{$tx->id}", [
            'reference_number' => 'REF-NEW-2026',
        ]);

        $response->assertOk();
        $tx->refresh();
        $this->assertSame('REF-NEW-2026', $tx->reference_number);
        $this->assertOnlineLedgerBalanced();
    }

    public function test_patch_status_pending_to_completed_posts_gl(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Flip Pending',
            'customer_phone' => '01000000205',
            'purchase_price' => 50,
            'selling_price' => 150,
            'amount_paid' => 0,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'pending',
        ]);
        $this->assertNull($tx->income_transaction_id, 'pending must have no GL');

        $response = $this->patchJson("/api/v1/online/transactions/{$tx->id}", [
            'status' => 'completed',
        ]);

        $response->assertOk();
        $tx->refresh();
        $this->assertSame(OnlineTransactionStatus::Completed, $tx->status);
        $this->assertNotNull($tx->income_transaction_id, 'Completed must publish GL');
        $this->assertOnlineLedgerBalanced();
    }

    public function test_patch_status_completed_to_cancelled_reverses_gl(): void
    {
        $vaultBaseline = $this->accountBalance($this->cashbox->id);

        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Cancel via PATCH',
            'customer_phone' => '01000000206',
            'purchase_price' => 100,
            'selling_price' => 300,
            'amount_paid' => 200,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        // After create: vault should have moved (net +100 = amount_paid - purchase)
        $vaultAfterCreate = $this->accountBalance($this->cashbox->id);
        $this->assertEqualsWithDelta(
            $vaultBaseline + 100.0,
            $vaultAfterCreate,
            0.01,
            'Create must move the vault by amount_paid − purchase = 200 − 100 = 100.',
        );

        $response = $this->patchJson("/api/v1/online/transactions/{$tx->id}", [
            'status' => 'cancelled',
        ]);

        $response->assertOk();
        $tx->refresh();
        $this->assertSame(OnlineTransactionStatus::Cancelled, $tx->status);
        $vaultAfterPatch = $this->accountBalance($this->cashbox->id);
        $this->assertEqualsWithDelta(
            $vaultBaseline,
            $vaultAfterPatch,
            0.01,
            'Cancel must reverse the create — vault should return to pre-create baseline.',
        );
        $this->assertOnlineLedgerBalanced();
    }

    public function test_patch_status_cancelled_to_completed_reposts_gl(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Reopen',
            'customer_phone' => '01000000207',
            'purchase_price' => 50,
            'selling_price' => 150,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'pending',
        ]);

        $this->service->update($tx, ['status' => 'cancelled']);
        $tx->refresh();
        $this->assertSame(OnlineTransactionStatus::Cancelled, $tx->status);

        $response = $this->patchJson("/api/v1/online/transactions/{$tx->id}", [
            'status' => 'completed',
        ]);

        $response->assertOk();
        $tx->refresh();
        $this->assertSame(OnlineTransactionStatus::Completed, $tx->status);
        $this->assertNotNull($tx->income_transaction_id, 'Reopen must re-post GL');
        $this->assertOnlineLedgerBalanced();
    }

    public function test_patch_status_pending_to_failed(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Fail',
            'customer_phone' => '01000000208',
            'purchase_price' => 50,
            'selling_price' => 150,
            'amount_paid' => 0,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'status' => 'pending',
        ]);

        $response = $this->patchJson("/api/v1/online/transactions/{$tx->id}", [
            'status' => 'failed',
            'failure_reason' => 'رفض العميل',
        ]);

        $response->assertOk();
        $tx->refresh();
        $this->assertSame(OnlineTransactionStatus::Failed, $tx->status);
        $this->assertSame('رفض العميل', $tx->failure_reason);
        $this->assertOnlineLedgerBalanced();
    }

    public function test_patch_completed_to_pending_via_status_change(): void
    {
        // Edge: can we go from Completed → Pending? This would have to
        // reverse the GL. The OnlineTransactionService::update gates this
        // by reversing all linked transactions when originalStatus=Completed
        // and newStatus != Completed. So the GL should reverse correctly.
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Reset to Pending',
            'customer_phone' => '01000000209',
            'purchase_price' => 50,
            'selling_price' => 150,
            'amount_paid' => 150,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response = $this->patchJson("/api/v1/online/transactions/{$tx->id}", [
            'status' => 'pending',
        ]);

        $response->assertOk();
        $tx->refresh();
        $this->assertSame(OnlineTransactionStatus::Pending, $tx->status);
        $this->assertOnlineLedgerBalanced();
    }

    public function test_patch_change_vault_reposts_cash_settlement(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Swap Vault',
            'customer_phone' => '01000000210',
            'purchase_price' => 0,
            'selling_price' => 300,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        // Add a bank_transfer payment method (only cash is seeded).
        PaymentMethod::firstOrCreate(
            ['code' => 'bank_transfer'],
            ['name_ar' => 'تحويل بنكي', 'name_en' => 'Bank Transfer', 'is_active' => true, 'order' => 2],
        );

        $cashBefore = $this->accountBalance($this->cashbox->id);
        $bankBefore = $this->accountBalance($this->bank->id);

        // Swap both payment_method AND account_id together (mismatched
        // pair is rejected by the validator — see the rejection test below).
        $response = $this->patchJson("/api/v1/online/transactions/{$tx->id}", [
            'payment_method' => 'bank_transfer',
            'account_id' => $this->bank->id,
        ]);

        $response->assertOk();
        $tx->refresh();
        $this->assertSame($this->bank->id, $tx->account_id);
        $this->assertOnlineLedgerBalanced();
        $this->assertEqualsWithDelta(
            $cashBefore - 100,
            $this->accountBalance($this->cashbox->id),
            0.01,
            'Cash vault should drop by 100 (cash settlement reversed).',
        );
        $this->assertEqualsWithDelta(
            $bankBefore + 100,
            $this->accountBalance($this->bank->id),
            0.01,
            'Bank vault should gain 100 (new cash settlement).',
        );
    }

    public function test_patch_change_payment_method_validates_liquidity(): void
    {
        // Setup: cash + cashbox
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Payment Method Swap',
            'customer_phone' => '01000000211',
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        // Try to switch to bank_transfer while keeping cashbox — should fail
        // because cashbox doesn't match bank_transfer's expected account type.
        $response = $this->patchJson("/api/v1/online/transactions/{$tx->id}", [
            'payment_method' => 'bank_transfer',
        ]);

        $response->assertStatus(422);
    }

    // ============================================================
    // Operation 27 — GET /online/transactions/{id}
    // ============================================================

    public function test_show_returns_full_transaction_with_relations(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Show',
            'customer_phone' => '01000000300',
            'purchase_price' => 50,
            'selling_price' => 150,
            'amount_paid' => 150,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response = $this->getJson("/api/v1/online/transactions/{$tx->id}");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertSame($tx->id, $data['id']);
        $this->assertArrayHasKey('service_type', $data);
        $this->assertArrayHasKey('provider', $data);
        $this->assertArrayHasKey('payment_method', $data);
        $this->assertArrayHasKey('status', $data);
    }

    public function test_show_404_for_nonexistent(): void
    {
        $response = $this->getJson('/api/v1/online/transactions/999999');
        $response->assertStatus(404);
    }

    // ============================================================
    // Operation 25 — GET /online/transactions (filters)
    // ============================================================

    public function test_index_filters_by_status(): void
    {
        $c1 = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'customer_name' => 'A', 'customer_phone' => '01000000400',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);
        $p1 = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'customer_name' => 'B', 'customer_phone' => '01000000401',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/v1/online/transactions?status=pending');
        $response->assertOk();
        $items = $response->json('data.items');
        $ids = collect($items)->pluck('id')->all();
        $this->assertContains($p1->id, $ids);
        $this->assertNotContains($c1->id, $ids);
    }

    public function test_index_filters_by_service_type(): void
    {
        $otherType = OnlineServiceType::firstOrCreate(
            ['code' => 'other_type'],
            ['name_ar' => 'آخر', 'name_en' => 'Other', 'is_active' => true],
        );
        $a = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'customer_name' => 'A', 'customer_phone' => '01000000402',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);
        $b = $this->service->create([
            'service_type_code' => $otherType->code,
            'customer_name' => 'B', 'customer_phone' => '01000000403',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        $response = $this->getJson("/api/v1/online/transactions?service_type_code={$this->serviceType->code}");
        $response->assertOk();
        $ids = collect($response->json('data.items'))->pluck('id')->all();
        $this->assertContains($a->id, $ids);
        $this->assertNotContains($b->id, $ids);
    }

    public function test_index_search_matches_name_phone_reference(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'customer_name' => 'اسم فريد',
            'customer_phone' => '01122334455',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
            'reference_number' => 'REF-SPECIAL',
        ]);

        $byName = $this->getJson('/api/v1/online/transactions?search=فريد')->assertOk();
        $this->assertContains($tx->id, collect($byName->json('data.items'))->pluck('id')->all());

        $byPhone = $this->getJson('/api/v1/online/transactions?search=01122')->assertOk();
        $this->assertContains($tx->id, collect($byPhone->json('data.items'))->pluck('id')->all());

        $byRef = $this->getJson('/api/v1/online/transactions?search=SPECIAL')->assertOk();
        $this->assertContains($tx->id, collect($byRef->json('data.items'))->pluck('id')->all());
    }

    public function test_index_with_trashed_includes_cancelled(): void
    {
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'customer_name' => 'To Cancel',
            'customer_phone' => '01000000410',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);
        $this->service->delete($tx);

        // Default: cancelled NOT visible
        $defaultResp = $this->getJson('/api/v1/online/transactions')->assertOk();
        $defaultIds = collect($defaultResp->json('data.items'))->pluck('id')->all();
        $this->assertNotContains($tx->id, $defaultIds);

        // With with_trashed: cancelled visible
        $trashedResp = $this->getJson('/api/v1/online/transactions?with_trashed=1')->assertOk();
        $trashedIds = collect($trashedResp->json('data.items'))->pluck('id')->all();
        $this->assertContains($tx->id, $trashedIds);
    }

    // ============================================================
    // Operation 29 — DELETE /online/transactions/{id}
    // ============================================================

    public function test_delete_soft_deletes_and_reverses_gl(): void
    {
        $vaultBefore = $this->accountBalance($this->cashbox->id);

        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'provider_code' => $this->provider->code,
            'customer_name' => 'Delete Test',
            'customer_phone' => '01000000500',
            'purchase_price' => 100,
            'selling_price' => 300,
            'amount_paid' => 200,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response = $this->deleteJson("/api/v1/online/transactions/{$tx->id}");

        $response->assertOk();
        $tx->refresh();
        $this->assertNotNull($tx->deleted_at, 'soft-deleted');
        $this->assertSame(OnlineTransactionStatus::Cancelled, $tx->status);
        $this->assertNotNull($tx->cancelled_by);
        $this->assertNotNull($tx->cancelled_at);
        $this->assertEqualsWithDelta(
            $vaultBefore,
            $this->accountBalance($this->cashbox->id),
            0.01,
        );
        $this->assertOnlineLedgerBalanced();
    }

    public function test_delete_is_idempotent_at_service_level(): void
    {
        // FINDING: HTTP DELETE is NOT idempotent in practice because Laravel's
        // route model binding does `findOrFail` which scopes out soft-deleted
        // rows. Once the first DELETE soft-deletes the row, a second DELETE
        // request hits the route binding and returns 404 BEFORE the
        // controller's idempotency guard runs.
        //
        // The service-level idempotency guard IS correct (it returns true
        // when already-deleted without touching the GL). We test it here
        // to confirm the underlying contract.
        $vaultBaseline = $this->accountBalance($this->cashbox->id);

        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'customer_name' => 'Idem',
            'customer_phone' => '01000000501',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        // After create: vault should have moved by amount_paid − purchase = 100 − 0 = +100
        $vaultAfterCreate = $this->accountBalance($this->cashbox->id);
        $this->assertEqualsWithDelta($vaultBaseline + 100.0, $vaultAfterCreate, 0.01);

        // First delete — reverses GL, vault returns to baseline
        $this->service->delete($tx);
        $this->assertNotNull($tx->fresh()->deleted_at);

        $vaultAfterFirst = $this->accountBalance($this->cashbox->id);
        $this->assertEqualsWithDelta($vaultBaseline, $vaultAfterFirst, 0.01);

        // Second delete via service (passing the soft-deleted model directly)
        $tx2 = OnlineTransaction::withTrashed()->find($tx->id);
        $result = $this->service->delete($tx2);
        $this->assertTrue($result, 'Service delete should return true on already-deleted row');

        $vaultAfterSecond = $this->accountBalance($this->cashbox->id);
        $this->assertEqualsWithDelta(
            $vaultAfterFirst,
            $vaultAfterSecond,
            0.01,
            'Second service-level delete must NOT reverse GL again.',
        );
    }

    public function test_http_delete_is_idempotent_at_http_layer(): void
    {
        // F-3 fix: HTTP DELETE is now idempotent. The controller resolves
        // the model with withTrashed() so the second DELETE reaches the
        // service which sees the row is already soft-deleted and returns
        // success without reversing GL again.
        $vaultBaseline = $this->accountBalance($this->cashbox->id);

        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'customer_name' => 'HTTP Idem',
            'customer_phone' => '01000000502',
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        // After create: vault = baseline + amount_paid − purchase = +100
        $vaultAfterCreate = $this->accountBalance($this->cashbox->id);
        $this->assertEqualsWithDelta($vaultBaseline + 100.0, $vaultAfterCreate, 0.01);

        // First DELETE: 200, vault returns to baseline
        $this->deleteJson("/api/v1/online/transactions/{$tx->id}")->assertOk();
        $vaultAfterFirst = $this->accountBalance($this->cashbox->id);
        $this->assertEqualsWithDelta($vaultBaseline, $vaultAfterFirst, 0.01);

        // Second DELETE: 200 idempotent, no GL change
        $response = $this->deleteJson("/api/v1/online/transactions/{$tx->id}");
        $response->assertOk();
        $vaultAfterSecond = $this->accountBalance($this->cashbox->id);
        $this->assertEqualsWithDelta($vaultAfterFirst, $vaultAfterSecond, 0.01);
    }

    // ============================================================
    // Customer Balances & Statement
    // ============================================================

    public function test_customer_balances_groups_by_customer(): void
    {
        $c1 = $this->makeCustomer('C1', '01000000600');
        $c2 = $this->makeCustomer('C2', '01000000601');

        $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'customer_id' => $c1->id,
            'purchase_price' => 0, 'selling_price' => 200, 'amount_paid' => 50,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);
        $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'customer_id' => $c1->id,
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);
        $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'customer_id' => $c2->id,
            'purchase_price' => 0, 'selling_price' => 300, 'amount_paid' => 300,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        $response = $this->getJson('/api/v1/online/customer-balances');
        $response->assertOk();
        $rows = collect($response->json('data'));
        $c1Row = $rows->firstWhere('client_id', $c1->id);
        $this->assertNotNull($c1Row);
        $this->assertEqualsWithDelta(300.0, $c1Row['total_sales'], 0.01);
        $this->assertEqualsWithDelta(150.0, $c1Row['total_paid'], 0.01);
        $this->assertEqualsWithDelta(150.0, $c1Row['total_debt'], 0.01);
        $this->assertSame(2, $c1Row['transaction_count']);
    }

    public function test_customer_balances_filters_debtors_only(): void
    {
        $c1 = $this->makeCustomer('Debtor', '01000000602');
        $c2 = $this->makeCustomer('Clean', '01000000603');
        $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'customer_id' => $c1->id,
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 0,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);
        $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'customer_id' => $c2->id,
            'purchase_price' => 0, 'selling_price' => 100, 'amount_paid' => 100,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        $response = $this->getJson('/api/v1/online/customer-balances?status=debtors');
        $response->assertOk();
        $rows = collect($response->json('data'));
        $ids = $rows->pluck('client_id')->all();
        $this->assertContains($c1->id, $ids);
        $this->assertNotContains($c2->id, $ids);
    }

    public function test_customer_statement_registered_customer(): void
    {
        $customer = $this->makeCustomer('Stmt', '01000000700');
        $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'customer_id' => $customer->id,
            'purchase_price' => 50, 'selling_price' => 200, 'amount_paid' => 60,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        $response = $this->getJson("/api/v1/online/customer-statement?client_id={$customer->id}");
        $response->assertOk();
        $data = $response->json('data');
        $this->assertArrayHasKey('transactions', $data);
        $this->assertArrayHasKey('pagination', $data);
        $this->assertArrayHasKey('running_balance', $data);
        $this->assertGreaterThan(0, count($data['transactions']));
    }

    public function test_customer_statement_walk_in_fallback(): void
    {
        // Walk-in: no customer_id, name + phone in free text.
        $tx = $this->service->create([
            'service_type_code' => $this->serviceType->code,
            'customer_name' => 'Walk In Stmt',
            'customer_phone' => '01000000701',
            'purchase_price' => 0, 'selling_price' => 150, 'amount_paid' => 50,
            'payment_method' => 'cash', 'account_id' => $this->cashbox->id,
        ]);

        // The walk-in tx gets auto-created Customer + AR mirror. Pass either
        // client_id (preferred) OR client_name to hit the controller's
        // fallback path.
        $response = $this->getJson('/api/v1/online/customer-statement?client_id='.$tx->customer_id);
        $response->assertOk();
        $data = $response->json('data');
        $this->assertGreaterThanOrEqual(1, count($data['transactions']));
    }
}
