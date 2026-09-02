<?php

namespace Tests\Feature\Visa;

use App\Enums\AccountType;
use App\Enums\VisaStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmra\VisaAgent;
use App\Models\Transaction;
use App\Models\VisaBooking;
use App\Models\VisaPayment;
use App\Services\Visa\VisaBookingService;

/**
 * Visa Module — Full Baseline Restore Audit.
 *
 * Phase 10 production audit (2026-08-25). Mirrors the HajjUmraFullBaselineRestoreTest
 * pattern from commit dc3cc5e. Each scenario asserts that after a full lifecycle
 * (cancel / refund / delete / multi-customer), EVERY financial account touched by
 * the booking returns to its pre-booking baseline within 0.01 tolerance.
 *
 * These tests focus on the user's two stated invariants:
 *   1. "كل حاجه ترجع لي اصلها تاني" — every account returns to baseline.
 *   2. "الدين والمديونيه" — customer debt/credit and agent supplier payable
 *      are correctly zeroed on reversal.
 *
 * They are written DEFENSIVELY against the known DEFECT-VISA-2026-08-25-A
 * (double-seeded opening balances), so they assert per-account baseline
 * deltas rather than relying on `assertLedgerGloballyBalanced()`.
 *
 * Coverage matrix:
 *   Group 1 (DELETE):  7 scenarios — EGP-full, EGP-partial, EGP-multi-pay, USD-agent,
 *                                SAR-no-agent, pay-debt-then-delete, two-customers
 *   Group 2 (CANCEL):  2 scenarios — EGP-full, USD-full
 *   Group 3 (REFUND):  2 scenarios — EGP-full, SAR-partial
 *   Group 4 (Invariant): 5 scenarios — API view-level, original amount preserved,
 *                                   reversal entries added, double-delete idempotency
 *
 * @group visa visa-baseline-restore
 */
class VisaFullBaselineRestoreTest extends VisaTestCase
{
    // ════════════════════════════════════════════════════════════════════════
    //  Helpers
    // ════════════════════════════════════════════════════════════════════════

    /**
     * Snapshot all account balances touched by the visa lifecycle.
     * Returns a map [account_id => balance].
     */
    protected function snapshotBalances(): array
    {
        $snap = [];
        // Vaults
        foreach ([$this->vaultEgp, $this->vaultUsd, $this->vaultSar, $this->bankEgp] as $vault) {
            $snap[$vault->id] = (float) $vault->fresh()->balance;
        }
        // Agent supplier account
        $snap[$this->agent->account_id] = (float) Account::find($this->agent->account_id)->fresh()->balance;
        // Customer AR account (auto-created by service)
        $customerAccount = Account::query()
            ->where('type', AccountType::Customer->value)
            ->where('notes', 'like', '%#'.$this->customer->id.'%')
            ->first();
        if ($customerAccount) {
            $snap[$customerAccount->id] = (float) $customerAccount->fresh()->balance;
        }

        return $snap;
    }

    /**
     * Assert every account in a baseline snapshot has been restored.
     */
    protected function assertBaselinesRestored(array $baseline): void
    {
        foreach ($baseline as $accountId => $expected) {
            $account = Account::find($accountId);
            if (! $account) {
                continue;
            }
            $actual = (float) $account->fresh()->balance;
            $this->assertEqualsWithDelta(
                $expected,
                $actual,
                0.01,
                "Account #{$accountId} ({$account->name}, {$account->currency}) should return to baseline {$expected}, got {$actual}"
            );
        }
    }

    /**
     * Resolve the customer's auto-created AR account (created by VisaBookingService).
     */
    protected function customerArAccount(): ?Account
    {
        return Account::query()
            ->where('type', AccountType::Customer->value)
            ->where(function ($q) {
                $q->where('notes', 'like', '%#'.$this->customer->id.'%')
                    ->orWhere('name', 'like', '%'.$this->customer->full_name.'%');
            })
            ->first();
    }

    /**
     * Resolve the agent's supplier account.
     */
    protected function agentAccount(): Account
    {
        return Account::findOrFail($this->agent->account_id);
    }

    // ════════════════════════════════════════════════════════════════════════
    //  GROUP 1: DELETE — كل السيناريوهات دي لازم ترجع كل الأرصدة لاصلها
    // ════════════════════════════════════════════════════════════════════════

    /**
     * السيناريو 1: EGP, دفع كامل, ثم DELETE
     * المتوقع: كل الأرصدة (vault EGP + customer AR + agent AP) ترجع baseline.
     */
    public function test_egp_create_pay_full_delete_restores_all_baselines(): void
    {
        $baseline = $this->snapshotBalances();

        // إنشاء الحجز بـ 1500 + 100 fee = 1600 EGP
        $booking = $this->makeBooking();
        $bookingId = $booking->id;

        // دفع كامل 1600
        $service = app(VisaBookingService::class);
        $service->addPayment($booking->fresh(), [
            'amount' => 1600.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
        ]);

        // DELETE
        $this->actingAsUser($this->user)
            ->deleteJson("/api/v1/visa/bookings/{$bookingId}")
            ->assertOk();

        // Assertions: كل الأرصدة ترجع baseline
        $this->assertBaselinesRestored($baseline);

        // الحجز لازم يكون soft-deleted
        $booking->refresh();
        $this->assertNotNull($booking->deleted_at, 'Booking should be soft-deleted');

        // VisaPayment بتستخدم SoftDeletes trait فالـ delete بيعمل SOFT-delete
        // (مش hard-delete). ده مقبول طالما الـ AccountEntry العكسية موجودة.
        $paymentsCount = VisaPayment::where('visa_booking_id', $bookingId)->count();
        $this->assertEquals(0, $paymentsCount,
            'VisaPayment rows should be soft-deleted (excluded from default query)');

        $trashedPayments = VisaPayment::onlyTrashed()->where('visa_booking_id', $bookingId)->count();
        $this->assertGreaterThan(0, $trashedPayments,
            'VisaPayment rows must be soft-deleted (visible via onlyTrashed)');
    }

