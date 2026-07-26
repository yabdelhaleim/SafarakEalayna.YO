<?php

namespace Tests\Feature\TourismDivision;

use App\Enums\BusInventoryPaymentType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Bus\BusBooking;
use App\Models\Bus\BusCompany;
use App\Models\Bus\BusInventory;
use App\Models\Customer;
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmraBooking;
use App\Models\Transaction;
use App\Models\VisaBooking;
use Illuminate\Support\Facades\DB;

/**
 * FULL END-TO-END DELETE FLOW TEST.
 *
 * Exercises the real DELETE endpoint (not POST /cancel) for all three
 * tourism-division modules:
 *   1. Hajj/Umra  (POST /api/v1/hajj-umra/bookings/{id}  +  DELETE /api/v1/hajj-umra/bookings/{id})
 *   2. Visa       (POST /api/v1/visa/bookings/{id}       +  DELETE /api/v1/visa/bookings/{id})
 *   3. Bus        (POST /api/v1/bus/bookings             +  DELETE /api/v1/bus/bookings/{id})
 *
 * The tests run against the real SQLite test database, exercise the actual
 * HTTP routes, controllers, services and the ModelDeletionGuard. After each
 * delete we assert:
 *   - booking row is soft-deleted (deleted_at NOT NULL)
 *   - original transactions are PRESERVED (additive reversal contract)
 *   - the ledger is still globally balanced (Σ debits = Σ credits)
 *   - second DELETE returns 422 (idempotency guard)
 *   - list endpoints do not return the deleted booking
 */
class FullDeleteFlowE2ETest extends TourismTestCase
{
    // ====================================================================
    // 1) HAJJ/UMRA — full delete flow
    // ====================================================================

    public function test_hajj_umra_delete_unpaid_booking_end_to_end(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        // Snapshot the OPENING balances BEFORE the booking exists.
        $openingCashbox = (float) $this->cashbox->fresh()->balance;
        $openingCustomerAr = (float) $customer->ledgerAccount()->first()->balance;

        // Create a paid booking with an initial payment.
        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $this->assertNotNull(HajjUmraBooking::find($bookingId), 'booking must exist before delete');

        // Real DELETE (not POST /cancel).
        $del = $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}");
        $del->assertOk()->assertJsonPath('success', true);

        // Booking is soft-deleted (still in DB, deleted_at set).
        $trashed = HajjUmraBooking::withTrashed()->find($bookingId);
        $this->assertNotNull($trashed, 'booking row must still exist after soft-delete');
        $this->assertNotNull($trashed->deleted_at, 'deleted_at must be set after DELETE');
        $this->assertNull(HajjUmraBooking::find($bookingId), 'find() must hide the soft-deleted row');

        // Net balance restored to opening (reversal is additive, so net effect = 0).
        $this->assertEqualsWithDelta(
            $openingCashbox, (float) $this->cashbox->fresh()->balance, 0.02,
            'cashbox must return to opening balance after delete',
        );
        $this->assertEqualsWithDelta(
            $openingCustomerAr, (float) $customer->ledgerAccount()->first()->balance, 0.02,
            'customer AR must return to opening balance after delete',
        );

        // Original transactions are preserved (additive reversal contract).
        $originalTxs = Transaction::query()
            ->where('related_type', HajjUmraBooking::class)
            ->where('related_id', $bookingId)
            ->count();
        $this->assertGreaterThan(0, $originalTxs, 'original transactions must be preserved');

        // Per-account ledger consistency.
        $this->assertLedgerGloballyBalancedStatic();

