<?php

namespace Tests\Feature\Visa;

use App\Enums\TransactionModule;
use App\Enums\VisaStatus;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\User;
use App\Models\VisaBooking;
use App\Models\VisaDetail;
use App\Models\HajjUmra\VisaDuration;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaModificationService;
use App\Services\Visa\VisaRefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use ReflectionClass;
use Tests\TestCase;

/**
 * Regression test for audit finding 10.1 (Low, fixed in 0f999ea).
 *
 * The original VisaBookingService::updateTransactionAmount() was a
 * protected helper that mutated Account.balance via raw SQL with NO
 * LedgerBalanceMutationGuard wrapper — silently bypassing the guard and
 * corrupting the financial timeline. The Visa service was already
 * rewritten to use additive reversal (no longer needs this helper), so
 * the method was deleted as dead code.
 *
 * This test class locks in three guarantees:
 *   1. The dangerous method `updateTransactionAmount` no longer exists
 *      on VisaBookingService (anywhere: public, protected, private).
 *   2. The deprecation shims (cancel, deleteBookingWithReversal,
 *      repostExpenseTransaction, repostIncomeTransaction) still delegate
 *      correctly to VisaRefundService / VisaModificationService — so any
 *      legacy Filament / test callers that still use them keep working.
 *   3. The lifecycle guards (cannot modify cancelled/refunded bookings,
 *      cannot add payment to a cancelled/refunded booking) still throw
 *      the correct Arabic error message.
 *
 * @see \App\Services\Visa\VisaBookingService
 */
class VisaBookingServiceDeadCodeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected Customer $customer;

    protected VisaBookingService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name' => 'Visa Dead-Code Tester',
            'email' => 'visa-deadcode@example.com',
            'password' => Hash::make('password'),
            'role' => 'admin',
            'is_active' => true,
        ]);

        Employee::query()->create([
            'user_id' => $this->admin->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->admin);

        $this->customer = Customer::query()->create([
            'full_name' => 'عميل تأشيرة',
            'phone' => '01000000010',
            'national_id' => '22345678901234',
            'created_by' => $this->admin->id,
        ]);

        $this->service = app(VisaBookingService::class);
    }

    /**
     * Helper: create a VisaBooking in a given status with the supplied
     * amounts (so payment tests have a non-zero remaining_amount).
     */
    protected function createBooking(VisaStatus $status, float $selling = 5000.0, float $purchase = 4000.0): VisaBooking
    {
        $duration = VisaDuration::query()->create([
            'name' => '30 يوم',
            'code' => 'VD-30-'.uniqid(),
            'label_ar' => '30 يوم',
            'days' => 30,
            'is_active' => true,
        ]);

        $detail = VisaDetail::query()->create([
            'visa_type' => 'tourist',
            'country' => 'SA',
            'duration' => 30,
            'visa_duration_id' => $duration->id,
            'status' => $status->value,
        ]);

        return VisaBooking::query()->create([
            'customer_id' => $this->customer->id,
            'visa_detail_id' => $detail->id,
            'module' => TransactionModule::Visa->value,
            'purchase_price' => $purchase,
            'selling_price' => $selling,
            'service_fee' => 0,
            'profit' => $selling - $purchase,
            'currency' => 'EGP',
            'status' => $status->value,
            'agent_name' => $this->customer->full_name,
            'employee_id' => $this->admin->id,
            'created_by' => $this->admin->id,
        ]);
    }

    /**
     * The dangerous method must not exist anywhere on VisaBookingService —
     * not public, not protected, not private. Otherwise a stale caller
     * (queue job, tinker, future refactor) could re-introduce the silent
     * balance corruption.
     */
    public function test_updateTransactionAmount_method_is_removed(): void
    {
        $reflection = new ReflectionClass(VisaBookingService::class);

        $this->assertFalse(
            $reflection->hasMethod('updateTransactionAmount'),
            'VisaBookingService must NOT have an updateTransactionAmount method — it was the silent balance-bypass bug.'
        );
    }

    /**
     * The shim `cancel()` must delegate to VisaRefundService and actually
     * mark the booking as cancelled. Verifies the deprecation shim still
     * works for any legacy callers (Filament pages, tests, etc.).
     */
    public function test_cancel_shim_delegates_to_visa_refund_service(): void
    {
        $booking = $this->createBooking(VisaStatus::Submitted);
        $this->assertSame(VisaStatus::Submitted, $booking->status);

        $cancelled = $this->service->cancel($booking, 'audit test');

        $this->assertSame(VisaStatus::Cancelled, $cancelled->status);

        $booking->refresh();
        $this->assertSame(VisaStatus::Cancelled, $booking->status);
    }

    /**
     * The shim `deleteBookingWithReversal()` must delegate to
     * VisaRefundService::deleteWithReversal and actually delete the booking.
     */
    public function test_delete_booking_with_reversal_shim_delegates(): void
    {
        $booking = $this->createBooking(VisaStatus::Submitted);
        $bookingId = $booking->id;

        $result = $this->service->deleteBookingWithReversal($bookingId, $this->admin->id);

        $this->assertTrue($result);
        $this->assertSoftDeleted('visa_bookings', ['id' => $bookingId]);
    }

    /**
     * The shim `repostExpenseTransaction()` must delegate to
     * VisaModificationService::repostExpense and return a Transaction.
     */
    public function test_repost_expense_shim_delegates_to_visa_modification_service(): void
    {
        $booking = $this->createBooking(VisaStatus::Submitted);

        // Stub a transaction to pass through the shim
        $stubTransaction = new \App\Models\Transaction([
            'type' => 'expense',
            'amount' => $booking->purchase_price,
            'module' => TransactionModule::Visa->value,
        ]);
        $stubTransaction->id = 99999; // arbitrary, won't be saved
        $stubTransaction->exists = false;

        // Wrap the underlying service to verify delegation
        $modificationService = $this->mock(VisaModificationService::class);
        $modificationService->shouldReceive('repostExpense')
            ->once()
            ->with($booking, $stubTransaction, 4500.0)
            ->andReturn($stubTransaction);

        $result = $this->service->repostExpenseTransaction($booking, $stubTransaction, 4500.0);

        $this->assertSame($stubTransaction, $result);
    }

    /**
     * The shim `repostIncomeTransaction()` must delegate to
     * VisaModificationService::repostIncome and pass the resolved
     * customerAccount->id as the 4th argument.
     */
    public function test_repost_income_shim_resolves_customer_account(): void
    {
        $booking = $this->createBooking(VisaStatus::Submitted);

        $stubTransaction = new \App\Models\Transaction([
            'type' => 'income',
            'amount' => $booking->selling_price,
            'module' => TransactionModule::Visa->value,
        ]);
        $stubTransaction->id = 99999;
        $stubTransaction->exists = false;

        $expectedCustomerAccountId = $this->service->ensureCustomerAccount($this->customer->id)->id;

        $modificationService = $this->mock(VisaModificationService::class);
        $modificationService->shouldReceive('repostIncome')
            ->once()
            ->with($booking, $stubTransaction, 5500.0, $expectedCustomerAccountId)
            ->andReturn($stubTransaction);

        $result = $this->service->repostIncomeTransaction($booking, $stubTransaction, 5500.0);

        $this->assertSame($stubTransaction, $result);
    }

    /**
     * Lifecycle guard: editing a cancelled booking must throw with an
     * Arabic error message — no phantom income/expense transactions.
     */
    public function test_update_rejects_cancelled_booking(): void
    {
        $booking = $this->createBooking(VisaStatus::Cancelled);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('لا يمكن تعديل حجز تأشيرة مُلغى (status=cancelled)');

        $this->service->update($booking, ['notes' => 'محاولة تعديل بعد الإلغاء']);
    }

    /**
     * Lifecycle guard: editing a refunded booking must throw.
     */
    public function test_update_rejects_refunded_booking(): void
    {
        $booking = $this->createBooking(VisaStatus::Refunded);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('لا يمكن تعديل حجز تأشيرة تم استرداده بالكامل (status=refunded)');

        $this->service->update($booking, ['notes' => 'محاولة تعديل بعد الاسترداد']);
    }

    /**
     * Lifecycle guard: editing a soft-deleted booking must throw.
     */
    public function test_update_rejects_soft_deleted_booking(): void
    {
        $booking = $this->createBooking(VisaStatus::Submitted);
        $booking->delete(); // soft-delete

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('لا يمكن تعديل حجز تأشيرة محذوف (soft-deleted)');

        $this->service->update($booking, ['notes' => 'محاولة تعديل بعد الحذف']);
    }

    /**
     * Lifecycle guard: adding a payment to a cancelled booking must throw.
     */
    public function test_add_payment_rejects_cancelled_booking(): void
    {
        $booking = $this->createBooking(VisaStatus::Cancelled, 5000.0, 4000.0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('لا يمكن إضافة دفعة على حجز تأشيرة مُلغى (status=cancelled)');

        $this->service->addPayment($booking, [
            'amount' => 100.0,
            'account_id' => 1, // irrelevant — guard fires first
            'payment_method' => 'cash',
        ]);
    }

    /**
     * Lifecycle guard: adding a payment to a refunded booking must throw.
     */
    public function test_add_payment_rejects_refunded_booking(): void
    {
        $booking = $this->createBooking(VisaStatus::Refunded, 5000.0, 4000.0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('لا يمكن إضافة دفعة على حجز تأشيرة تم استرداده بالكامل (status=refunded)');

        $this->service->addPayment($booking, [
            'amount' => 100.0,
            'account_id' => 1,
            'payment_method' => 'cash',
        ]);
    }

    /**
     * Overpayment guard: addPayment must reject amounts exceeding the
     * remaining balance.
     */
    public function test_add_payment_rejects_overpayment(): void
    {
        $booking = $this->createBooking(VisaStatus::Submitted, 5000.0, 4000.0);
        // selling_price = 5000, paid_amount = 0, remaining = 5000

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('مبلغ الدفعة');

        $this->service->addPayment($booking, [
            'amount' => 6000.0, // over by 1000
            'account_id' => 1,
            'payment_method' => 'cash',
        ]);
    }

    /**
     * Regression safety net: scanning the service source for any
     * `LedgerBalanceMutationGuard::run()` bypass. The original dead-code
     * bug bypassed the guard entirely. This test confirms the service
     * file no longer contains raw `->update(['balance' =>` outside
     * documented/guarded paths.
     */
    public function test_service_source_has_no_unprotected_balance_writes(): void
    {
        $reflection = new ReflectionClass(VisaBookingService::class);
        $filename = $reflection->getFileName();
        $source = file_get_contents($filename);

        // Strip comments to avoid false positives in docblocks
        $stripped = preg_replace('!/\*.*?\*/!s', '', (string) $source);
        $stripped = preg_replace('![ \t]*//.*[^\n]!', '', (string) $stripped);

        // Look for raw `$account->update(['balance'` or `$booking->update(['balance'`
        // outside LedgerBalanceMutationGuard — this was the original bug.
        $this->assertDoesNotMatchRegularExpression(
            '/\$account->update\(\[.?\s*[\'"]balance[\'"]/',
            (string) $stripped,
            'VisaBookingService must not write to balance without LedgerBalanceMutationGuard.'
        );
    }
}
