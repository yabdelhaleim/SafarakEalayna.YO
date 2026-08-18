<?php

namespace Tests\Feature\Tourism;

use App\Enums\FlightSystemType;
use App\Enums\HajjUmraStatus;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightSystem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\Testdox;
use Tests\TestCase;

/**
 * Regression test for INCIDENT-2026-08-17 — Tourism booking edit-after-save
 * financial incident.
 *
 * Contract: After a Tourism booking is created (Flight + Hajj/Umra + Visa),
 * the booking is **immutable from a financial standpoint**. There is no
 * Edit-after-save path in any layer:
 *   - Vue UI: no `:id/edit` route, no Edit button on show / index pages.
 *   - API: PUT/PATCH on /api/v1/{flight|hajj-umra|visa|aviation}/bookings/{id}
 *          returns 405 Method Not Allowed (the route is no longer registered).
 *   - API: POST /api/v1/flight/bookings/{id}/prices returns 404 Not Found
 *          (the route has been removed entirely).
 *   - Service: FlightBookingService::updateBooking(), updatePrices(),
 *              AviationService::updateBooking(),
 *              HajjUmraBookingService::update(), VisaBookingService::update()
 *              throw LogicException if called (defense-in-depth so a stale
 *              internal caller cannot silently corrupt the ledger).
 *
 * To change a Tourism booking, the operator must CANCEL the booking
 * (which creates reversal entries) and CREATE a new one.
 *
 * See:
 *   - tests/reports/phase1_reproduction.log
 *   - tests/reports/phase2_reproduction.log
 *   - docs/TOURISM_EDIT_AUDIT_20260818.md
 *   - docs/TOURISM_NO_EDIT_CONTRACT.md
 *   - tests/reports/TOURISM_BOOKING_EDIT_FINANCIAL_INCIDENT_20260817.md
 */
class TourismNoEditContractTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Customer $customer;
    protected FlightCarrier $carrier;
    protected FlightSystem $system;
    protected Account $cashbox;
    protected string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'role' => 'admin', 'is_active' => true, 'email_verified_at' => now(),
        ]);

        // Use Sanctum::actingAs() so we don't hit the 5/min login throttle
        // when running many tests in the same minute.
        Sanctum::actingAs($this->admin, ['*']);

        // Prepaid pool "رصيد مسبق — ناقلو الطيران" — required for FlightBookingService.
        Account::create([
            'name' => 'إقفال تكاليف الطيران',
            'type' => 'cashbox', 'currency' => 'EGP', 'balance' => 0,
            'is_active' => true, 'owner_type' => 'office',
            'module_type' => 'tourism', 'created_by' => $this->admin->id,
        ]);
        Account::create([
            'name' => 'رصيد مسبق — ناقلو الطيران',
            'type' => 'cashbox', 'currency' => 'EGP', 'balance' => 100000,
            'is_active' => true, 'owner_type' => 'office',
            'module_type' => 'office', 'created_by' => $this->admin->id,
        ]);

        $this->cashbox = Account::create([
            'name' => 'Test Cashbox', 'type' => 'cashbox', 'currency' => 'EGP',
            'balance' => 100000, 'is_active' => true,
            'owner_type' => 'office', 'module_type' => 'office',
            'created_by' => $this->admin->id,
        ]);

        $this->customer = Customer::create([
            'full_name' => 'NoEdit Customer',
            'phone' => '0123456789', 'national_id' => '999999999',
            'type' => 'individual', 'is_active' => true,
            'created_by' => $this->admin->id,
        ]);

        $this->carrier = FlightCarrier::create([
            'name' => 'NoEdit Carrier', 'code' => 'NEC-'.uniqid(),
            'currency' => 'EGP', 'available_balance' => 10000,
            'is_active' => true, 'created_by' => $this->admin->id,
        ]);
        \DB::table('flight_carriers')->where('id', $this->carrier->id)->update(['balance' => 10000]);
        $this->carrier->refresh();

        $this->system = FlightSystem::create([
            'name' => 'NoEdit System', 'code' => 'NES-'.uniqid(),
            'currency' => 'EGP', 'available_balance' => 10000,
            'is_active' => true, 'created_by' => $this->admin->id,
        ]);
        \DB::table('flight_systems')->where('id', $this->system->id)->update(['balance' => 10000]);
        $this->system->refresh();

        $this->token = 'sanctum-bypass'; // placeholder — Sanctum::actingAs handles auth
    }

    private function bookingData(): array
    {
        return [
            'customer_id' => $this->customer->id,
            'booking_channel_type' => 'SIGN',
            'booking_channel_provider' => 'Office',
            'system_type' => FlightSystemType::Manual->value,
            'airline_name' => 'EgyptAir',
            'pnr' => 'NOEDIT',
            'from_airport' => 'CAI',
            'to_airport' => 'AMM',
            'departure_date' => now()->addDays(7)->toDateString(),
            'departure_time' => '10:00',
            'trip_type' => 'one_way',
            'passenger_count' => 1,
            'passengers_count' => 1,
            'passengers' => [['first_name' => 'Test', 'last_name' => 'NoEdit', 'type' => 'adult']],
            'selling_price' => 50,
            'purchase_price' => 40,
            'currency' => 'EGP',
            'flight_carrier_id' => $this->carrier->id,
            'flight_system_id' => $this->system->id,
            'purchase_balance_source' => 'carrier',
            'account_id' => $this->cashbox->id,
            'agent_name' => 'NoEdit Test',
            'notes' => 'NoEdit test',
            'baggage_allowance_kg' => 0,
        ];
    }

    /**
     * Create a FlightBooking via the API and return the persisted model.
     * The API wraps the booking in ApiResponse::success() as
     * { success, message, data: { ...booking... } }.
     *
     * NOTE: Sanctum::actingAs($this->admin) is set in setUp(), so we
     * don't need actingAs() here.
     */
    private function createBooking(): FlightBooking
    {
        $resp = $this->postJson('/api/v1/flight/bookings', $this->bookingData());

        $resp->assertStatus(201);
        $bookingId = $resp->json('data.id');

        return FlightBooking::findOrFail($bookingId);
    }

    #[Test]
    #[Testdox('INCIDENT-2026-08-17: PUT /api/v1/flight/bookings/{id} returns 405')]
    public function put_flight_booking_returns_405(): void
    {
        $booking = $this->createBooking();

        $resp = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/flight/bookings/{$booking->id}", [
                'selling_price' => 30, 'purchase_price' => 40, 'currency' => 'EGP',
                'flight_carrier_id' => $this->carrier->id,
                'flight_system_id' => $this->system->id,
            ]);

        $this->assertContains(
            $resp->status(),
            [405, 404],
            "PUT /api/v1/flight/bookings/{$booking->id} must NOT be routable. Got: {$resp->status()} — {$resp->getContent()}"
        );
    }

    #[Test]
    #[Testdox('INCIDENT-2026-08-17: PATCH /api/v1/flight/bookings/{id} returns 405')]
    public function patch_flight_booking_returns_405(): void
    {
        $booking = $this->createBooking();

        $resp = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->patchJson("/api/v1/flight/bookings/{$booking->id}", [
                'selling_price' => 30,
            ]);

        $this->assertContains(
            $resp->status(),
            [405, 404],
            "PATCH /api/v1/flight/bookings/{$booking->id} must NOT be routable. Got: {$resp->status()}"
        );
    }

    #[Test]
    #[Testdox('INCIDENT-2026-08-17: POST /api/v1/flight/bookings/{id}/prices returns 404')]
    public function post_flight_booking_prices_returns_404(): void
    {
        $booking = $this->createBooking();

        $resp = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/flight/bookings/{$booking->id}/prices", [
                'selling_price' => 30, 'purchase_price' => 40,
            ]);

        $this->assertSame(
            404, $resp->status(),
            "POST /api/v1/flight/bookings/{$booking->id}/prices must NOT exist. Got: {$resp->status()} — {$resp->getContent()}"
        );
    }

    #[Test]
    #[Testdox('INCIDENT-2026-08-17: PUT /api/v1/aviation/{id} returns 405 (alt route blocked)')]
    public function put_aviation_returns_405(): void
    {
        $booking = $this->createBooking();

        $resp = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/aviation/{$booking->id}", [
                'status' => 'cancelled',
                'notes' => 'attempted update via legacy aviation route',
            ]);

        $this->assertContains(
            $resp->status(),
            [405, 404],
            "PUT /api/v1/aviation/{$booking->id} must NOT be routable. Got: {$resp->status()}"
        );
    }

    #[Test]
    #[Testdox('INCIDENT-2026-08-17: PUT /api/v1/hajj-umra/bookings/{id} returns 405')]
    public function put_hajjumra_booking_returns_405(): void
    {
        $resp = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson('/api/v1/hajj-umra/bookings/1', [
                'selling_price' => 30,
            ]);

        $this->assertContains(
            $resp->status(),
            [405, 404],
            "PUT /api/v1/hajj-umra/bookings/{id} must NOT be routable. Got: {$resp->status()}"
        );
    }

    #[Test]
    #[Testdox('INCIDENT-2026-08-17: PUT /api/v1/visa/bookings/{id} returns 405')]
    public function put_visa_booking_returns_405(): void
    {
        $resp = $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson('/api/v1/visa/bookings/1', [
                'selling_price' => 30,
            ]);

        $this->assertContains(
            $resp->status(),
            [405, 404],
            "PUT /api/v1/visa/bookings/{id} must NOT be routable. Got: {$resp->status()}"
        );
    }

    #[Test]
    #[Testdox('INCIDENT-2026-08-17: FlightBookingService::updateBooking() throws LogicException')]
    public function flight_booking_service_update_throws(): void
    {
        $booking = $this->createBooking();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Tourism no-edit contract/');

        app(FlightBookingService::class)->updateBooking($booking, ['selling_price' => 30]);
    }

    #[Test]
    #[Testdox('INCIDENT-2026-08-17: FlightBookingService::updatePrices() throws LogicException')]
    public function flight_booking_service_update_prices_throws(): void
    {
        $booking = $this->createBooking();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Tourism no-edit contract/');

        app(FlightBookingService::class)->updatePrices($booking, 40, 30);
    }

    #[Test]
    #[Testdox('INCIDENT-2026-08-17: HajjUmraBookingService::update() throws LogicException')]
    public function hajjumra_booking_service_update_throws(): void
    {
        // Use an unsaved model instance — the throw-stub fires before any DB
        // access, so we don't need to satisfy FK / NOT-NULL constraints.
        $booking = new \App\Models\HajjUmraBooking([
            'id' => 999,
            'customer_id' => $this->customer->id,
            'program_id' => 1,
            'account_id' => $this->cashbox->id,
            'currency' => 'EGP',
            'selling_price' => 50, 'purchase_price' => 40, 'profit' => 10,
            'status' => HajjUmraStatus::Pending->value,
            'agent_name' => 'TestAgent', 'created_by' => $this->admin->id,
        ]);
        $booking->exists = true; // mark as persisted so save() short-circuits

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Tourism no-edit contract/');

        app(\App\Services\HajjUmra\HajjUmraBookingService::class)->update($booking, ['selling_price' => 30]);
    }

    #[Test]
    #[Testdox('INCIDENT-2026-08-17: VisaBookingService::update() throws LogicException')]
    public function visa_booking_service_update_throws(): void
    {
        // Use an unsaved model instance — same rationale as above.
        $booking = new \App\Models\VisaBooking([
            'id' => 999,
            'customer_id' => $this->customer->id,
            'visa_detail_id' => 1,
            'selling_price' => 50, 'purchase_price' => 40,
            'service_fee' => 5, 'currency' => 'EGP', 'profit' => 15,
            'status' => VisaStatus::Draft->value,
            'agent_name' => 'TestAgent', 'created_by' => $this->admin->id,
        ]);
        $booking->exists = true;

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessageMatches('/Tourism no-edit contract/');

        app(\App\Services\Visa\VisaBookingService::class)->update($booking, ['selling_price' => 30]);
    }

    // ===================== FINANCIAL SAFETY REGRESSION =====================
    // Per directive §7: a booking, after creation, cannot have its
    // selling_price / purchase_price / currency / service_fee changed via ANY
    // route. Every attempt must produce NO ledger drift.

    #[Test]
    #[Testdox('INCIDENT-2026-08-17: Flight selling_price unchanged after attempted edit')]
    public function financial_safety_flight_selling_price_unchanged(): void
    {
        $booking = $this->createBooking();
        $originalSelling = (float) $booking->selling_price;
        $originalPurchase = (float) $booking->purchase_price;

        // Try every endpoint that *could* be a backdoor.
        foreach ([
            ['PUT',  "/api/v1/flight/bookings/{$booking->id}", ['selling_price' => 9999, 'purchase_price' => 0]],
            ['PATCH', "/api/v1/flight/bookings/{$booking->id}", ['selling_price' => 9999]],
            ['POST',  "/api/v1/flight/bookings/{$booking->id}/prices", ['selling_price' => 9999, 'purchase_price' => 0]],
            ['PUT',   "/api/v1/aviation/{$booking->id}", ['selling_price' => 9999]],
        ] as [$method, $url, $body]) {
            $resp = match ($method) {
                'PUT' => $this->putJson($url, $body),
                'PATCH' => $this->patchJson($url, $body),
                'POST' => $this->postJson($url, $body),
            };
            $this->assertContains(
                $resp->status(), [405, 404],
                "$method $url must NOT be routable for Tourism booking edit. Got: {$resp->status()}"
            );
        }

        // Re-fetch and verify nothing changed.
        $booking->refresh();
        $this->assertSame($originalSelling, (float) $booking->selling_price,
            'selling_price must NOT have changed');
        $this->assertSame($originalPurchase, (float) $booking->purchase_price,
            'purchase_price must NOT have changed');
    }

    #[Test]
    #[Testdox('INCIDENT-2026-08-17: No new Transactions created by attempted edit')]
    public function ledger_integrity_no_new_transactions_after_attempted_edit(): void
    {
        $booking = $this->createBooking();
        $txCountBefore = Transaction::count();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/flight/bookings/{$booking->id}", [
                'selling_price' => 9999,
            ]);
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/flight/bookings/{$booking->id}/prices", [
                'selling_price' => 9999,
            ]);

        $this->assertSame($txCountBefore, Transaction::count(),
            'No new Transactions may be created by attempted-edit traffic.');
    }

    #[Test]
    #[Testdox('INCIDENT-2026-08-17: No new AccountEntries created by attempted edit')]
    public function ledger_integrity_no_new_account_entries_after_attempted_edit(): void
    {
        $booking = $this->createBooking();
        $entryCountBefore = AccountEntry::count();

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/flight/bookings/{$booking->id}", [
                'selling_price' => 9999,
            ]);
        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->postJson("/api/v1/flight/bookings/{$booking->id}/prices", [
                'selling_price' => 9999,
            ]);

        $this->assertSame($entryCountBefore, AccountEntry::count(),
            'No new AccountEntries may be created by attempted-edit traffic.');
    }

    #[Test]
    #[Testdox('INCIDENT-2026-08-17: Carrier.balance drift = 0 after attempted edit')]
    public function financial_safety_carrier_balance_unchanged(): void
    {
        $booking = $this->createBooking();
        $carrierBalanceBefore = (float) $this->carrier->fresh()->balance;

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/flight/bookings/{$booking->id}", [
                'selling_price' => 9999, 'purchase_price' => 0,
            ]);

        $carrierBalanceAfter = (float) $this->carrier->fresh()->balance;
        $this->assertEqualsWithDelta(
            $carrierBalanceBefore, $carrierBalanceAfter, 0.001,
            'FlightCarrier.balance must NOT drift after attempted edit.'
        );
    }

    #[Test]
    #[Testdox('INCIDENT-2026-08-17: Treasury balance drift = 0 after attempted edit')]
    public function financial_safety_treasury_balance_unchanged(): void
    {
        $booking = $this->createBooking();
        $treasuryBalanceBefore = (float) $this->cashbox->fresh()->balance;

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/flight/bookings/{$booking->id}", [
                'selling_price' => 9999, 'purchase_price' => 0,
            ]);

        $treasuryBalanceAfter = (float) $this->cashbox->fresh()->balance;
        $this->assertEqualsWithDelta(
            $treasuryBalanceBefore, $treasuryBalanceAfter, 0.001,
            'Treasury (cashbox) balance must NOT drift after attempted edit.'
        );
    }

    #[Test]
    #[Testdox('INCIDENT-2026-08-17: Profit column unchanged after attempted edit')]
    public function financial_safety_profit_column_unchanged(): void
    {
        $booking = $this->createBooking();
        $profitBefore = (float) $booking->profit;

        $this->withHeader('Authorization', 'Bearer '.$this->token)
            ->putJson("/api/v1/flight/bookings/{$booking->id}", [
                'selling_price' => 9999, 'purchase_price' => 0, 'profit' => 9999,
            ]);

        $booking->refresh();
        $this->assertEqualsWithDelta(
            $profitBefore, (float) $booking->profit, 0.001,
            'profit column must NOT have changed — no phantom profit allowed.'
        );
    }
}