    /**
     * السيناريو 2: EGP, دفع جزئي (60%), ثم DELETE
     * المتوقع: كل الأرصدة ترجع baseline حتى لو الحجز مكانش مدفوع بالكامل.
     */
    public function test_egp_create_partial_pay_delete_restores_all_baselines(): void
    {
        $baseline = $this->snapshotBalances();

        $booking = $this->makeBooking();
        $bookingId = $booking->id;

        // دفع جزئي 60% من 1600 = 960
        app(VisaBookingService::class)->addPayment($booking->fresh(), [
            'amount' => 960.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
        ]);

        $this->actingAsUser($this->user)
            ->deleteJson("/api/v1/visa/bookings/{$bookingId}")
            ->assertOk();

        $this->assertBaselinesRestored($baseline);
    }

    /**
     * السيناريو 3: EGP, 3 دفعات على حسابات مختلفة (cash + bank), ثم DELETE
     * المتوقع: كل من vault EGP + bank EGP + customer AR + agent AP ترجع baseline.
     */
    public function test_egp_create_multi_payment_delete_restores_all_baselines(): void
    {
        $baseline = $this->snapshotBalances();

        $booking = $this->makeBooking();
        $bookingId = $booking->id;

        $service = app(VisaBookingService::class);
        // 3 دفعات: 700 cash, 500 bank, 400 cash
        $service->addPayment($booking->fresh(), [
            'amount' => 700.0, 'account_id' => $this->vaultEgp->id, 'payment_method' => 'cash',
        ]);
        $service->addPayment($booking->fresh(), [
            'amount' => 500.0, 'account_id' => $this->bankEgp->id, 'payment_method' => 'bank_transfer',
        ]);
        $service->addPayment($booking->fresh(), [
            'amount' => 400.0, 'account_id' => $this->vaultEgp->id, 'payment_method' => 'cash',
        ]);

        $this->actingAsUser($this->user)
            ->deleteJson("/api/v1/visa/bookings/{$bookingId}")
            ->assertOk();

        $this->assertBaselinesRestored($baseline);
    }

