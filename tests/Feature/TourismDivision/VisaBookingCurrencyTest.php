<?php

namespace Tests\Feature\TourismDivision;

use App\Models\HajjUmra\VisaAgent;
use App\Models\Transaction;
use App\Models\VisaBooking;

class VisaBookingCurrencyTest extends TourismTestCase
{
    public function test_booking_rejects_mismatched_settlement_account_currency(): void
    {
        $customer = $this->makeCustomer();
        $agent = $this->makeVisaAgent();
        $usdAccount = $this->makeAccount('cashbox', 'خزينة دولار', 'tourism', 0, 'USD');

        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload(
            $customer->id,
            $agent->id,
            $usdAccount->id,
            ['currency' => 'EGP'],
        ));

        $response->assertStatus(422)->assertJsonValidationErrors('account_id');
        $this->assertDatabaseCount('visa_bookings', 0);
    }

    public function test_booking_rejects_mismatched_initial_payment_account_currency(): void
    {
        $customer = $this->makeCustomer();
        $agent = $this->makeVisaAgent();
        $usdAccount = $this->makeAccount('cashbox', 'خزينة دولار', 'tourism', 0, 'USD');

        $payload = $this->bookingPayload($customer->id, $agent->id, $this->cashbox->id);
        $payload['initial_payment'] = [
            'amount' => 250,
            'payment_method' => 'cash',
            'account_id' => $usdAccount->id,
        ];

        $response = $this->postJson('/api/v1/visa/bookings', $payload);

        $response->assertStatus(422)->assertJsonValidationErrors('initial_payment.account_id');
        $this->assertDatabaseCount('visa_bookings', 0);
    }

    public function test_booking_accepts_matching_currency_without_case_sensitivity(): void
    {
        $customer = $this->makeCustomer();
        $agent = $this->makeVisaAgent();

        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload(
            $customer->id,
            $agent->id,
            $this->cashbox->id,
            ['currency' => 'egp'],
        ));

        $response->assertCreated()->assertJsonPath('success', true);
    }

    public function test_payment_rejects_account_with_currency_different_from_booking(): void
    {
        $booking = $this->createBooking();
        $usdAccount = $this->makeAccount('cashbox', 'خزينة دولار', 'tourism', 0, 'USD');
        $transactionsBefore = Transaction::query()->count();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 100,
            'payment_method' => 'cash',
            'account_id' => $usdAccount->id,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('account_id');
        $this->assertDatabaseCount('visa_payments', 0);
        $this->assertSame($transactionsBefore, Transaction::query()->count());
    }

    public function test_payment_rejects_currency_override_different_from_booking(): void
    {
        $booking = $this->createBooking();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'currency' => 'USD',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('currency');
        $this->assertDatabaseCount('visa_payments', 0);
    }

    public function test_payment_accepts_matching_account_currency(): void
    {
        $booking = $this->createBooking();

        $response = $this->postJson("/api/v1/visa/bookings/{$booking->id}/payments", [
            'amount' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);

        $response->assertCreated()->assertJsonPath('success', true);
        $this->assertDatabaseHas('visa_payments', [
            'visa_booking_id' => $booking->id,
            'account_id' => $this->cashbox->id,
        ]);
    }

    protected function createBooking(): VisaBooking
    {
        $customer = $this->makeCustomer();
        $agent = $this->makeVisaAgent();

        $response = $this->postJson('/api/v1/visa/bookings', $this->bookingPayload(
            $customer->id,
            $agent->id,
            $this->cashbox->id,
        ));

        $response->assertCreated();

        return VisaBooking::findOrFail($response->json('data.id'));
    }

    protected function bookingPayload(int $customerId, int $agentId, int $accountId, array $overrides = []): array
    {
        return array_replace_recursive([
            'customer_id' => $customerId,
            'visa_details' => [
                'visa_type' => 'tourist',
                'country' => 'السعودية',
                'visa_agent_id' => $agentId,
            ],
            'purchase_price' => 1000,
            'selling_price' => 1500,
            'account_id' => $accountId,
        ], $overrides);
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
}
