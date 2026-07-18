<?php

namespace Tests\Feature\Finance;

use App\Enums\TransactionModule;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Account;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\User;
use App\Services\Finance\LedgerClearingAccounts;
use App\Services\Finance\PrepaidLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * اختبارات منع تكرار مشكلة "رصيد مسبق ناقلي/أنظمة يدخل في السالب"
 * (Cash box deficit على البرودكشن — Bk#1 FLT-20260704-6104D8 و Bk#2 FLT-20260705-5996E2).
 *
 * الـ Root Cause كان: Filament UI كان يسمح بتعديل `flight_carriers.balance`
 * و `flight_systems.balance` مباشرةً بدون تسجيل القيد المقابل في الـ prepaid GL
 * (Account "رصيد مسبق — ..."). عند الحجز، الـ `consumeCogs()` كان يخصم من
 * الـ GL الفارغ فيدخل في السالب.
 *
 * الـ Fix:
 *  - Phase 1: PrepaidLedgerService::consumeCogs() يرمي InsufficientBalanceException
 *    لو الرصيد المسبق < amount.
 *  - Phase 3: FlightCarrier/FlightSystem models تمنع تعديل balance خارج debit()/credit().
 *
 * @see app/Services/Finance/PrepaidLedgerService.php
 * @see app/Models/Flight/FlightCarrier.php
 * @see app/Models/Flight/FlightSystem.php
 */
class PrepaidCogsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected FlightCarrier $carrier;

    protected FlightSystem $system;

    protected Account $cashAccount;

    protected PrepaidLedgerService $prepaidService;

    protected LedgerClearingAccounts $clearingAccounts;

    protected function setUp(): void
    {
        parent::setUp();

        // تفعيل الـ guards الصارمة لاختبار الـ production logic
        config(['accounting.strict_test_guards' => true]);

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        // Cash source (الخزينة)
        $this->cashAccount = Account::create([
            'name' => 'Cash Source Test',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 100000.00,
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'tourism',
            'created_by' => $this->user->id,
        ]);

        // Carrier
        $this->system = FlightSystem::create([
            'name' => 'Test System',
            'code' => 'TST',
            'type' => 'gds',
            'currency' => 'EGP',
            'balance' => 0.00,
            'credit_limit' => 0.00,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        $this->carrier = FlightCarrier::create([
            'flight_system_id' => $this->system->id,
            'name' => 'Test Carrier',
            'code' => 'TC',
            'currency' => 'EGP',
            'balance' => 0.00,
            'credit_limit' => 0.00,
            'is_active' => true,
            'created_by' => $this->user->id,
        ]);

        // Force creation of clearing accounts via the service
        $this->clearingAccounts = app(LedgerClearingAccounts::class);
        $this->prepaidService = app(PrepaidLedgerService::class);
    }

    /**
     * Happy path: شحن ثم استهلاك — يجب أن ينجح.
     */
    public function test_consume_cogs_succeeds_when_prepaid_balance_sufficient(): void
    {
        // 1) Recharge 10000 EGP من الخزينة → رصيد مسبق ناقلين
        $this->prepaidService->recharge(
            prepaidKey: 'flight_carrier',
            source: $this->cashAccount,
            amount: 10000.00,
            module: TransactionModule::Flight,
            notes: 'Test recharge',
        );

        // 2) Recharge the carrier balance manually via credit() (passes the observer)
        $this->carrier->credit(10000.00, 'Test credit', $this->user->id);

        // 3) Consume COGS — should succeed since prepaid balance (10000) >= amount (3000)
        $tx = $this->prepaidService->consumeCogs(
            prepaidKey: 'flight_carrier',
            module: TransactionModule::Flight,
            amount: 3000.00,
            notes: 'Test consume',
        );

        $this->assertNotNull($tx);
        $this->assertEquals(3000.00, (float) $tx->amount);
    }

    /**
     * Critical guard: لو الرصيد المسبق < amount → InsufficientBalanceException.
     *
     * هذا هو الـ regression test لمشكلة Bk#1 في الإنتاج.
     */
    public function test_consume_cogs_throws_when_prepaid_balance_insufficient(): void
    {
        // الـ prepaid account موجود لكن رصيده = 0 (لم يتم شحنه)
        // الـ carrier.balance قد يكون > 0 (لو تم تعديله يدوياً من Filament)
        $this->carrier->credit(5000.00, 'Manual via Filament', $this->user->id);

        // Attempt to consume COGS — should throw
        $this->expectException(InsufficientBalanceException::class);
        $this->expectExceptionMessageMatches('/رصيد مسبق غير كافٍ/');

        try {
            $this->prepaidService->consumeCogs(
                prepaidKey: 'flight_carrier',
                module: TransactionModule::Flight,
                amount: 3000.00,
                notes: 'Should fail',
            );
        } catch (InsufficientBalanceException $e) {
            // Verify exception message contains the names + amounts
            $this->assertStringContainsString('رصيد مسبق', $e->getMessage());
            $this->assertStringContainsString('شحن رصيد', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Same guard for flight_system prepaid (Bk#2 issue on production).
     */
    public function test_consume_cogs_system_throws_when_prepaid_balance_insufficient(): void
    {
        // System balance > 0 (manual edit) but prepaid GL = 0
        $this->system->credit(8000.00, 'Manual via Filament', $this->user->id);

        $this->expectException(InsufficientBalanceException::class);

        $this->prepaidService->consumeCogs(
            prepaidKey: 'flight_system',
            module: TransactionModule::Flight,
            amount: 5000.00,
            notes: 'Should fail',
        );
    }

    /**
     * Edge case: لو الرصيد المسبق == المبلغ بالظبط → ينجح (مش أقل).
     */
    public function test_consume_cogs_succeeds_when_balance_exactly_equals_amount(): void
    {
        $this->prepaidService->recharge(
            prepaidKey: 'flight_carrier',
            source: $this->cashAccount,
            amount: 5000.00,
            module: TransactionModule::Flight,
            notes: 'Exact balance',
        );

        $tx = $this->prepaidService->consumeCogs(
            prepaidKey: 'flight_carrier',
            module: TransactionModule::Flight,
            amount: 5000.00,
            notes: 'Drain',
        );

        $this->assertNotNull($tx);
        $this->assertEquals(5000.00, (float) $tx->amount);
    }

    /**
     * amount <= 0 → null (existing behavior preserved).
     */
    public function test_consume_cogs_returns_null_for_zero_or_negative_amount(): void
    {
        $result1 = $this->prepaidService->consumeCogs(
            prepaidKey: 'flight_carrier',
            module: TransactionModule::Flight,
            amount: 0,
        );
        $result2 = $this->prepaidService->consumeCogs(
            prepaidKey: 'flight_carrier',
            module: TransactionModule::Flight,
            amount: -100,
        );

        $this->assertNull($result1);
        $this->assertNull($result2);
    }

    /**
     * Model observer (Phase 3): FlightCarrier.balance لا يمكن تعديله إلا
     * من debit()/credit() أو داخل LedgerBalanceMutationGuard.
     */
    public function test_flight_carrier_blocks_direct_balance_update(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/لا يمكن تعديل رصيد الناقل/');

        // Try to update balance directly (simulating Filament bypass)
        $this->carrier->balance = 99999;
        $this->carrier->save();
    }

    /**
     * Model observer allows update through credit()/debit() (these don't go through guard,
     * but Eloquent's increment/decrement bypasses the dirty check anyway).
     */
    public function test_flight_carrier_allows_credit_method(): void
    {
        $this->carrier->credit(5000.00, 'Test via credit()', $this->user->id);

        $this->carrier->refresh();
        $this->assertEquals(5000.00, (float) $this->carrier->balance);
    }

    /**
     * Model observer: FlightSystem.balance لا يمكن تعديله إلا من debit()/credit().
     */
    public function test_flight_system_blocks_direct_balance_update(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/لا يمكن تعديل رصيد نظام الحجز/');

        $this->system->balance = 99999;
        $this->system->save();
    }

    /**
     * Integration: Integration flow — recharge + consume — works end-to-end.
     */
    public function test_full_recharge_then_consume_flow(): void
    {
        // Step 1: Recharge from cash → prepaid GL AND carrier.balance
        \App\Support\Finance\LedgerBalanceMutationGuard::run(function () {
            $this->carrier->credit(15000.00, 'Recharge via service', $this->user->id);
        });

        $this->prepaidService->recharge(
            prepaidKey: 'flight_carrier',
            source: $this->cashAccount,
            amount: 15000.00,
            module: TransactionModule::Flight,
            notes: 'Full integration test',
        );

        // Step 2: Consume 5000 (within balance)
        $tx1 = $this->prepaidService->consumeCogs(
            prepaidKey: 'flight_carrier',
            module: TransactionModule::Flight,
            amount: 5000.00,
        );
        $this->assertNotNull($tx1);

        // Step 3: Consume 12000 (still within 15000 - 5000 = 10000, so this will fail since > 10000)
        // Reset expectation: actually 15000 - 5000 = 10000 remaining, so 12000 should fail
        $this->expectException(InsufficientBalanceException::class);
        $this->prepaidService->consumeCogs(
            prepaidKey: 'flight_carrier',
            module: TransactionModule::Flight,
            amount: 12000.00,
        );
    }
}