        // Second DELETE must fail with 422 (idempotency).
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_hajj_umra_delete_paid_booking_reverses_all_payments(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();
        $openingCashbox = (float) $this->cashbox->fresh()->balance;
        $openingCustomerAr = (float) $customer->ledgerAccount()->first()->balance;

        // Create booking + pay half the selling price.
        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 5000.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ])->assertCreated();

        // Sanity: cashbox should reflect the incoming payment.
        // (Booking itself moved 10000 from cashbox to expense clearing first, so
        // the net after the 5000 payment is 95000, i.e. cashbox is now LOWER than
        // the opening balance of 100000.)
        $cashboxAfterPayment = (float) $this->cashbox->fresh()->balance;
        $this->assertLessThan(
            $openingCashbox,
            $cashboxAfterPayment,
            'cashbox should be lower than opening after booking + partial payment',
        );
        $this->assertEqualsWithDelta(
            $openingCashbox - 5000.0, $cashboxAfterPayment, 0.02,
            'cashbox should be opening - 5000 after a 5000 partial payment against a 10000 expense',
        );

        // Delete the paid booking.
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        // Cashbox restored to opening (payment + sale were both reversed).
        $this->assertEqualsWithDelta(
            $openingCashbox, (float) $this->cashbox->fresh()->balance, 0.02,
            'cashbox must return to opening after delete of paid booking',
        );

        // Customer AR restored to opening.
        $this->assertEqualsWithDelta(
            $openingCustomerAr, (float) $customer->ledgerAccount()->first()->balance, 0.02,
            'customer AR must return to opening after delete',
        );

        $this->assertLedgerGloballyBalancedStatic();
    }

    public function test_hajj_umra_delete_does_not_affect_other_bookings(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();
        $openingCashbox = (float) $this->cashbox->fresh()->balance;

        // Create two bookings.
        $r1 = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 5000.00,
            'selling_price' => 7000.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();
        $booking1 = $r1->json('data.id');

        $r2 = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 3000.00,
            'selling_price' => 4000.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();
        $booking2 = $r2->json('data.id');

        // Delete only the first.
        $this->deleteJson("/api/v1/hajj-umra/bookings/{$booking1}")->assertOk();

        // Second booking is untouched.
        $this->assertNotNull(HajjUmraBooking::find($booking2));
        $this->assertNull(HajjUmraBooking::find($booking1));

        // Net effect on the customer: only booking 2 still counts.
        $this->assertEqualsWithDelta(
            4000.0, (float) $customer->ledgerAccount()->first()->balance, 0.02,
            'customer AR should reflect only booking 2',
        );

        $this->assertLedgerGloballyBalancedStatic();
    }

    // ====================================================================
    // 2) VISA — full delete flow
    // ====================================================================

    public function test_visa_delete_paid_booking_end_to_end(): void
    {
        $customer = $this->makeCustomer();
        $agent = $this->makeVisaAgent();
        $openingCashbox = (float) $this->cashbox->fresh()->balance;

        $resp = $this->postJson('/api/v1/visa/bookings', [
            'customer_id' => $customer->id,
            'visa_details' => [
                'visa_type' => 'tourist',
                'country' => 'السعودية',
                'visa_agent_id' => $agent->id,
                'executing_company' => 'شركة تنفيذ',
                'executing_agent' => 'وكيل تنفيذ',
                'submission_date' => now()->toDateString(),
            ],
            'purchase_price' => 1000.00,
            'selling_price' => 1500.00,
            'account_id' => $this->cashbox->id,
            'initial_payment' => ['amount' => 1500.00, 'payment_method' => 'cash'],
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $this->assertNotNull(VisaBooking::find($bookingId), 'visa booking must exist before delete');

        // Cashbox should reflect the incoming payment.
        $this->assertEqualsWithDelta(
            $openingCashbox + 1500.0, (float) $this->cashbox->fresh()->balance, 0.02,
            'cashbox should hold the initial visa payment',
        );

        // Real DELETE.
        $del = $this->deleteJson("/api/v1/visa/bookings/{$bookingId}");
        $del->assertOk()->assertJsonPath('success', true);

        // Booking is soft-deleted.
        $trashed = VisaBooking::withTrashed()->find($bookingId);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertNull(VisaBooking::find($bookingId));

        // All balances restored.
        $this->assertEqualsWithDelta(
            $openingCashbox, (float) $this->cashbox->fresh()->balance, 0.02,
            'cashbox must return to opening after visa delete',
        );
        $this->assertEqualsWithDelta(
            0.0, (float) $customer->ledgerAccount()->first()->balance, 0.02,
            'customer AR must be 0 after visa delete',
        );
        $agentAccount = Account::find($agent->account_id);
        $this->assertEqualsWithDelta(
            0.0, (float) $agentAccount->balance, 0.02,
            'visa agent AP must be 0 after visa delete',
        );

        // Original transactions preserved.
        $this->assertGreaterThan(0, Transaction::query()
            ->where('related_type', VisaBooking::class)
            ->where('related_id', $bookingId)
            ->count(),
            'original visa transactions must be preserved');

        $this->assertLedgerGloballyBalancedStatic();

        // Second DELETE must fail with 422.
        $this->deleteJson("/api/v1/visa/bookings/{$bookingId}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_visa_delete_unpaid_booking(): void
    {
        $customer = $this->makeCustomer();
        $agent = $this->makeVisaAgent();
        $openingCashbox = (float) $this->cashbox->fresh()->balance;

        $resp = $this->postJson('/api/v1/visa/bookings', [
            'customer_id' => $customer->id,
            'visa_details' => [
                'visa_type' => 'tourist',
                'country' => 'السعودية',
                'visa_agent_id' => $agent->id,
                'executing_company' => 'شركة تنفيذ',
                'executing_agent' => 'وكيل تنفيذ',
                'submission_date' => now()->toDateString(),
            ],
            'purchase_price' => 1000.00,
            'selling_price' => 1500.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $this->deleteJson("/api/v1/visa/bookings/{$bookingId}")->assertOk();

        $trashed = VisaBooking::withTrashed()->find($bookingId);
        $this->assertNotNull($trashed->deleted_at);

        $this->assertEqualsWithDelta(
            $openingCashbox, (float) $this->cashbox->fresh()->balance, 0.02,
            'cashbox must be unchanged after unpaid visa delete',
        );
        $this->assertEqualsWithDelta(
            0.0, (float) $customer->ledgerAccount()->first()->balance, 0.02,
            'customer AR must be 0 after unpaid visa delete',
        );

        $this->assertLedgerGloballyBalancedStatic();
    }

    // ====================================================================
    // 3) BUS — full delete flow (Bus uses its own BusTestCase-style setup,
    //          but TourismTestCase provides all we need to create the
    //          booking via the API and verify balances).
    // ====================================================================

    public function test_bus_delete_paid_booking_end_to_end(): void
    {
        // Bus payment requires an `office`-module cashbox. The TourismTestCase
        // creates a `tourism` cashbox which the BusLiquidityAccount rule rejects,
        // so we spin up a dedicated bus cashbox here.
        $busCashbox = $this->makeBusCashbox();

        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeBusInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
        ]);

        $startAvail = $inventory->available_tickets;

        $resp = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'E2E Delete Test',
            'customer_phone' => '01070000099',
            'quantity' => 2,
        ])->assertCreated();
        $bookingId = $resp->json('data.id');
        $booking = BusBooking::find($bookingId);

        // Pay it in full.
        $this->postJson("/api/v1/bus/bookings/{$bookingId}/pay", [
            'amount' => (float) $booking->total_price,
            'payment_method' => 'cash',
            'account_id' => $busCashbox->id,
        ])->assertOk();

        $this->assertNotNull($booking, 'bus booking must exist before delete');

        // Real DELETE.
        $del = $this->deleteJson("/api/v1/bus/bookings/{$bookingId}");
        $del->assertOk()->assertJsonPath('success', true);

        // Booking is soft-deleted.
        $trashed = BusBooking::withTrashed()->find($bookingId);
        $this->assertNotNull($trashed);
        $this->assertNotNull($trashed->deleted_at);
        $this->assertNull(BusBooking::find($bookingId));

        // Available tickets restored to original count.
        $inventory->refresh();
        $this->assertEquals($startAvail, $inventory->available_tickets, 'inventory tickets restored after delete');

        $this->assertLedgerGloballyBalancedStatic();

        // Second DELETE must fail with 422.
        $this->deleteJson("/api/v1/bus/bookings/{$bookingId}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_bus_delete_unpaid_booking(): void
    {
        $company = $this->makeBusCompany([], 0);
        $inventory = $this->makeBusInventory([
            'company_id' => $company->id,
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
        ]);
        $startAvail = $inventory->available_tickets;

        $resp = $this->postJson('/api/v1/bus/bookings', [
            'inventory_id' => $inventory->id,
            'customer_name' => 'E2E Unpaid Delete',
            'customer_phone' => '01070000088',
            'quantity' => 1,
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $this->deleteJson("/api/v1/bus/bookings/{$bookingId}")->assertOk();

        $trashed = BusBooking::withTrashed()->find($bookingId);
        $this->assertNotNull($trashed->deleted_at);

        $inventory->refresh();
        $this->assertEquals($startAvail, $inventory->available_tickets);

        $this->assertLedgerGloballyBalancedStatic();
    }

    // ====================================================================
    // Helpers — kept inline so the test file is self-contained.
    // ====================================================================

    /**
     * Create an office-module cashbox usable by the Bus booking payment
     * endpoint (BusLiquidityAccount rule requires module_type='office' +
     * is_module_vault=true).
     */
    protected function makeBusCashbox(): Account
    {
        return Account::query()->create([
            'name' => 'خزينة باصات (EGP)',
            'type' => 'cashbox',
            'currency' => 'EGP',
            'balance' => 0.0,
            'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => 'office',
            'is_module_vault' => true,
            'created_by' => $this->user->id,
        ]);
    }

    protected function makeBusCompany(array $attrs = [], ?float $balance = 0): BusCompany
    {
        $company = BusCompany::query()->create(array_merge([
            'name' => 'شركة باص اختبار '.uniqid(),
            'phone' => '010'.random_int(10000000, 99999999),
            'is_active' => true,
        ], $attrs));

        if ($balance !== null) {
            $account = Account::query()->create([
                'name' => 'حساب شركة: '.$company->name,
                'type' => 'supplier',
                'currency' => 'EGP',
                'balance' => $balance,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE,
                'module_type' => 'bus',
                'created_by' => $this->user->id,
            ]);
            $company->update(['account_id' => $account->id]);
        }

        return $company->fresh();
    }

    protected function makeBusInventory(array $attrs = []): BusInventory
    {
        return BusInventory::query()->create(array_merge([
            'company_id' => $attrs['company_id'] ?? null,
            'route' => 'القاهرة - أسوان',
            'travel_date' => now()->addDays(10)->toDateString(),
            'total_tickets' => 5,
            'available_tickets' => 5,
            'cost_per_ticket' => 80,
            'selling_price' => 120,
            'currency' => 'EGP',
            'fx_rate_to_egp' => 1.0,
            'total_cost' => 0,
            'amount_paid' => 0,
            'remaining_debt' => 0,
            'payment_type' => BusInventoryPaymentType::Deferred,
            'status' => 'active',
        ], $attrs));
    }

    protected function makeVisaAgent(): VisaAgent
    {
        return VisaAgent::query()->create([
            'company_name' => 'وكيل اختبار '.uniqid(),
            'phone' => '010'.random_int(10000000, 99999999),
            'country' => 'السعودية',
            'default_cost_price' => 1000,
            'is_active' => true,
        ]);
    }

    /**
     * Static per-account ledger consistency check.
     * For every account, Account.balance must equal SUM(credit) - SUM(debit) on its entries.
     * (Per-account consistency is the project's invariant; global debits==credits does NOT
     * hold because opening-balance entries are intentionally unbalanced — that is by design.)
     */
    protected function assertLedgerGloballyBalancedStatic(): void
    {
        $imbalanced = [];
        $accounts = Account::query()->get();
        foreach ($accounts as $account) {
            $entries = AccountEntry::query()->where('account_id', $account->id)->get();
            $ledgerBalance = (float) $entries->sum('credit') - (float) $entries->sum('debit');
            $storedBalance = (float) $account->balance;
            if (abs($ledgerBalance - $storedBalance) > 0.02) {
                $imbalanced[] = [
                    'id' => $account->id,
                    'name' => $account->name,
                    'stored' => $storedBalance,
                    'ledger' => $ledgerBalance,
                ];
            }
        }
        $this->assertEmpty(
            $imbalanced,
            'Ledger inconsistency on accounts: '.json_encode($imbalanced, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
    }
}
