<?php

namespace Tests\Feature\HajjUmra;

use App\Enums\AccountType;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\Program;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\TransactionService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PHASE 4.6 — Cross-Module Duplicate-Income Guard Regression.
 *
 * After Phase 4.6's HajjUmra-specific price lock-down, the Path C
 * reversal/repost flow is NO LONGER EXERCISED for HajjUmra bookings
 * (prices cannot be edited after creation).
 *
 * However, the underlying TransactionService::recordJournalTransfer
 * duplicate-income guard (Phase 4.5A.1 — widened to exclude `عكس:`
 * rows) remains a critical defensive layer for OTHER modules
 * (Bus, Visa, Online) that may still legitimately need to reverse-and-
 * repost income transactions.
 *
 * This file keeps ONE regression test (the only one still meaningful):
 * the duplicate guard must STILL throw on a genuine active duplicate
 * even when a previously-reversed Income co-exists for the same related
 * entity. The original 7 Path-C-specific tests have been removed
 * (their assumptions no longer hold post-lock-down).
 *
 * The pre-existing 2 `test_4_3_update_*_PATH_C_KNOWN_DEFECT_*` tests
 * in HajjUmraBookingLifecycleTest.php have been repurposed to lock-
 * assertions (Phase 4.6.5).
 *
 * @see \App\Services\Finance\TransactionService::recordJournalTransfer (guard)
 * @audit-fix path-c-20260814 (guard retained) | phase-4.6-lockdown (hajj_lifecycle)
 */
class HajjUmraPathCFixTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Account $treasury;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name'      => 'Cross-Module Guard Tester',
            'email'     => 'guard-' . uniqid('', true) . '@test.local',
            'password'  => Hash::make('password'),
            'role'      => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->treasury = LedgerBalanceMutationGuard::run(function () {
            return Account::query()->create([
                'name'      => 'خزينة الحراسة',
                'type'      => AccountType::Cashbox->value,
                'currency'  => 'EGP',
                'balance'   => 1_000_000.00,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module'      => 'hajj_umra',
                'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);
        });
    }

    /**
     * CROSS-MODULE GUARD REGRESSION.
     *
     * After Phase 4.6 locks HajjUmra prices, the
     * HajjUmraBookingService::repostIncomeTransaction() path is no longer
     * reachable through the public API. Other modules (Bus, Visa, Online)
     * still use the same TransactionService, so this test exercises the
     * canonical guard directly with a scenario that mirrors a future bug:
     * a buggy code path tries to record a SECOND ACTIVE Income for a booking
     * that already has an ACTIVE Income. The guard must throw
     * "Duplicate income transaction blocked" regardless of who the caller
     * is, which module it is for, or which UI path it came from.
     *
     * Status of the C4 widening (Phase 4.5A.1):
     *   The guard's filter now allows a new ACTIVE Income IF AND ONLY IF
     *   the existing Income has notes starting with 'عكس:' / 'عكس '.
     *   Under Phase 4.6 lock-down, that state cannot be reached from the
     *   HajjUmra API. Cross-module callers (Bus / Visa / Online) never
     *   reverse-then-repost income. So the C4 widening branch is dormant.
     */
    public function test_4_5_6_duplicate_income_guard_still_blocks_genuine_active_duplicate(): void
    {
        // 1. Create a booking through the canonical flow (writes ACTIVE
        //    income + expense via HajjUmraBookingService::create).
        $customer = Customer::query()->create([
            'full_name' => 'عميل Cross-Module Guard',
            'phone'     => '+20107' . random_int(1000000, 9999999),
            'email'     => 'guard-cust-' . uniqid('', true) . '@test.local',
            'national_id' => '296' . str_pad((string) random_int(1, 999999999), 12, '0', STR_PAD_LEFT),
            'is_active' => true,
        ]);
        $program = Program::query()->create([
            'program_name'           => 'برنامج Guard',
            'program_type'           => 'hajj',
            'total_nights'           => 14,
            'mecca_nights'           => 8,
            'medina_nights'          => 6,
            'accommodation_type'     => 'DOUBLE',
            'mecca_hotel_name'       => 'فندق مكة',
            'medina_hotel_name'      => 'فندق المدينة',
            'departure_date'         => now()->addDays(60)->toDateString(),
            'return_date'            => now()->addDays(74)->toDateString(),
            'airline'                => 'Test Air',
            'executing_company'      => 'شركة تنفيذ',
            'departure_point'        => 'CAI',
            'default_selling_price'  => 50000.00,
            'default_purchase_price' => 42000.00,
            'is_active'              => true,
            'created_by'             => $this->admin->id,
        ]);

        $response = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id'    => $customer->id,
            'program_id'     => $program->id,
            'purchase_price' => 40000,
            'selling_price'  => 50000,
            'currency'       => 'EGP',
            'per_person'     => true,
            'status'         => 'confirmed',
            'agent_name'     => 'وكيل Guard',
            'account_id'     => $this->treasury->id,
        ]);
        $response->assertCreated();

        $booking = HajjUmraBooking::query()->findOrFail($response->json('data.id'));
        $this->assertNotNull($booking->income_transaction_id);

        // Confirm the existing income is currently ACTIVE (no عكس prefix).
        $existingIncome = Transaction::query()->findOrFail($booking->income_transaction_id);
        $this->assertNotEmpty($existingIncome->notes);
        $this->assertStringStartsNotWith('عكس:', (string) $existingIncome->notes,
            'Test prerequisite: existing income must be ACTIVE for this guard test.');

        // 2. A buggy code path attempts to insert a SECOND ACTIVE income
        //    for the same booking — the guard must throw.
        $customerAccountId = DB::table('customers')->where('id', $booking->customer_id)->value('account_id');
        $this->assertNotNull($customerAccountId);

        $service = app(TransactionService::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Duplicate income transaction blocked/');

        $service->recordIncome([
            'amount'        => 99999,
            'to_account_id' => $customerAccountId,
            'module'        => TransactionModule::HajjUmra->value,
            'related_type'  => HajjUmraBooking::class,
            'related_id'    => $booking->id,
            'notes'         => 'attempted duplicate sale — must throw',
            'created_by'    => $this->admin->id,
        ]);
    }
}
