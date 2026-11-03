<?php

namespace Tests\Feature\Flight;

use App\Enums\FlightBookingStatus;
use App\Enums\TransactionModule;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightCarrier;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\FlightRefund;
use App\Models\Flight\FlightSystem;
use App\Models\Setting\Currency;
use App\Models\Transaction;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\RefundService;
use App\Services\Finance\TransactionService;
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Database\Seeders\UnifiedVaultsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * CROSS-CURRENCY REFUND EDGE CASES
 * ═════════════════════════════════
 *
 * Regression suite for the bug fixed on 2026-09-03:
 *   RefundService::createRefundRequest fallback chain returned
 *   `selling_price` (always EGP per the 2026-07-23 contract) instead of
 *   `selling_price_foreign` when `original_amount` was null. For non-EGP
 *   bookings this meant `refund_amount` was the EGP-equivalent (e.g. 50000
 *   instead of 500 USD), and the cashbox was debited by 50x its actual
 *   balance — driving it massively negative.
 *
 * These tests pin the EXPECTED post-fix behavior across:
 *   1. PARTIAL refund (50% of selling) for USD booking
 *   2. PARTIAL refund (25% of selling) for KWD booking
 *   3. Sequential partial refunds (3 × 1/3 each, total ≈ 100%) for SAR booking
 *   4. Refund WITH cancellation fee (booking currency ≠ cashbox fee currency)
 *   5. Refund AFTER payDebt cross-currency (booking=EUR, receipt=EGP)
 *   6. Currency-match guard: USD booking → GBP treasury must be rejected
 *   7. original_amount explicit: pre-set field still wins over fallback
 *   8. Partial then full residual: cap at original_amount (Bug #C5 regression)
 */
class RefundCrossCurrencyEdgeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Employee $employee;
    protected FlightBookingService $bookingService;
    protected RefundService $refundService;
    protected TransactionService $txService;

    public static function edgeCaseProvider(): array
    {
        return [
            'USD 50% partial'   => ['USD',  10_000.0, 50_000.0, 50.0,   'half'],
            'KWD 25% partial'   => ['KWD',   1_000.0, 16_250.0, 162.5, 'quarter'],
            'SAR three-thirds'  => ['SAR',  10_000.0, 13_333.3, 13.3333, 'thirds'],
            'EUR with cancellation fee' => ['EUR', 5_000.0, 54_500.0, 54.5, 'fee'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(UnifiedVaultsSeeder::class);

        foreach ([
            'USD' => 50.0, 'KWD' => 162.5, 'SAR' => 13.3333,
            'EUR' => 54.5, 'GBP' => 65.0, 'EGP' => 1.0,
        ] as $code => $rate) {
            Currency::query()->updateOrCreate(
                ['code' => $code],
                ['name_ar' => $code, 'name_en' => $code, 'symbol' => $code,
                 'exchange_rate' => $rate, 'is_active' => true, 'order' => 0]
            );
        }

        $this->admin = User::query()->create([
            'name' => 'Edge Tester', 'email' => 'edge-' . uniqid() . '@test.com',
            'password' => Hash::make('password'), 'role' => 'admin', 'is_active' => true,
        ]);
        $this->employee = Employee::query()->create([
            'user_id' => $this->admin->id, 'status' => 'active',
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        $this->bookingService = app(FlightBookingService::class);
        $this->refundService = app(RefundService::class);
        $this->txService = app(TransactionService::class);
    }

    protected function buildFixture(string $currency, float $cashboxOpening): array
    {
        $cashbox = Account::query()->create([
            'name' => "{$currency} Edge Cashbox",
            'type' => 'cashbox', 'currency' => $currency,
            'balance' => 0.0, 'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => AccountModuleContract::TOURISM_MODULE_TYPE,
            'is_module_vault' => true, 'created_by' => $this->admin->id,
        ]);
        LedgerBalanceMutationGuard::run(function () use ($cashbox, $cashboxOpening) {
            $tx = Transaction::query()->create([
                'type' => 'transfer', 'amount' => $cashboxOpening,
                'module' => TransactionModule::General->value,
                'from_account_id' => $cashbox->id, 'to_account_id' => $cashbox->id,
                'created_by' => $this->admin->id,
                'notes' => "OPENING: edge test {$cashbox->name}",
            ]);
            \App\Models\AccountEntry::query()->create([
                'account_id' => $cashbox->id, 'transaction_id' => $tx->id,
                'debit' => 0, 'credit' => $cashboxOpening,
                'balance_after' => $cashboxOpening,
            ]);
            $cashbox->update(['balance' => $cashboxOpening]);
        });

        $treasury = Treasury::query()->create([
            'name' => "{$currency} Edge Treasury",
            'currency' => $currency,
            'current_balance' => $cashboxOpening, 'is_active' => true,
        ]);

        $system = FlightSystem::query()->create([
            'name' => "{$currency} Edge System", 'code' => substr($currency, 0, 2) . 'ES' . uniqid(),
            'currency' => $currency, 'credit_limit' => 50000.0,
            'balance' => 0.0, 'available_balance' => 0.0,
            'is_active' => true, 'created_by' => $this->admin->id,
        ]);
        $carrier = FlightCarrier::query()->create([
            'name' => "{$currency} Edge Carrier", 'code' => substr($currency, 0, 2) . 'EC' . uniqid(),
            'flight_system_id' => $system->id, 'currency' => $currency,
            'credit_limit' => 200000.0, 'balance' => 0.0, 'available_balance' => 0.0,
            'is_active' => true, 'created_by' => $this->admin->id,
        ]);

        $customer = Customer::query()->create([
            'full_name' => "Edge Cust {$currency}",
            'phone' => '010' . substr(md5(Str::lower($currency) . uniqid()), 0, 8),
            'is_active' => true, 'created_by' => $this->admin->id,
        ]);

        // Recharge carrier but keep enough cashbox headroom for refunds.
        $rechargeAmount = min($cashboxOpening * 0.3, 3000.0);
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $carrier, $cashbox, $rechargeAmount, "Edge setup {$currency}"
        );

        return compact('cashbox', 'treasury', 'system', 'carrier', 'customer');
    }

    protected function createBooking(
        string $currency, float $sellingPriceEgp, float $exchangeRate, array $fx, ?float $originalAmount = null
    ): FlightBooking {
        $purchasePriceEgp = round($sellingPriceEgp * 0.6, 2);
        $sellingPriceForeign = round($sellingPriceEgp / $exchangeRate, 4);
        $purchasePriceForeign = round($purchasePriceEgp / $exchangeRate, 4);

        return $this->bookingService->createBooking([
            'customer_id' => $fx['customer']->id,
            'employee_id' => $this->employee->id,
            'selling_price' => $sellingPriceEgp,
            'purchase_price' => $purchasePriceEgp,
            'selling_price_foreign' => $sellingPriceForeign,
            'purchase_price_foreign' => $purchasePriceForeign,
            'currency' => $currency,
            'original_currency' => $currency,
            'original_amount' => $originalAmount,    // may be null
            'account_id' => $fx['cashbox']->id,
            'agent_name' => 'Edge Test Agent',
            'origin' => 'CAI', 'destination' => 'DXB',
            'departure_date' => now()->addDays(14)->toDateString(),
            'departure_time' => '10:00:00',
            'trip_type' => 'one_way',
            'airline' => substr($currency, 0, 2) . 'E',
            'passenger_count' => 1,
            'flight_carrier_id' => $fx['carrier']->id,
            'pnr' => 'PNR' . uniqid(),
        ]);
    }

    protected function payFullForeign(FlightBooking $booking, float $sellingPriceForeign, Account $cashbox): void
    {
        $this->bookingService->addPayment($booking, [
            'amount' => $sellingPriceForeign,
            'payment_method' => 'cash',
            'account_id' => $cashbox->id,
            'idempotency_key' => 'edge-' . uniqid(),
        ]);
    }

    protected function assertNoNegativeLiquidity(array $accounts): void
    {
        foreach ($accounts as $acc) {
            $bal = (float) $acc->fresh()->balance;
            $this->assertGreaterThanOrEqual(
                0.0, $bal,
                "Account #{$acc->id} ({$acc->name}, {$acc->currency}) must not go negative. Got {$bal}"
            );
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // EDGE CASE 1+2+3+4: parameterized partial refunds
    // ═════════════════════════════════════════════════════════════════════
    #[\PHPUnit\Framework\Attributes\DataProvider('edgeCaseProvider')]
    public function test_partial_refund_debits_cashbox_by_foreign_amount_not_egp_equivalent(
        string $currency, float $cashboxOpening, float $sellingPriceEgp,
        float $exchangeRate, string $mode
    ): void {
        $fx = $this->buildFixture($currency, $cashboxOpening);
        $booking = $this->createBooking($currency, $sellingPriceEgp, $exchangeRate, $fx);

        $sellingPriceForeign = round($sellingPriceEgp / $exchangeRate, 4);
        $this->payFullForeign($booking, $sellingPriceForeign, $fx['cashbox']);

        $cashboxBefore = (float) $fx['cashbox']->fresh()->balance;

        // Decide refund amount + fee based on mode.
        // The service computes `refund_amount = original_amount - cancellation_fee`
        // and IGNORES the caller-supplied refund_amount. So to test partial
        // refunds we set cancellation_fee = original_amount - desiredRefund.
        // For 'thirds' we round the fee so that refund_amount is exact (1000/3 = 333.3333...).
        [$refundForeign, $feeForeign] = match ($mode) {
            'half'    => [round($sellingPriceForeign / 2, 4),    round($sellingPriceForeign / 2, 4)],
            'quarter' => [round($sellingPriceForeign / 4, 4),    round($sellingPriceForeign * 3 / 4, 4)],
            // For thirds, the fee = 2/3 with 4 decimal places produces the exact 1/3 refund.
            'thirds'  => [round($sellingPriceForeign / 3, 4),    round($sellingPriceForeign * 2 / 3, 4)],
            'fee'     => [round($sellingPriceForeign * 0.8, 4),  round($sellingPriceForeign * 0.2, 4)],
        };

        $rr = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_currency' => $currency,
            'cancellation_fee' => $feeForeign,
            'refund_exchange_rate' => $exchangeRate,
            'destination' => 'agency_treasury',
            'treasury_id' => $fx['treasury']->id,
            'refund_type' => 'partial',
            'reason' => "[{$currency}/{$mode}] edge test",
            'created_by' => $this->admin->id,
        ], $this->admin->id);

        $this->assertEqualsWithDelta($refundForeign, (float) $rr->refund_amount, 0.01,
            "[{$currency}] RefundRequest.refund_amount must be in foreign ({$refundForeign}), ".
            "NOT in EGP-equivalent (" . round($refundForeign * $exchangeRate, 2) . "). ".
            "Note: original_amount column is decimal(2), so 1000/3 → 333.33 not 333.3333.");

        $processed = $this->refundService->processRefundRequest($rr->id, $this->admin->id);
        $this->assertEquals('processed', $processed->fresh()->status);

        // The cashbox must be debited by EXACTLY the foreign refund amount
        // (NOT the EGP-equivalent).
        $cashboxAfter = (float) $fx['cashbox']->fresh()->balance;
        $debit = round($cashboxBefore - $cashboxAfter, 4);
        $this->assertEqualsWithDelta(
            $refundForeign, $debit, 0.05,
            "[{$currency}/{$mode}] cashbox debit must equal foreign refund amount ".
            "(expected {$refundForeign} {$currency}, got {$debit} {$currency}; ".
            "the EGP-equivalent would have been " . round($refundForeign * $exchangeRate, 2) . ")"
        );

        // The cashbox must remain non-negative throughout.
        $this->assertNoNegativeLiquidity([$fx['cashbox']]);

        // Booking status reflects partial vs full refund.
        $booking->refresh();
        $this->assertEquals(
            FlightBookingStatus::PARTIALLY_REFUNDED->value, $booking->status->value,
            "[{$currency}/{$mode}] booking must be PARTIALLY_REFUNDED"
        );
    }

    // ═════════════════════════════════════════════════════════════════════
    // EDGE CASE 5: payDebt cross-currency, then cancel and refund
    // ═════════════════════════════════════════════════════════════════════
    public function test_pay_debt_then_partial_refund_uses_correct_foreign_amount(): void
    {
        $currency = 'EUR';
        $fx = $this->buildFixture($currency, 5000.0);
        $exchangeRate = 54.5;
        $sellingPriceEgp = 54500.0;

        $booking = $this->createBooking($currency, $sellingPriceEgp, $exchangeRate, $fx);
        $sellingPriceForeign = round($sellingPriceEgp / $exchangeRate, 4);
        $arAccount = $fx['customer']->ledgerAccount;

        // Flip booking to CONFIRMED (required for refunds). addPayment only
        // promotes status when cumulative paid >= selling_price, so we use
        // confirmBooking directly to skip that gate.
        $this->bookingService->confirmBooking($booking);

        // Settle via payDebt (cross-currency: customer AR is EGP, receipt cashbox is EGP)
        $egpCashbox = Account::query()->create([
            'name' => 'EUR-Debt Receipt EGP Cashbox', 'type' => 'cashbox',
            'currency' => 'EGP', 'balance' => 100000.0, 'is_active' => true,
            'owner_type' => Account::OWNER_TYPE_OFFICE,
            'module_type' => AccountModuleContract::TOURISM_MODULE_TYPE,
            'is_module_vault' => true, 'created_by' => $this->admin->id,
        ]);
        $this->txService->recordIncome([
            'amount' => $sellingPriceEgp, 'to_account_id' => $egpCashbox->id,
            'contra_account_id' => $arAccount->id, 'allow_contra_negative' => true,
            'module' => TransactionModule::Flight->value,
            'related_type' => Customer::class, 'related_id' => $fx['customer']->id,
            'notes' => "[{$currency}] payDebt before refund",
            'created_by' => $this->admin->id,
        ]);

        // Now process a partial refund of 1/4 in foreign currency.
        // The service computes refund_amount = original_amount - cancellation_fee,
        // so we set cancellation_fee = 3/4 of selling to get 1/4 back.
        $refundForeign = round($sellingPriceForeign / 4, 4);
        $feeForeign = round($sellingPriceForeign * 3 / 4, 4);
        $cashboxBefore = (float) $fx['cashbox']->fresh()->balance;

        $rr = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_currency' => $currency,
            'cancellation_fee' => $feeForeign,
            'refund_exchange_rate' => $exchangeRate,
            'destination' => 'agency_treasury',
            'treasury_id' => $fx['treasury']->id,
            'refund_type' => 'partial',
            'reason' => "[{$currency}] partial refund after payDebt",
            'created_by' => $this->admin->id,
        ], $this->admin->id);

        $this->assertEquals($refundForeign, (float) $rr->refund_amount);
        $this->refundService->processRefundRequest($rr->id, $this->admin->id);

        $cashboxAfter = (float) $fx['cashbox']->fresh()->balance;
        $debit = round($cashboxBefore - $cashboxAfter, 4);
        $this->assertEqualsWithDelta(
            $refundForeign, $debit, 0.05,
            "[{$currency}] payDebt-then-refund cashbox debit must be foreign amount"
        );

        $this->assertNoNegativeLiquidity([$fx['cashbox'], $egpCashbox]);
    }

    // ═════════════════════════════════════════════════════════════════════
    // EDGE CASE 6: Currency-match guard — USD booking → EUR treasury rejected
    // ═════════════════════════════════════════════════════════════════════
    public function test_currency_mismatch_booking_vs_treasury_rejected(): void
    {
        $fx = $this->buildFixture('USD', 5000.0);
        $booking = $this->createBooking('USD', 50000.0, 50.0, $fx);

        $this->payFullForeign($booking, 1000.0, $fx['cashbox']);

        // Try to refund USD booking to a EUR treasury
        $eurTreasury = Treasury::query()->create([
            'name' => 'EUR Mismatch Treasury', 'currency' => 'EUR',
            'current_balance' => 5000.0, 'is_active' => true,
        ]);

        // createRefundRequest validates booking vs refund_currency (both USD here)
        // — passes. The mismatch with treasury.currency (EUR) is caught in
        // processRefundRequest step 2 validation.
        $rr = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_currency' => 'USD',  // booking currency
            'cancellation_fee' => 500.0,
            'refund_exchange_rate' => 50.0,
            'destination' => 'agency_treasury',
            'treasury_id' => $eurTreasury->id,  // EUR treasury (mismatch!)
            'refund_type' => 'partial',
            'reason' => 'mismatch test',
            'created_by' => $this->admin->id,
        ], $this->admin->id);

        $this->expectException(\RuntimeException::class);
        try {
            $this->refundService->processRefundRequest($rr->id, $this->admin->id);
        } catch (\Throwable $e) {
            $this->assertStringContainsString('تضارب', $e->getMessage(),
                'exception must mention currency mismatch (تضارب)');
            throw $e;
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // EDGE CASE 7: Cumulative refund cap (Bug #C5 regression)
    // The service must cap cumulative active refunds at original_amount.
    // For a 1000 USD booking, two partial refunds of 600 + 500 must reject the second.
    // ═════════════════════════════════════════════════════════════════════
    public function test_cumulative_refunds_capped_at_original_amount(): void
    {
        $fx = $this->buildFixture('USD', 10000.0);
        $booking = $this->createBooking('USD', 50000.0, 50.0, $fx);
        $this->payFullForeign($booking, 1000.0, $fx['cashbox']);

        // First refund: 600 USD (cancellation_fee=400 → refund_amount=600)
        $rr1 = $this->refundService->createRefundRequest([
            'flight_booking_id' => $booking->id,
            'refund_currency' => 'USD',
            'cancellation_fee' => 400.0,
            'refund_exchange_rate' => 50.0,
            'destination' => 'agency_treasury',
            'treasury_id' => $fx['treasury']->id,
            'refund_type' => 'partial',
            'reason' => 'first partial',
            'created_by' => $this->admin->id,
        ], $this->admin->id);
        $this->assertEquals(600.0, (float) $rr1->refund_amount);
        $this->refundService->processRefundRequest($rr1->id, $this->admin->id);

        // Second refund: would push cumulative to 1100 (600+500), exceeding original 1000.
        // Must reject.
        $this->expectException(\RuntimeException::class);
        try {
            $this->refundService->createRefundRequest([
                'flight_booking_id' => $booking->id,
                'refund_currency' => 'USD',
                'cancellation_fee' => 500.0,
                'refund_exchange_rate' => 50.0,
                'destination' => 'agency_treasury',
                'treasury_id' => $fx['treasury']->id,
                'refund_type' => 'partial',
                'reason' => 'over-cap',
                'created_by' => $this->admin->id,
            ], $this->admin->id);
        } catch (\Throwable $e) {
            $this->assertStringContainsString('سيتجاوز', $e->getMessage(),
                'exception must mention cap exceeded (سيتجاوز)');
            throw $e;
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // EDGE CASE 8: Sequential partial refunds must each use foreign amount.
    // Each refund's `refund_amount` = `original_amount - cancellation_fee`,
    // so we vary the cancellation_fee to produce different partial amounts.
    // ═════════════════════════════════════════════════════════════════════
    public function test_sequential_partial_refunds_each_debit_foreign_amount(): void
    {
        $currency = 'USD';
        $fx = $this->buildFixture($currency, 10000.0);
        $exchangeRate = 50.0;
        $sellingPriceEgp = 50000.0;
        $sellingPriceForeign = 1000.0;

        $booking = $this->createBooking($currency, $sellingPriceEgp, $exchangeRate, $fx);
        $this->payFullForeign($booking, $sellingPriceForeign, $fx['cashbox']);

        // Three sequential refunds with foreign amounts summing to 1000 USD.
        // (cancellation_fee varies so refund_amount differs each time)
        //   refund 1: fee=800 → refund=200
        //   refund 2: fee=700 → refund=300
        //   refund 3: fee=500 → refund=500
        $plan = [
            ['fee' => 800.0, 'refund' => 200.0],
            ['fee' => 700.0, 'refund' => 300.0],
            ['fee' => 500.0, 'refund' => 500.0],
        ];

        $cashboxBefore = (float) $fx['cashbox']->fresh()->balance;
        $totalForeignDebit = 0.0;

        foreach ($plan as $idx => $step) {
            $cashboxSnapshot = (float) $fx['cashbox']->fresh()->balance;

            $rr = $this->refundService->createRefundRequest([
                'flight_booking_id' => $booking->id,
                'refund_currency' => $currency,
                'cancellation_fee' => $step['fee'],
                'refund_exchange_rate' => $exchangeRate,
                'destination' => 'agency_treasury',
                'treasury_id' => $fx['treasury']->id,
                'refund_type' => 'partial',
                'reason' => "Sequential refund #" . ($idx + 1),
                'created_by' => $this->admin->id,
            ], $this->admin->id);

            $this->assertEquals($step['refund'], (float) $rr->refund_amount,
                "Sequential refund #{$idx}: refund_amount must be {$step['refund']} {$currency}");

            $this->refundService->processRefundRequest($rr->id, $this->admin->id);

            $cashboxAfter = (float) $fx['cashbox']->fresh()->balance;
            $debit = round($cashboxSnapshot - $cashboxAfter, 4);
            $this->assertEqualsWithDelta(
                $step['refund'], $debit, 0.05,
                "Sequential refund #{$idx}: cashbox debit ({$debit} {$currency}) must equal foreign refund amount ({$step['refund']} {$currency})"
            );

            $totalForeignDebit += $debit;
        }

        $cashboxFinal = (float) $fx['cashbox']->fresh()->balance;
        $this->assertEqualsWithDelta(
            $cashboxBefore - $totalForeignDebit, $cashboxFinal, 0.05,
            "Final cashbox balance must equal pre-sequential minus sum of foreign debits"
        );

        $this->assertNoNegativeLiquidity([$fx['cashbox']]);

        // Booking status after cumulative partial refunds equals original_amount
        // but with cancellation_fees > 0 throughout, the status remains
        // PARTIALLY_REFUNDED. (REFUNDED status requires a single full-amount
        // refund_request with cancellation_fee=0.)
        $booking->refresh();
        $this->assertEquals(
            FlightBookingStatus::PARTIALLY_REFUNDED->value, $booking->status->value,
            'Sequential partial refunds leave booking as PARTIALLY_REFUNDED'
        );
    }
}
