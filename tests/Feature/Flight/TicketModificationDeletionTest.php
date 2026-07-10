<?php

namespace Tests\Feature\Flight;

use App\Models\Customer;
use App\Models\Flight\AirlineAccount;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Flight\TicketModification;
use App\Models\Account;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\ModificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Feature tests for TicketModification reversal — Priority 3.5.
 *
 * ⚠️  GAP-AWARE TEST:
 *   TicketModification is the **known GAP** in the financial reversal feature.
 *   When a modification is confirmed, `confirmModification()` debits AirlineAccount.balance
 *   DIRECTLY (via AirlineAccountDebitService) and posts a paired GL entry on the
 *   prepaid_flight_carrier GL account. On reversal, `reverseConfirmation()` credits
 *   AirlineAccount.balance directly — **it does NOT post a paired GL reversal entry**.
 *   This means:
 *     - AirlineAccount.balance IS reversed (net delta == 0) ✓
 *     - GL account balance may not be reversed (GAP) — but that mirrors the
 *       confirmation side which also did not use the canonical GL path.
 *
 *   See: docs/ARCHITECTURE.md § 8.5 and Phase 1v2 todo in ModificationService.
 *
 * Tested branches:
 *   - Confirmed modification → reversed + AirlineAccount credited back.
 *   - Non-confirmed modification → soft-deleted without balance change.
 *   - Idempotency: double-reversal throws.
 *   - Booking fields restored to pre-modification values.
 */
class TicketModificationDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected ModificationService $modificationService;

    protected FlightBookingService $bookingService;

    protected User $admin;

    protected Customer $customer;

    protected FlightSystem $flightSystem;

    protected FlightCarrier $carrier;

    protected Account $cashbox;

    protected AirlineAccount $airlineAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->modificationService = app(ModificationService::class);
        $this->bookingService = app(FlightBookingService::class);

        $this->admin = User::factory()->create([
            'name' => 'Mod Test Admin',
            'email' => 'mod-admin@test.com',
            'role' => 'admin',
            'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        $this->customer = Customer::create([
            'full_name' => 'Mod Test Customer',
            'phone' => '0123456789',
            'email' => 'mod-customer@test.com',
            'national_id' => '55566677788899',
            'city' => 'Cairo',
        ]);

        $this->flightSystem = FlightSystem::create([
            'name' => 'Mod Test System',
            'code' => 'MTS'.substr(md5((string) microtime(true)), 0, 6),
            'type' => 'gds',
            'is_active' => true,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 0,
            'created_by' => $this->admin->id,
        ]);

        $this->carrier = FlightCarrier::create([
            'name' => 'Mod Test Airline',
            'code' => 'MTA',
            'flight_system_id' => $this->flightSystem->id,
            'currency' => 'EGP',
            'balance' => 0,
            'credit_limit' => 50000,
            'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->cashbox = Account::create([
            'name' => 'Mod Test Cashbox',
            'type' => 'cashbox',
            'balance' => 100000,
            'currency' => 'EGP',
            'is_active' => true,
            'owner_type' => 'office',
            'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $this->carrier,
            $this->cashbox,
            100000.00,
            'Mod test setup'
        );

        $this->cashbox->refresh();

        $this->airlineAccount = AirlineAccount::create([
            'flight_carrier_id' => $this->carrier->id,
            'name' => 'Mod Test Airline Account',
            'code' => 'MTAA'.substr(md5((string) microtime(true)), 0, 4),
            'system_type' => 'Amadeus',
            'currency' => 'EGP',
            'is_active' => true,
        ]);

        // `balance` is intentionally removed from AirlineAccount's fillable.
        // We set it after creation via direct property assignment + save.
        // In tests, the updating event guard logs a warning but does not throw.
        $this->airlineAccount->balance = 10000.00;
        $this->airlineAccount->save();

        Log::info('TicketModificationDeletionTest setUp complete', [
            'airline_account_id' => $this->airlineAccount->id,
            'airline_balance' => (float) $this->airlineAccount->balance,
        ]);
    }

    protected function createConfirmedBookingWithModification(
        int $sellingPrice = 18000,
        int $purchasePrice = 15000,
        float $airlineChangeFee = 500.0
    ): array {
        $booking = $this->bookingService->createBooking([
            'customer_id'      => $this->customer->id,
            'airline_name'     => 'Mod Test Airline',
            'from_airport'     => 'CAI',
            'to_airport'       => 'JED',
            'departure_date'   => now()->addDays(7)->toDateString(),
            'trip_type'        => 'one_way',
            'currency'         => 'EGP',
            'purchase_price'   => $purchasePrice,
            'selling_price'    => $sellingPrice,
            'flight_carrier_id'=> $this->carrier->id,
            'account_id'       => $this->cashbox->id,
            'airline_account_id' => $this->airlineAccount->id,
            'passengers'       => [
                ['name' => 'Mod Pax', 'type' => 'adult'],
            ],
        ]);

        $this->bookingService->addPayment($booking, [
            'amount'         => $sellingPrice,
            'payment_method' => 'cash',
            'account_id'     => $this->cashbox->id,
        ]);

        // NOTE: We bypass the listener-based confirmation flow because the
        // ProcessTicketModificationAccounting listener tries to firstOrCreate
        // accounts with 'type' values not present in the AccountType enum
        // ('treasury' / 'liability'). That's a pre-existing bug in the listener.
        // For the reversal test we only need:
        //   1. A confirmed modification row (status='confirmed', confirmed_at set)
        //   2. The airline account debited by airline_change_fee
        // We simulate (2) directly by calling AirlineAccount::debit() which goes
        // through the proper debit() → mutateBalanceInternal path.
        $modification = $this->modificationService->createRequest([
            'booking_id'             => $booking->id,
            'modification_type'      => 'date_change',
            'new_departure_date'     => now()->addDays(14)->toDateString(),
            'new_destination'        => 'RUH',
            'airline_change_fee'     => $airlineChangeFee,
            'agency_commission'      => 200.0,
            'currency'               => 'EGP',
            'payment_method'         => 'cash',
            'notes'                  => 'Test modification',
        ], $this->admin->id);

        // Mark modification as confirmed with snapshots (as confirmModification would)
        $modification->status = 'confirmed';
        $modification->confirmed_at = now();
        $modification->airline_change_fee_snapshot = $airlineChangeFee;
        $modification->commission_snapshot = 200.0;
        $modification->save();

        // Update booking fields as the confirm flow would
        $booking->departure_date = $modification->new_departure_date;
        $booking->destination = $modification->new_destination;
        $booking->last_modified_at = now();
        $booking->modification_count = 1;
        $booking->save();

        // Debit the airline account directly (mirrors what the listener would do)
        $this->airlineAccount->refresh();
        $this->airlineAccount->debit($airlineChangeFee, $booking->id, $this->admin->id);

        return ['booking' => $booking, 'modification' => $modification];
    }

    public function test_confirmed_modification_reversal_credits_back_airline_account(): void
    {
        Log::info('Starting: test_confirmed_modification_reversal_credits_back_airline_account');

        $airlineChangeFee = 500.0;
        $airlineBalanceBefore = (float) $this->airlineAccount->fresh()->balance;

        ['booking' => $booking, 'modification' => $modification] = $this->createConfirmedBookingWithModification(
            18000, 15000, $airlineChangeFee
        );

        // AirlineAccount.balance was debited by airline_change_fee
        $airlineBalanceAfterConfirm = (float) $this->airlineAccount->fresh()->balance;
        $this->assertLessThan(
            $airlineBalanceBefore,
            $airlineBalanceAfterConfirm,
            'AirlineAccount.balance must decrease on confirm (debit by airline_change_fee)'
        );
        $this->assertEquals(
            round($airlineBalanceBefore - $airlineChangeFee, 2),
            $airlineBalanceAfterConfirm,
            'AirlineAccount.balance should be debited by exactly airline_change_fee'
        );

        // Booking fields should be updated by the modification
        $booking->refresh();
        $this->assertNotEquals(
            $modification->original_departure_date->toDateString(),
            $booking->departure_date->toDateString(),
            'Booking departure_date must be updated by modification'
        );
        $this->assertEquals(1, (int) $booking->modification_count,
            'Booking modification_count must be incremented');

        // ── ACT: reverse the modification ──────────────────────────
        $this->modificationService->reverseConfirmation($modification->id, $this->admin->id);

        // ── ASSERT 1: AirlineAccount credited back ──────────────────
        $airlineBalanceAfterReverse = (float) $this->airlineAccount->fresh()->balance;
        $this->assertEquals(
            $airlineBalanceBefore,
            $airlineBalanceAfterReverse,
            'AirlineAccount.balance must be fully restored after reversal (net delta == 0)'
        );

        // ── ASSERT 2: Modification soft-deleted ────────────────────
        $modification->refresh();
        $this->assertTrue($modification->trashed(),
            'TicketModification must be soft-deleted after reversal');

        // ── ASSERT 3: Booking fields restored ──────────────────────
        $booking->refresh();
        $this->assertEquals(
            $modification->original_departure_date->toDateString(),
            $booking->departure_date->toDateString(),
            'Booking departure_date must be restored to original value'
        );
        $this->assertEquals(
            $modification->original_destination,
            $booking->destination,
            'Booking destination must be restored to original value'
        );
        $this->assertEquals(0, (int) $booking->modification_count,
            'Booking modification_count must be decremented');

        Log::info('PASSED: test_confirmed_modification_reversal_credits_back_airline_account', [
            'airline_balance_before' => $airlineBalanceBefore,
            'airline_balance_after_confirm' => $airlineBalanceAfterConfirm,
            'airline_balance_after_reverse' => $airlineBalanceAfterReverse,
        ]);
    }

    public function test_non_confirmed_modification_reversal_soft_deletes_without_balance_change(): void
    {
        $airlineBalanceBefore = (float) $this->airlineAccount->fresh()->balance;

        $booking = $this->bookingService->createBooking([
            'customer_id'      => $this->customer->id,
            'airline_name'     => 'Mod Test Airline',
            'from_airport'     => 'CAI',
            'to_airport'       => 'JED',
            'departure_date'   => now()->addDays(7)->toDateString(),
            'trip_type'        => 'one_way',
            'currency'         => 'EGP',
            'purchase_price'   => 15000,
            'selling_price'    => 18000,
            'flight_carrier_id'=> $this->carrier->id,
            'account_id'       => $this->cashbox->id,
            'airline_account_id' => $this->airlineAccount->id,
            'passengers'       => [
                ['name' => 'Pending Mod Pax', 'type' => 'adult'],
            ],
        ]);

        // Create but do NOT confirm
        $modification = $this->modificationService->createRequest([
            'booking_id'             => $booking->id,
            'modification_type'      => 'date_change',
            'new_departure_date'     => now()->addDays(14)->toDateString(),
            'new_destination'        => 'RUH',
            'airline_change_fee'     => 500.0,
            'agency_commission'      => 200.0,
            'currency'               => 'EGP',
            'payment_method'         => 'cash',
        ], $this->admin->id);

        $this->assertEquals('draft', $modification->status);

        // Airline balance unchanged (no confirm = no debit)
        $this->assertEquals(
            $airlineBalanceBefore,
            (float) $this->airlineAccount->fresh()->balance,
            'AirlineAccount.balance must not change on pending modification creation'
        );

        // Reverse
        $this->modificationService->reverseConfirmation($modification->id, $this->admin->id);

        $modification->refresh();
        $this->assertTrue($modification->trashed(), 'Pending modification must be soft-deleted');

        // Airline balance still unchanged
        $this->assertEquals(
            $airlineBalanceBefore,
            (float) $this->airlineAccount->fresh()->balance,
            'AirlineAccount.balance must not change when reversing pending modification'
        );

        Log::info('PASSED: test_non_confirmed_modification_reversal_soft_deletes_without_balance_change');
    }

    public function test_double_reversal_of_modification_throws(): void
    {
        ['booking' => $booking, 'modification' => $modification] = $this->createConfirmedBookingWithModification(
            18000, 15000, 500.0
        );

        // First reversal succeeds
        $this->modificationService->reverseConfirmation($modification->id, $this->admin->id);

        // Second reversal must throw (idempotency)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/محذوف بالفعل/');

        $this->modificationService->reverseConfirmation($modification->id, $this->admin->id);
    }
}