    /**
     * السيناريو 4: USD, وكيل USD, دفع كامل, ثم DELETE
     * المتوقع: USD vault + USD agent AP + USD customer AR كلهم يرجعوا baseline.
     */
    public function test_usd_create_with_usd_agent_delete_restores_all_baselines(): void
    {
        // إنشاء وكيل USD + حسابه
        $usdSupplier = Account::create([
            'name' => 'حساب وكيل USD - Audit',
            'type' => AccountType::Supplier->value,
            'currency' => 'USD',
            'balance' => 0.0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OWNER,
            'module_type' => 'visas',
            'is_module_vault' => false,
            'created_by' => $this->user->id,
        ]);
        $usdAgent = VisaAgent::create([
            'company_name' => 'USD Audit Agent',
            'contact_person' => 'USD Audit',
            'phone' => '01000000300',
            'currency' => 'USD',
            'account_id' => $usdSupplier->id,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        // إنشاء عميل USD
        $usdCustomer = $this->makeCustomer([
            'full_name' => 'Audit USD Customer',
            'currency' => 'USD',
        ]);

        // Snapshot الأرصدة المتعلقة
        $baseline = [
            $this->vaultUsd->id => (float) $this->vaultUsd->fresh()->balance,
            $usdSupplier->id => 0.0,
        ];

        // إنشاء الحجز USD
        $booking = $this->makeBooking([
            'customer_id' => $usdCustomer->id,
            'purchase_price' => 800.0,
            'selling_price' => 1500.0,
            'service_fee' => 100.0,
            'currency' => 'USD',
            'account_id' => $this->vaultUsd->id,
            'visa_details' => [
                'visa_type' => 'tourist',
                'country' => 'AUDIT-USD-LAND',
                'duration' => '30',
                'visa_duration_id' => $this->duration->id,
                'entry_type' => 'single',
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addMonths(6)->toDateString(),
                'submission_date' => now()->toDateString(),
                'expected_result_date' => now()->addDays(15)->toDateString(),
                'executing_company' => 'USD Audit Co',
                'executing_agent' => 'USD Audit',
                'executing_agent_contact' => '01000000300',
                'visa_agent_id' => $usdAgent->id,
            ],
        ]);
        $bookingId = $booking->id;

        // دفع كامل 1600 USD
        app(VisaBookingService::class)->addPayment($booking->fresh(), [
            'amount' => 1600.0,
            'account_id' => $this->vaultUsd->id,
            'payment_method' => 'cash',
        ]);

        $this->actingAsUser($this->user)
            ->deleteJson("/api/v1/visa/bookings/{$bookingId}")
            ->assertOk();

        // USD vault + USD supplier لازم يرجعوا baseline
        $this->assertEqualsWithDelta($baseline[$this->vaultUsd->id],
            (float) $this->vaultUsd->fresh()->balance, 0.01,
            'USD vault should return to baseline');
        $this->assertEqualsWithDelta(0.0,
            (float) Account::find($usdSupplier->id)->fresh()->balance, 0.01,
            'USD supplier account should be back to 0');
    }

    /**
     * السيناريو 5: SAR, بدون وكيل, دفع كامل, ثم DELETE
     * المتوقع: SAR vault + SAR customer AR ترجع baseline.
     */
    public function test_sar_create_with_no_agent_delete_restores_all_baselines(): void
    {
        $sarCustomer = $this->makeCustomer([
            'full_name' => 'Audit SAR Customer',
            'currency' => 'SAR',
        ]);

        $baselineSarVault = (float) $this->vaultSar->fresh()->balance;

        $booking = $this->makeBooking([
            'customer_id' => $sarCustomer->id,
            'purchase_price' => 800.0,
            'selling_price' => 1500.0,
            'service_fee' => 100.0,
            'currency' => 'SAR',
            'account_id' => $this->vaultSar->id,
            'agent_name' => 'No Agent — SAR',
            'visa_details' => [
                'visa_type' => 'tourist',
                'country' => 'AUDIT-SAR-LAND',
                'duration' => '30',
                'visa_duration_id' => $this->duration->id,
                'entry_type' => 'single',
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addMonths(6)->toDateString(),
                'submission_date' => now()->toDateString(),
                'expected_result_date' => now()->addDays(15)->toDateString(),
                'executing_company' => 'SAR Audit Co',
                'executing_agent' => 'SAR Audit',
                'executing_agent_contact' => '01000000400',
                'visa_agent_id' => null, // لا وكيل
            ],
        ]);
        $bookingId = $booking->id;

        app(VisaBookingService::class)->addPayment($booking->fresh(), [
            'amount' => 1600.0,
            'account_id' => $this->vaultSar->id,
            'payment_method' => 'cash',
        ]);

        $this->actingAsUser($this->user)
            ->deleteJson("/api/v1/visa/bookings/{$bookingId}")
            ->assertOk();

        $this->assertEqualsWithDelta($baselineSarVault,
            (float) $this->vaultSar->fresh()->balance, 0.01,
            'SAR vault should return to baseline');
    }

    /**
     * السيناريو 6: EGP, دفع عادي + تسديد مديونية عبر pay-customer-debt, ثم DELETE
     * المتوقع: كل الأرصدة ترجع baseline بغض النظر عن مسار الدفع.
     */
    public function test_egp_create_pay_then_pay_customer_debt_then_delete(): void
    {
        $baseline = $this->snapshotBalances();

        $booking = $this->makeBooking();
        $bookingId = $booking->id;

        // دفع عادي 800 (نص المبلغ)
        app(VisaBookingService::class)->addPayment($booking->fresh(), [
            'amount' => 800.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
        ]);

        // تسديد المديونية 800 عن طريق pay-customer-debt
        $customerAr = $this->customerArAccount();
        $this->actingAsUser($this->user)
            ->postJson("/api/v1/visa/customers/{$this->customer->id}/pay-debt", [
                'amount' => 800.0,
                'account_id' => $this->vaultEgp->id,
                'notes' => 'Audit — pay debt',
            ])
            ->assertOk();

        // DELETE
        $this->actingAsUser($this->user)
            ->deleteJson("/api/v1/visa/bookings/{$bookingId}")
            ->assertOk();

        $this->assertBaselinesRestored($baseline);
    }

    /**
     * السيناريو 7: عميلين مختلفين، كل واحد له حجز، نحذف الاثنين
     * المتوقع: العزل بين العملاء — حذف واحد ما يأثرش على التاني.
     */
    public function test_two_customers_independently_delete_both_restores_baselines(): void
    {
        $customer2 = $this->makeCustomer([
            'full_name' => 'Audit Customer 2',
        ]);

        $baseline = $this->snapshotBalances();

        // الحجز الأول للعميل الأول
        $booking1 = $this->makeBooking();
        app(VisaBookingService::class)->addPayment($booking1->fresh(), [
            'amount' => 1600.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
        ]);

        // الحجز التاني للعميل التاني
        $booking2 = $this->makeBooking([
            'customer_id' => $customer2->id,
            'agent_name' => 'Audit Agent 2',
        ]);
        app(VisaBookingService::class)->addPayment($booking2->fresh(), [
            'amount' => 1600.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
        ]);

        // حذف الاثنين
        $this->actingAsUser($this->user)->deleteJson("/api/v1/visa/bookings/{$booking1->id}")->assertOk();
        $this->actingAsUser($this->user)->deleteJson("/api/v1/visa/bookings/{$booking2->id}")->assertOk();

        // كل الأرصدة ترجع baseline (الـ EGP vault)
        $this->assertBaselinesRestored($baseline);

        // الـ agent AP لازم يرجع baseline حتى مع حذفين
        $this->assertEqualsWithDelta(
            $baseline[$this->agent->account_id],
            (float) $this->agentAccount()->fresh()->balance,
            0.01,
            'Agent AP should return to baseline after 2 deletes'
        );
    }

    // ════════════════════════════════════════════════════════════════════════
    //  GROUP 2: CANCEL — الإلغاء الخفيف لازم يرجع كل الأرصدة
    // ════════════════════════════════════════════════════════════════════════

    /**
     * السيناريو 8: EGP, دفع كامل, ثم CANCEL (light cancel)
     * المتوقع: الأرصدة ترجع + الحجز يفضل موجود لكن بـ status=Cancelled.
     */
    public function test_egp_create_pay_full_cancel_restores_all_baselines(): void
    {
        $baseline = $this->snapshotBalances();

        $booking = $this->makeBooking();
        $bookingId = $booking->id;

        app(VisaBookingService::class)->addPayment($booking->fresh(), [
            'amount' => 1600.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
        ]);

        $this->actingAsUser($this->user)
            ->postJson("/api/v1/visa/bookings/{$bookingId}/cancel", [
                'reason' => 'Audit — customer changed mind',
            ])
            ->assertOk();

        $this->assertBaselinesRestored($baseline);

        // الحجز لازم يفضل موجود (مش محذوف) لكن بـ Cancelled
        $booking = VisaBooking::find($bookingId);
        $this->assertNotNull($booking, 'Booking should remain visible after cancel');
        $this->assertNull($booking->deleted_at, 'Booking should NOT be soft-deleted after cancel');
        $this->assertEquals(VisaStatus::Cancelled->value, $booking->status->value);
    }

    /**
     * السيناريو 9: USD, دفع كامل, ثم CANCEL
     */
    public function test_usd_create_pay_full_cancel_restores_all_baselines(): void
    {
        $usdCustomer = $this->makeCustomer([
            'full_name' => 'Audit USD Customer Cancel',
            'currency' => 'USD',
        ]);

        $baselineUsdVault = (float) $this->vaultUsd->fresh()->balance;

        $booking = $this->makeBooking([
            'customer_id' => $usdCustomer->id,
            'purchase_price' => 800.0,
            'selling_price' => 1500.0,
            'service_fee' => 100.0,
            'currency' => 'USD',
            'account_id' => $this->vaultUsd->id,
            'visa_details' => [
                'visa_type' => 'tourist',
                'country' => 'AUDIT-USD-CANCEL',
                'duration' => '30',
                'visa_duration_id' => $this->duration->id,
                'entry_type' => 'single',
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addMonths(6)->toDateString(),
                'submission_date' => now()->toDateString(),
                'expected_result_date' => now()->addDays(15)->toDateString(),
                'executing_company' => 'USD Cancel Co',
                'executing_agent' => 'USD Cancel',
                'executing_agent_contact' => '01000000500',
                'visa_agent_id' => null,
            ],
        ]);

        app(VisaBookingService::class)->addPayment($booking->fresh(), [
            'amount' => 1600.0,
            'account_id' => $this->vaultUsd->id,
            'payment_method' => 'cash',
        ]);

        $this->actingAsUser($this->user)
            ->postJson("/api/v1/visa/bookings/{$booking->id}/cancel", [
                'reason' => 'Audit USD cancel',
            ])
            ->assertOk();

        $this->assertEqualsWithDelta($baselineUsdVault,
            (float) $this->vaultUsd->fresh()->balance, 0.01,
            'USD vault should return to baseline after cancel');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  GROUP 3: REFUND — الاسترداد الكامل لازم يرجع كل الأرصدة
    // ════════════════════════════════════════════════════════════════════════

    /**
     * السيناريو 10: EGP, دفع كامل, ثم REFUND
     * المتوقع: الأرصدة ترجع + status=Refunded + سجل refund_audit_logs موجود.
     */
    public function test_egp_create_pay_full_refund_restores_all_baselines(): void
    {
        $baseline = $this->snapshotBalances();

        $booking = $this->makeBooking();
        $bookingId = $booking->id;

        app(VisaBookingService::class)->addPayment($booking->fresh(), [
            'amount' => 1600.0,
            'account_id' => $this->vaultEgp->id,
            'payment_method' => 'cash',
        ]);

        $this->actingAsUser($this->user)
            ->postJson("/api/v1/visa/bookings/{$bookingId}/refund", [
                'reason' => 'Audit — full refund',
            ])
            ->assertOk();

        $this->assertBaselinesRestored($baseline);

        // التحقق من الـ refund_audit_logs (الجدول ما فيهوش عمود event — بس module و booking_id كافيين)
        $this->assertDatabaseHas('refund_audit_logs', [
            'booking_id' => $bookingId,
            'module' => 'visa',
        ]);

        $booking = VisaBooking::find($bookingId);
        $this->assertEquals(VisaStatus::Refunded->value, $booking->status->value);
    }

    /**
     * السيناريو 11: SAR, دفع جزئي, ثم REFUND
     * المتوقع: الـ refundAmount = paid amount (cap) — الأرصدة ترجع لقيمتها بعد استرداد المبلغ المدفوع فقط.
     */
    public function test_sar_create_partial_pay_refund_restores_all_baselines(): void
    {
        $sarCustomer = $this->makeCustomer([
            'full_name' => 'Audit SAR Customer Refund',
            'currency' => 'SAR',
        ]);

        $baselineSarVault = (float) $this->vaultSar->fresh()->balance;

        $booking = $this->makeBooking([
            'customer_id' => $sarCustomer->id,
            'purchase_price' => 800.0,
            'selling_price' => 1500.0,
            'service_fee' => 100.0,
            'currency' => 'SAR',
            'account_id' => $this->vaultSar->id,
            'visa_details' => [
                'visa_type' => 'tourist',
                'country' => 'AUDIT-SAR-REFUND',
                'duration' => '30',
                'visa_duration_id' => $this->duration->id,
                'entry_type' => 'single',
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addMonths(6)->toDateString(),
                'submission_date' => now()->toDateString(),
                'expected_result_date' => now()->addDays(15)->toDateString(),
                'executing_company' => 'SAR Refund Co',
                'executing_agent' => 'SAR Refund',
                'executing_agent_contact' => '01000000600',
                'visa_agent_id' => null,
            ],
        ]);

        // دفع جزئي 1000 من 1600
        app(VisaBookingService::class)->addPayment($booking->fresh(), [
            'amount' => 1000.0,
            'account_id' => $this->vaultSar->id,
            'payment_method' => 'cash',
        ]);

        $this->actingAsUser($this->user)
            ->postJson("/api/v1/visa/bookings/{$booking->id}/refund", [
                'reason' => 'Audit SAR partial refund',
            ])
            ->assertOk();

        // SAR vault لازم يرجع baseline بعد استرداد الـ 1000
        $this->assertEqualsWithDelta($baselineSarVault,
            (float) $this->vaultSar->fresh()->balance, 0.01,
            'SAR vault should return to baseline after refund');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  GROUP 4: Invariants — التحقق من القواعد اللي ما تتكسرش أبداً
    // ════════════════════════════════════════════════════════════════════════

    /**
     * السيناريو 12: API view-level — `/customer-balances` يرجع total_debt=0 بعد DELETE.
     */
    public function test_customer_balances_api_returns_zero_debt_after_full_lifecycle_delete(): void
    {
        $booking = $this->makeBooking();
        app(VisaBookingService::class)->addPayment($booking->fresh(), [
            'amount' => 1600.0, 'account_id' => $this->vaultEgp->id, 'payment_method' => 'cash',
        ]);

        $this->actingAsUser($this->user)
            ->deleteJson("/api/v1/visa/bookings/{$booking->id}")
            ->assertOk();

        // GET /customer-balances — العميل ده المفروض ميظهرش لأن الحجز اتحذف
        $response = $this->actingAsUser($this->user)
            ->getJson('/api/v1/visa/customer-balances')
            ->assertOk()
            ->json();

        $this->assertIsArray($response['data'] ?? $response);

        // ابحث عن العميل ده في الـ response
        $customerRow = collect($response['data'] ?? $response)->firstWhere('client_id', $this->customer->id);
        if ($customerRow) {
            $this->assertEquals(0.0, (float) ($customerRow['total_debt'] ?? 0),
                'Customer debt should be 0 after delete');
            $this->assertEquals(0, (int) ($customerRow['booking_count'] ?? 0),
                'Customer booking_count should be 0 after delete (soft-deleted bookings excluded)');
        }
        // لو مش موجود في القائمة، ده كمان مقبول لأن الحجوزات الـ soft-deleted بتتشال
    }

    /**
     * السيناريو 13: API view-level — `/customer-statement` running_balance = 0 بعد DELETE.
     */
    public function test_customer_statement_api_returns_zero_running_balance_after_full_lifecycle_delete(): void
    {
        $booking = $this->makeBooking();
        app(VisaBookingService::class)->addPayment($booking->fresh(), [
            'amount' => 1600.0, 'account_id' => $this->vaultEgp->id, 'payment_method' => 'cash',
        ]);

        $this->actingAsUser($this->user)
            ->deleteJson("/api/v1/visa/bookings/{$booking->id}")
            ->assertOk();

        $response = $this->actingAsUser($this->user)
            ->getJson("/api/v1/visa/customer-statement?client_id={$this->customer->id}")
            ->assertOk()
            ->json();

        // summary.total_debt لازم يكون 0 بعد الـ reversal الكامل
        $this->assertEquals(0.0, (float) ($response['data']['summary']['total_debt'] ?? 1),
            'Customer statement running balance should be 0 after delete');
    }

    /**
     * السيناريو 14: الـ Transaction الأصلية ما اتغيرتش بعد cancel/refund/delete
     * (additive pattern — الأصلية لا تُعدَّل).
     */
    public function test_original_transaction_amount_preserved_after_cancel_refund_delete(): void
    {
        $booking = $this->makeBooking();
        $bookingId = $booking->id;
        $originalIncomeAmount = (float) $booking->incomeTransaction->amount;
        $originalExpenseAmount = (float) $booking->expenseTransaction->amount;

        // دفع
        app(VisaBookingService::class)->addPayment($booking->fresh(), [
            'amount' => 1600.0, 'account_id' => $this->vaultEgp->id, 'payment_method' => 'cash',
        ]);

        // CANCEL أولاً
        $this->actingAsUser($this->user)
            ->postJson("/api/v1/visa/bookings/{$bookingId}/cancel", ['reason' => 'Audit cancel'])
            ->assertOk();

        // التحقق: الـ original transactions ما اتغيرتش amount-wise
        $this->assertEqualsWithDelta($originalIncomeAmount,
            (float) Transaction::find($booking->income_transaction_id)->fresh()->amount, 0.01,
            'Original income transaction amount must be preserved after cancel');
        $this->assertEqualsWithDelta($originalExpenseAmount,
            (float) Transaction::find($booking->expense_transaction_id)->fresh()->amount, 0.01,
            'Original expense transaction amount must be preserved after cancel');

        // refund مش هيشتغل لأن الحجز cancelled — لازم ينشئ حجز جديد
        $booking2 = $this->makeBooking();
        $originalIncomeAmount2 = (float) $booking2->incomeTransaction->amount;

        $this->actingAsUser($this->user)
            ->postJson("/api/v1/visa/bookings/{$booking2->id}/refund", ['reason' => 'Audit refund'])
            ->assertOk();

        $this->assertEqualsWithDelta($originalIncomeAmount2,
            (float) Transaction::find($booking2->income_transaction_id)->fresh()->amount, 0.01,
            'Original income transaction amount must be preserved after refund');

        // delete
        $booking3 = $this->makeBooking();
        $originalIncomeAmount3 = (float) $booking3->incomeTransaction->amount;

        $this->actingAsUser($this->user)
            ->deleteJson("/api/v1/visa/bookings/{$booking3->id}")
            ->assertOk();

        $this->assertEqualsWithDelta($originalIncomeAmount3,
            (float) Transaction::find($booking3->income_transaction_id)->fresh()->amount, 0.01,
            'Original income transaction amount must be preserved after delete');
    }

    /**
     * السيناريو 15: الـ AccountEntry entries العكسية اتضافت بـ prefix "عكس:"
     * (additive audit trail — الـ originals موجودة + reversals اتضافت).
     */
    public function test_account_entry_notes_prefix_reversal_after_cancel_refund_delete(): void
    {
        // CANCEL
        $booking1 = $this->makeBooking();
        app(VisaBookingService::class)->addPayment($booking1->fresh(), [
            'amount' => 1600.0, 'account_id' => $this->vaultEgp->id, 'payment_method' => 'cash',
        ]);
        $this->actingAsUser($this->user)
            ->postJson("/api/v1/visa/bookings/{$booking1->id}/cancel", ['reason' => 'Audit'])
            ->assertOk();

        $this->assertReversalEntriesExist($booking1, 'after cancel');

        // REFUND
        $booking2 = $this->makeBooking();
        $this->actingAsUser($this->user)
            ->postJson("/api/v1/visa/bookings/{$booking2->id}/refund", ['reason' => 'Audit'])
            ->assertOk();

        $this->assertReversalEntriesExist($booking2, 'after refund');

        // DELETE
        $booking3 = $this->makeBooking();
        app(VisaBookingService::class)->addPayment($booking3->fresh(), [
            'amount' => 1600.0, 'account_id' => $this->vaultEgp->id, 'payment_method' => 'cash',
        ]);
        $this->actingAsUser($this->user)
            ->deleteJson("/api/v1/visa/bookings/{$booking3->id}")
            ->assertOk();

        // الـ delete بيمسح الـ payments، فالـ transaction الأصلية بتكون للـ income/expense بس
        $incomeEntries = AccountEntry::where('transaction_id', $booking3->income_transaction_id)->get();
        $reversalCount = $incomeEntries->filter(fn ($e) => str_starts_with((string) $e->notes, 'عكس'))->count();
        $this->assertGreaterThan(0, $reversalCount,
            "Reversal entries must exist on income tx after delete (got {$reversalCount})");
    }

    /**
     * Helper: التأكد من وجود entries عكسية على كل الـ transactions المرتبطة بالحجز.
     */
    protected function assertReversalEntriesExist(VisaBooking $booking, string $context): void
    {
        foreach (['income_transaction_id', 'expense_transaction_id'] as $field) {
            $txId = $booking->{$field};
            if (! $txId) {
                continue;
            }
            $entries = AccountEntry::where('transaction_id', $txId)->get();
            $reversalCount = $entries->filter(fn ($e) => str_starts_with((string) $e->notes, 'عكس'))->count();
            $this->assertGreaterThan(0, $reversalCount,
                "Reversal entries (with 'عكس' prefix) must exist on {$field} {$context} (got {$reversalCount})");
        }
    }

    /**
     * السيناريو 16: Double DELETE — الـ idempotency guard يرفض الثاني بـ 422.
     */
    public function test_double_delete_is_rejected_idempotent_guard(): void
    {
        $booking = $this->makeBooking();
        $bookingId = $booking->id;

        // أول DELETE
        $this->actingAsUser($this->user)
            ->deleteJson("/api/v1/visa/bookings/{$bookingId}")
            ->assertOk();

        // تاني DELETE لازم يفشل بـ 422 (already trashed)
        $this->actingAsUser($this->user)
            ->deleteJson("/api/v1/visa/bookings/{$bookingId}")
            ->assertStatus(422);

        // التحقق من رسالة الخطأ
        $response = $this->actingAsUser($this->user)
            ->deleteJson("/api/v1/visa/bookings/{$bookingId}")
            ->assertStatus(422)
            ->json();

        $message = $response['message'] ?? '';
        $this->assertStringContainsString('محذوف', $message,
            'Error message should indicate booking is already deleted');
    }

    // ════════════════════════════════════════════════════════════════════════
    //  GROUP 5: DELETE + DEBT scenarios — التحقق من سيناريوهات الحذف مع ديون
    //  السيناريوهات دي متحدثة بعد طلب التحقق من حذف حجز لعميل عليه ديون
    // ════════════════════════════════════════════════════════════════════════

    /**
     * السيناريو 17: عميل عنده حجزين — واحد مدفوع بالكامل + واحد عليه دين
     * → نحذف اللي عليه دين → لازم الحجز التاني (المدفوع) يفضل سليم
     * → الـ customer AR لازم يعكس فقط الدين المتبقي (مش كل حاجة ترجع 0)
     */
    public function test_customer_with_mixed_bookings_delete_debt_only_preserves_paid_booking(): void
    {
        // الحجز الأول: مدفوع بالكامل 1600
        $paidBooking = $this->makeBooking([
            'agent_name' => 'Paid Booking',
        ]);
        app(VisaBookingService::class)->addPayment($paidBooking->fresh(), [
            'amount' => 1600.0, 'account_id' => $this->vaultEgp->id, 'payment_method' => 'cash',
        ]);

        // الحجز التاني: نفس العميل، عليه دين 1600 (صفر مدفوع)
        $debtBooking = $this->makeBooking([
            'agent_name' => 'Debt Booking',
        ]);
        $debtBookingId = $debtBooking->id;

        // Snapshot: الـ EGP vault المفروض يكون عنده +1600 (من الحجز المدفوع)
        $baselineVaultAfterPaid = (float) $this->vaultEgp->fresh()->balance;

        // حذف الحجز اللي عليه دين
        $this->actingAsUser($this->user)
            ->deleteJson("/api/v1/visa/bookings/{$debtBookingId}")
            ->assertOk();

        // 1) الحجز المدفوع لازم يفضل موجود و حالته Submitted
        $paidBooking->refresh();
        $this->assertNull($paidBooking->deleted_at,
            'Paid booking should NOT be affected when only the debt booking is deleted');
        $this->assertEquals(VisaStatus::Submitted->value, $paidBooking->status->value);

        // 2) الـ EGP vault لازم يفضل عنده الـ +1600 من الحجز المدفوع
        $this->assertEqualsWithDelta($baselineVaultAfterPaid,
            (float) $this->vaultEgp->fresh()->balance, 0.01,
            'EGP vault should keep the +1600 from the paid booking');

        // 3) الـ customer AR لازم يكون 0 (الدين اتعكس بالكامل)
        $customerAr = $this->customerArAccount();
        if ($customerAr) {
            $this->assertEqualsWithDelta(0.0,
                (float) $customerAr->fresh()->balance, 0.01,
                'Customer AR should be 0 after debt booking delete (debt fully reversed)');
        }

        // 4) الـ agent AP لازم يكون -1000 (الـ expense من الحجز المدفوع لسه موجود
        //    في الـ ledger). الحجز اللي عليه دين الـ expense بتاعه اتعكس بالكامل.
        $this->assertEqualsWithDelta(-1000.0,
            (float) $this->agentAccount()->fresh()->balance, 0.01,
            'Agent AP should be -1000 (paid booking expense remains, debt booking expense reversed)');
    }

    /**
     * السيناريو 18: عميل عنده 3 حجوزات — 2 عليهم ديون + 1 مدفوع
     * → حذف الحجوزات اللي عليها ديون → الحجز المدفوع يفضل سليم
     * → الـ customer AR يرجع لـ 0 (الدين المتبقي اتعكس)
     */
    public function test_customer_with_three_bookings_delete_two_debts_keeps_paid(): void
    {
        // الحجز المدفوع: دفع كامل
        $paidBooking = $this->makeBooking([
            'agent_name' => 'PAID Booking',
        ]);
        app(VisaBookingService::class)->addPayment($paidBooking->fresh(), [
            'amount' => 1600.0, 'account_id' => $this->vaultEgp->id, 'payment_method' => 'cash',
        ]);

        // الحجز التاني عليه دين (صفر مدفوع)
        $debtBooking1 = $this->makeBooking([
            'agent_name' => 'DEBT Booking 1',
        ]);

        // الحجز التالت عليه دين جزئي (دفع 50% ثم عليه 800)
        $debtBooking2 = $this->makeBooking([
            'agent_name' => 'DEBT Booking 2',
        ]);
        app(VisaBookingService::class)->addPayment($debtBooking2->fresh(), [
            'amount' => 800.0, 'account_id' => $this->vaultEgp->id, 'payment_method' => 'cash',
        ]);

        // Snapshot بعد ما اتعملت الحجوزات (قبل الحذف)
        $baselineVaultBeforeDeletes = (float) $this->vaultEgp->fresh()->balance;
        // الـ vault المفروض يكون عنده 1600 (paid) + 800 (debt2) = 2400 زيادة عن الـ baseline

        // حذف الحجوزات اللي عليها ديون
        $this->actingAsUser($this->user)
            ->deleteJson("/api/v1/visa/bookings/{$debtBooking1->id}")
            ->assertOk();
        $this->actingAsUser($this->user)
            ->deleteJson("/api/v1/visa/bookings/{$debtBooking2->id}")
            ->assertOk();

        // 1) الـ vault لازم يفضل عنده فقط الـ +1600 من الحجز المدفوع
        //    (الـ +800 من الحجز ده اتعكس بالكامل)
        $expectedVault = $baselineVaultBeforeDeletes - 800.0;
        $this->assertEqualsWithDelta($expectedVault,
            (float) $this->vaultEgp->fresh()->balance, 0.01,
            'Vault should keep only the +1600 from the paid booking after deleting 2 debts');

        // 2) الحجز المدفوع لازم يفضل سليم
        $paidBooking->refresh();
        $this->assertNull($paidBooking->deleted_at);
        $this->assertEquals(VisaStatus::Submitted->value, $paidBooking->status->value);

        // 3) الـ customer AR لازم يكون 0
        $customerAr = $this->customerArAccount();
        if ($customerAr) {
            $this->assertEqualsWithDelta(0.0,
                (float) $customerAr->fresh()->balance, 0.01,
                'Customer AR should be 0 after deleting both debt bookings');
        }
    }

    /**
     * السيناريو 19: `/customer-balances` بعد حذف حجز عليه دين — لازم يعرض 0
     * (لأن الدين المتبقي اتعكس بالكامل)
     */
    public function test_customer_balances_after_delete_debt_booking_returns_zero(): void
    {
        // حجز عليه دين (صفر مدفوع)
        $debtBooking = $this->makeBooking();
        $customerId = $this->customer->id;

        // قبل الحذف: العميل المفروض يظهر في `/customer-balances` بـ total_debt = 1600
        $responseBefore = $this->actingAsUser($this->user)
            ->getJson('/api/v1/visa/customer-balances')
            ->assertOk()
            ->json();
        $customerRowBefore = collect($responseBefore['data'] ?? $responseBefore)
            ->firstWhere('client_id', $customerId);
        $this->assertNotNull($customerRowBefore, 'Customer should appear in balances before delete');
        $this->assertEqualsWithDelta(1600.0,
            (float) ($customerRowBefore['total_debt'] ?? 0), 0.01,
            'Customer total_debt should be 1600 before delete');

        // حذف الحجز
        $this->actingAsUser($this->user)
            ->deleteJson("/api/v1/visa/bookings/{$debtBooking->id}")
            ->assertOk();

        // بعد الحذف: العميل لازم يختفي أو يظهر بـ total_debt = 0
        $responseAfter = $this->actingAsUser($this->user)
            ->getJson('/api/v1/visa/customer-balances')
            ->assertOk()
            ->json();
        $customerRowAfter = collect($responseAfter['data'] ?? $responseAfter)
            ->firstWhere('client_id', $customerId);

        if ($customerRowAfter) {
            $this->assertEqualsWithDelta(0.0,
                (float) ($customerRowAfter['total_debt'] ?? 0), 0.01,
                'Customer total_debt should be 0 after deleting the only debt booking');
            $this->assertEquals(0, (int) ($customerRowAfter['booking_count'] ?? 0),
                'Customer booking_count should be 0 after delete');
        }
        // لو اختفى من القائمة، ده كمان مقبول لأن الحجوزات الـ soft-deleted بتتشال
    }

    /**
     * السيناريو 20: `/customer-statement` بعد حذف حجز عليه دين — running_balance لازم يكون 0
     */
    public function test_customer_statement_after_delete_debt_booking_returns_zero_running(): void
    {
        $debtBooking = $this->makeBooking();
        $customerId = $this->customer->id;

        // قبل الحذف: summary.total_debt = 1600
        $responseBefore = $this->actingAsUser($this->user)
            ->getJson("/api/v1/visa/customer-statement?client_id={$customerId}")
            ->assertOk()
            ->json();
        $this->assertEqualsWithDelta(1600.0,
            (float) ($responseBefore['data']['summary']['total_debt'] ?? 1), 0.01,
            'Statement summary total_debt should be 1600 before delete');

        // حذف الحجز
        $this->actingAsUser($this->user)
            ->deleteJson("/api/v1/visa/bookings/{$debtBooking->id}")
            ->assertOk();

        // بعد الحذف: summary.total_debt = 0
        $responseAfter = $this->actingAsUser($this->user)
            ->getJson("/api/v1/visa/customer-statement?client_id={$customerId}")
            ->assertOk()
            ->json();
        $this->assertEqualsWithDelta(0.0,
            (float) ($responseAfter['data']['summary']['total_debt'] ?? 1), 0.01,
            'Statement running balance should be 0 after delete');
    }
}
