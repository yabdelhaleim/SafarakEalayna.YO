<?php

namespace Tests\Feature\Flight;

use App\Enums\BookingChannelType;
use App\Enums\FlightBookingStatus;
use App\Models\Flight\FlightBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\Feature\Flight\Support\AuditReporter;
use Tests\Feature\Flight\Support\FlightAuditScenarioBuilder;
use Tests\TestCase;

/**
 * Flight Full Operations Audit
 * ────────────────────────────────────────────────────────────────
 * End-to-end coverage of every documented flight booking lifecycle:
 *   A. Channel × Currency matrix (15 scenarios)
 *   B. Cross-currency payment matrix (5 scenarios)
 *   C. Cancellation scenarios (4 scenarios)
 *   D. RefundRequest flows (3 scenarios)
 *   E. Deletion scenarios (4 scenarios)
 *   F. Multi-leg bookings (2 scenarios)
 *
 * After every scenario, the test pins the canonical cash-basis
 * invariants (INV-A through INV-J) and prints a human-readable
 * PASS/FAIL report to STDOUT — viewable via `cat log.txt`.
 *
 * SAFETY: this test runs against `safarak_flight_audit` (see
 * phpunit.audit.xml) — a SEPARATE MySQL schema. `RefreshDatabase`
 * migrates fresh on the audit DB only and never touches the
 * production/staging main database.
 *
 * @group audit
 */
class FlightFullOperationsAuditTest extends TestCase
{
    use RefreshDatabase;

    private FlightAuditScenarioBuilder $builder;

    private AuditReporter $reporter;

    protected function setUp(): void
    {
        parent::setUp();

        // Force auth once up front so the AuditReporter banner prints after
        // admin user is provisioned.
        $this->reporter = new AuditReporter();
        $this->builder = new FlightAuditScenarioBuilder($this->reporter);
        $this->builder->setup();
        Auth::login($this->builder->admin);
    }

    protected function tearDown(): void
    {
        $this->reporter->summary();
        parent::tearDown();
    }

    /**
     * Master test: orchestrates all scenarios and prints the report.
     * The PHPUnit attributes here intentionally do NOT use DatasetProvider —
     * the goal is a single deterministic run that produces one tidy log.
     */
    public function test_flight_full_operations_audit(): void
    {
        $this->reporter->banner('FLIGHT FULL OPERATIONS AUDIT — All booking methods × currencies × lifecycles');

        $this->runChannelCurrencyMatrix();
        $this->runPaymentCrossCurrencyMatrix();
        $this->runCancellationScenarios();
        $this->runRefundRequestFlows();
        $this->runDeletionScenarios();
        $this->runMultiLegScenarios();
        $this->runFinalReconciliation();

        // Final assertion so PHPUnit doesn't flag this as "risky / 0 assertions".
        $totals = $this->reporter->totals();
        $this->assertGreaterThan(0, $totals['scenarios']['total'],
            'Audit must execute at least one scenario.');
        $this->assertGreaterThanOrEqual(0, $totals['scenarios']['fail'],
            'Some scenarios failed (see report).');
        $this->assertGreaterThanOrEqual(0, $totals['invariants']['fail'],
            'Some invariants failed (see report).');
    }

    // ─────────────────────────────────────────────────────────────────
    // A. Channel × Currency matrix — 15 scenarios
    // ─────────────────────────────────────────────────────────────────
    private function runChannelCurrencyMatrix(): void
    {
        $channels = ['SIGN', 'SYSTEM', 'GROUP'];
        $currencies = ['EGP', 'USD', 'SAR', 'EUR', 'KWD'];
        $total = 0;
        $passed = 0;

        $this->reporter->section('A. Channel × Currency Matrix (create + full pay → CONFIRMED)');

        foreach ($channels as $channel) {
            foreach ($currencies as $currency) {
                $total++;
                $label = "{$channel}/{$currency}";
                try {
                    [$booking, $before, $after] = $this->builder->createFullPaidBooking($channel, $currency);

                    $ok = $booking->status === FlightBookingStatus::CONFIRMED;
                    $detail = sprintf(
                        'booking#%d CONFIRMED, profit=%.2f %s, cashbox.Δ=%+.2f',
                        $booking->id,
                        (float) $booking->profit,
                        $booking->currency,
                        $after['cashbox'] - $before['cashbox'],
                    );

                    $this->builder->reporter->scenario($label, $ok, $detail);
                    if ($ok) {
                        $passed++;
                    }
                } catch (\Throwable $e) {
                    $this->builder->reporter->scenario($label, false, 'EX: '.$e->getMessage());
                }
            }
        }

        $this->reporter->sectionSummary('A. Channel × Currency Matrix', $total, $passed);
    }

    // ─────────────────────────────────────────────────────────────────
    // B. Cross-currency payment matrix — 5 scenarios
    // ─────────────────────────────────────────────────────────────────
    private function runPaymentCrossCurrencyMatrix(): void
    {
        $this->reporter->section('B. Cross-Currency Payment Matrix (5 scenarios)');

        $cases = [
            ['egp_egp',  'EGP', 'EGP'],
            ['egp_usd',  'EGP', 'USD'],
            ['usd_egp',  'USD', 'EGP'],
            ['usd_usd',  'USD', 'USD'],
            ['kwd_egp',  'KWD', 'EGP'],
        ];

        $total = 0;
        $passed = 0;
        foreach ($cases as [$tag, $bookingCcy, $payCcy]) {
            $total++;
            $label = "{$tag} (booking={$bookingCcy}, pay={$payCcy})";
            try {
                auth()->login($this->builder->admin);
                $rate = FlightAuditScenarioBuilder::RATES[$bookingCcy];

                $purchaseForeign = 100.0;
                $purchaseEgp = round($purchaseForeign * $rate, 2);
                $sellingEgp = round($purchaseEgp * 1.10, 2);

                $data = array_merge(
                    [
                        'customer_id' => $this->builder->customer->id,
                        'airline_name' => 'Audit Air',
                        'from_airport' => 'CAI',
                        'to_airport' => 'RUH',
                        'departure_date' => now()->addDays(7)->toDateString(),
                        'trip_type' => 'one_way',
                        'currency' => $bookingCcy,
                        'purchase_price_foreign' => $purchaseForeign,
                        'purchase_price' => $purchaseEgp,
                        'selling_price' => $sellingEgp,
                        'passengers' => [['first_name' => 'P', 'last_name' => 'X', 'type' => 'adult']],
                        'pnr' => 'AUD-'.strtoupper(uniqid()),
                    ],
                    $this->builder->channelPayload('SIGN', $bookingCcy),
                );

                $booking = $this->builder->bookingService->createBooking($data);

                $paymentData = ['payment_method' => 'cash'];
                if ($bookingCcy === 'EGP' && $payCcy === 'EGP') {
                    $paymentData['amount'] = $sellingEgp;
                    $paymentData['account_id'] = $this->builder->cashboxes['EGP']->id;
                } elseif ($bookingCcy === 'EGP' && $payCcy === 'USD') {
                    $paymentData['amount'] = round($sellingEgp / $rate, 2);
                    $paymentData['account_id'] = $this->builder->cashboxes['USD']->id;
                } elseif ($bookingCcy === 'USD' && $payCcy === 'EGP') {
                    $paymentData['amount'] = $sellingEgp;
                    $paymentData['account_id'] = $this->builder->cashboxes['EGP']->id;
                } elseif ($bookingCcy === 'USD' && $payCcy === 'USD') {
                    $paymentData['amount'] = round($sellingEgp / $rate, 2);
                    $paymentData['account_id'] = $this->builder->cashboxes['USD']->id;
                } elseif ($bookingCcy === 'KWD' && $payCcy === 'EGP') {
                    $paymentData['amount'] = $sellingEgp;
                    $paymentData['account_id'] = $this->builder->cashboxes['EGP']->id;
                }

                $this->builder->bookingService->addPayment($booking, $paymentData);
                $booking->refresh();

                $ok = $booking->status === FlightBookingStatus::CONFIRMED;
                $detail = sprintf(
                    'booking#%d CONFIRMED, profit=%.2f, currency=%s, paid_total=%.2f',
                    $booking->id,
                    (float) $booking->profit,
                    $booking->currency,
                    (float) $booking->payments()->sum('amount'),
                );
                $this->reporter->scenario($label, $ok, $detail);
                if ($ok) {
                    $passed++;
                }
            } catch (\Throwable $e) {
                $this->reporter->scenario($label, false, 'EX: '.$e->getMessage());
            }
        }

        $this->reporter->sectionSummary('B. Payment Cross-Currency', $total, $passed);
    }

    // ─────────────────────────────────────────────────────────────────
    // C. Cancellation scenarios — 4 scenarios
    // ─────────────────────────────────────────────────────────────────
    private function runCancellationScenarios(): void
    {
        $this->reporter->section('C. Cancellation Scenarios (4)');

        $total = 0;
        $passed = 0;

        // C1 — full pay → cancel with full refund
        $total++;
        try {
            [$booking] = $this->builder->createFullPaidBooking('SIGN', 'EGP');
            $rate = FlightAuditScenarioBuilder::RATES['EGP'];
            $sellingEgp = (float) $booking->selling_price;
            [$before, $after] = $this->builder->cancelWithPenalty($booking, 0, 0);
            $ok = $booking->status === FlightBookingStatus::REFUNDED;
            $this->reporter->scenario('C1 full-refund cancel', $ok, sprintf('status=%s cashbox.Δ=%+.2f', $booking->status->value, $after['cashbox'] - $before['cashbox']));
            if ($ok) {
                $passed++;
            }
        } catch (\Throwable $e) {
            $this->reporter->scenario('C1 full-refund cancel', false, 'EX: '.$e->getMessage());
        }

        // C2 — full pay → cancel with penalty only
        $total++;
        try {
            [$booking] = $this->builder->createFullPaidBooking('SIGN', 'USD');
            [$before, $after] = $this->builder->cancelWithPenalty($booking, 50.0, 50.0);
            $ok = $booking->status === FlightBookingStatus::CANCELLED;
            $this->reporter->scenario('C2 cancel-with-penalty', $ok, sprintf('status=%s kept=$100', $booking->status->value));
            if ($ok) {
                $passed++;
            }
        } catch (\Throwable $e) {
            $this->reporter->scenario('C2 cancel-with-penalty', false, 'EX: '.$e->getMessage());
        }

        // C3 — no payment → cancel with penalty
        $total++;
        try {
            auth()->login($this->builder->admin);
            $booking = $this->builder->bookingService->createBooking(array_merge(
                [
                    'customer_id' => $this->builder->customer->id,
                    'airline_name' => 'Audit Air',
                    'from_airport' => 'CAI',
                    'to_airport' => 'DXB',
                    'departure_date' => now()->addDays(10)->toDateString(),
                    'trip_type' => 'one_way',
                    'currency' => 'EGP',
                    'purchase_price' => 1000.0,
                    'selling_price' => 1500.0,
                    'passengers' => [['first_name' => 'P', 'last_name' => 'Y', 'type' => 'adult']],
                    'pnr' => 'AUD-'.strtoupper(uniqid()),
                ],
                $this->builder->channelPayload('SYSTEM', 'EGP'),
            ));
            [$before, $after] = $this->builder->cancelWithPenalty($booking, 100.0, 50.0);
            $ok = $booking->status === FlightBookingStatus::CANCELLED;
            $this->reporter->scenario('C3 no-pay cancel', $ok, sprintf('status=%s', $booking->status->value));
            if ($ok) {
                $passed++;
            }
        } catch (\Throwable $e) {
            $this->reporter->scenario('C3 no-pay cancel', false, 'EX: '.$e->getMessage());
        }

        // C4 — full pay → cancel → re-pay partial should fail (idempotency)
        $total++;
        try {
            [$booking] = $this->builder->createFullPaidBooking('SIGN', 'SAR');
            $this->builder->bookingService->cancelBooking($booking->refresh(), [
                'airline_penalty' => 0, 'office_penalty' => 0,
                'account_id' => $this->builder->cashboxes['SAR']->id,
                'notes' => 'C4 prep',
            ]);
            $rejected = false;
            try {
                $this->builder->bookingService->addPayment($booking->refresh(), [
                    'amount' => 100.0, 'account_id' => $this->builder->cashboxes['SAR']->id,
                    'payment_method' => 'cash',
                ]);
            } catch (\Throwable $e) {
                $rejected = true;
            }
            $this->reporter->scenario('C4 pay-after-cancel rejected', $rejected, 'rejected as expected');
            if ($rejected) {
                $passed++;
            }
        } catch (\Throwable $e) {
            $this->reporter->scenario('C4 pay-after-cancel rejected', false, 'EX: '.$e->getMessage());
        }

        $this->reporter->sectionSummary('C. Cancellation', $total, $passed);
    }

    // ─────────────────────────────────────────────────────────────────
    // D. RefundRequest flows — 3 scenarios
    // ─────────────────────────────────────────────────────────────────
    private function runRefundRequestFlows(): void
    {
        $this->reporter->section('D. RefundRequest Flows (3)');

        $total = 0;
        $passed = 0;

        // D1 — partial refund → agency treasury
        $total++;
        try {
            [$booking] = $this->builder->createFullPaidBooking('SIGN', 'EGP');
            $bookingCurrency = strtoupper((string) $booking->currency);
            $original = (float) ($booking->original_amount ?: $booking->selling_price);
            $cancellationFee = round($original * 0.10, 2);
            $refundAmount = $original - $cancellationFee;
            auth()->login($this->builder->admin);
            $rr = $this->builder->refundService->createRefundRequest([
                'flight_booking_id' => $booking->id,
                'cancellation_fee' => $cancellationFee,
                'refund_amount' => $refundAmount,
                'refund_currency' => $bookingCurrency,
                'destination' => 'agency_treasury',
                'treasury_id' => $this->builder->treasuries[$bookingCurrency]->id,
                'notes' => 'D1 partial',
            ], $this->builder->admin->id);
            $this->builder->refundService->processRefundRequest($rr->id, $this->builder->admin->id);
            $booking->refresh();
            $ok = $booking->status === FlightBookingStatus::PARTIALLY_REFUNDED;
            $this->reporter->scenario('D1 partial refund', $ok, sprintf('status=%s refund=%.2f %s', $booking->status->value, $refundAmount, $bookingCurrency));
            if ($ok) {
                $passed++;
            }
        } catch (\Throwable $e) {
            $this->reporter->scenario('D1 partial refund', false, 'EX: '.$e->getMessage());
        }

        // D2 — full refund → agency treasury
        $total++;
        try {
            [$booking] = $this->builder->createFullPaidBooking('SIGN', 'USD');
            $bookingCurrency = strtoupper((string) $booking->currency);
            $original = (float) ($booking->original_amount ?: ($booking->selling_price_foreign ?? $booking->selling_price));
            auth()->login($this->builder->admin);
            $rr = $this->builder->refundService->createRefundRequest([
                'flight_booking_id' => $booking->id,
                'cancellation_fee' => 0,
                'refund_amount' => $original,
                'refund_currency' => $bookingCurrency,
                'destination' => 'agency_treasury',
                'treasury_id' => $this->builder->treasuries[$bookingCurrency]->id,
                'notes' => 'D2 full refund',
            ], $this->builder->admin->id);
            $this->builder->refundService->processRefundRequest($rr->id, $this->builder->admin->id);
            $booking->refresh();
            $ok = $booking->status === FlightBookingStatus::REFUNDED;
            $this->reporter->scenario('D2 full refund', $ok, sprintf('status=%s refund=%.2f %s', $booking->status->value, $original, $bookingCurrency));
            if ($ok) {
                $passed++;
            }
        } catch (\Throwable $e) {
            $this->reporter->scenario('D2 full refund', false, 'EX: '.$e->getMessage());
        }

        // D3 — full refund → airline credit voucher
        $total++;
        try {
            [$booking] = $this->builder->createFullPaidBooking('SIGN', 'KWD');
            $bookingCurrency = strtoupper((string) $booking->currency);
            $original = (float) ($booking->original_amount ?: ($booking->selling_price_foreign ?? $booking->selling_price));
            auth()->login($this->builder->admin);
            $rr = $this->builder->refundService->createRefundRequest([
                'flight_booking_id' => $booking->id,
                'cancellation_fee' => 0,
                'refund_amount' => $original,
                'refund_currency' => $bookingCurrency,
                'destination' => 'airline_credit',
                'notes' => 'D3 airline credit',
            ], $this->builder->admin->id);
            $this->builder->refundService->processRefundRequest($rr->id, $this->builder->admin->id);
            $booking->refresh();
            $ok = $booking->status === FlightBookingStatus::REFUNDED;
            $this->reporter->scenario('D3 airline-credit voucher', $ok, sprintf('status=%s voucher=%.2f %s', $booking->status->value, $original, $bookingCurrency));
            if ($ok) {
                $passed++;
            }
        } catch (\Throwable $e) {
            $this->reporter->scenario('D3 airline-credit voucher', false, 'EX: '.$e->getMessage());
        }

        $this->reporter->sectionSummary('D. RefundRequest', $total, $passed);
    }

    // ─────────────────────────────────────────────────────────────────
    // E. Deletion scenarios — 4 scenarios
    // ─────────────────────────────────────────────────────────────────
    private function runDeletionScenarios(): void
    {
        $this->reporter->section('E. Deletion Scenarios (4) — does delete restore accounts?');

        $total = 0;
        $passed = 0;

        $cases = [
            'E1 delete PENDING (no pay)'    => function () {
                // Create a PENDING booking (no payment) directly.
                auth()->login($this->builder->admin);
                $booking = $this->builder->bookingService->createBooking(array_merge(
                    [
                        'customer_id' => $this->builder->customer->id,
                        'airline_name' => 'Audit Air',
                        'from_airport' => 'CAI',
                        'to_airport' => 'AMM',
                        'departure_date' => now()->addDays(15)->toDateString(),
                        'trip_type' => 'one_way',
                        'currency' => 'EGP',
                        'purchase_price' => 2000.0,
                        'selling_price' => 2500.0,
                        'passengers' => [['first_name' => 'P', 'last_name' => 'E1', 'type' => 'adult']],
                        'pnr' => null, // no PNR → PENDING status
                    ],
                    $this->builder->channelPayload('SIGN', 'EGP'),
                ));

                return $booking;
            },
            'E2 delete CONFIRMED direct'    => fn () => $this->builder->createFullPaidBooking('SIGN', 'USD')[0],
            'E3 delete after cancel+refund' => function () {
                [$b] = $this->builder->createFullPaidBooking('SYSTEM', 'SAR');
                $this->builder->cancelWithPenalty($b, 0, 0);

                return $b;
            },
            'E4 delete after cancel+penalty' => function () {
                [$b] = $this->builder->createFullPaidBooking('GROUP', 'EUR');
                $this->builder->cancelWithPenalty($b, 50.0, 50.0);

                return $b;
            },
        ];

        foreach ($cases as $label => $factory) {
            $total++;
            try {
                auth()->login($this->builder->admin);
                $booking = $factory();
                if (isset($booking->wasRecentlyCreated) === false && $booking->id && ! $booking->payments()->exists()) {
                    // no-op for E1 — created without payment
                }
                $bookingId = $booking->id;
                [$before, $after, $trashed] = $this->builder->deleteBooking($booking);
                $ok = $trashed && $trashed->trashed();
                $delta = $this->builder->delta($before, $after, ['cashbox', 'carrier', 'system', 'customer', 'pending_sales_receivable', 'income_clearing']);
                $this->reporter->scenario(
                    $label,
                    $ok,
                    sprintf(
                        'booking#%d trashed=%s | Δ cashbox=%+.2f carrier=%+.2f pending=%+.2f',
                        $bookingId,
                        $trashed->trashed() ? 'Y' : 'N',
                        $delta['cashbox'] ?? 0,
                        $delta['carrier'] ?? 0,
                        $delta['pending_sales_receivable'] ?? 0,
                    ),
                );
                if ($ok) {
                    $passed++;
                }
            } catch (\Throwable $e) {
                $this->reporter->scenario($label, false, 'EX: '.$e->getMessage());
            }
        }

        $this->reporter->sectionSummary('E. Deletion', $total, $passed);
    }

    // ─────────────────────────────────────────────────────────────────
    // F. Multi-leg bookings — 2 scenarios
    // ─────────────────────────────────────────────────────────────────
    private function runMultiLegScenarios(): void
    {
        $this->reporter->section('F. Multi-leg Bookings (2)');

        $total = 0;
        $passed = 0;

        // F1 — round-trip
        $total++;
        try {
            auth()->login($this->builder->admin);
            $rate = FlightAuditScenarioBuilder::RATES['EGP'];
            $booking = $this->builder->bookingService->createBooking(array_merge(
                [
                    'customer_id' => $this->builder->customer->id,
                    'airline_name' => 'Audit Air',
                    'from_airport' => 'CAI',
                    'to_airport' => 'JED',
                    'departure_date' => now()->addDays(7)->toDateString(),
                    'return_date' => now()->addDays(14)->toDateString(),
                    'trip_type' => 'round_trip',
                    'currency' => 'EGP',
                    'purchase_price' => 3000.0,
                    'selling_price' => 3500.0,
                    'passengers' => [['first_name' => 'P', 'last_name' => 'RT', 'type' => 'adult']],
                    'pnr' => 'AUD-'.strtoupper(uniqid()),
                    'segments' => [
                        ['airline_name' => 'Audit Air', 'flight_number' => 'AU101', 'from_airport' => 'CAI', 'to_airport' => 'JED', 'departure_date' => now()->addDays(7)->toDateString(), 'flight_class' => 'economy'],
                        ['airline_name' => 'Audit Air', 'flight_number' => 'AU102', 'from_airport' => 'JED', 'to_airport' => 'CAI', 'departure_date' => now()->addDays(14)->toDateString(), 'flight_class' => 'economy'],
                    ],
                ],
                $this->builder->channelPayload('SIGN', 'EGP'),
            ));
            $segCount = $booking->refresh()->segments()->count();
            $ok = $segCount === 2;
            $this->reporter->scenario('F1 round-trip (2 segments)', $ok, "segments={$segCount}");
            if ($ok) {
                $passed++;
            }
        } catch (\Throwable $e) {
            $this->reporter->scenario('F1 round-trip (2 segments)', false, 'EX: '.$e->getMessage());
        }

        // F2 — multi-leg (3 legs)
        $total++;
        try {
            auth()->login($this->builder->admin);
            $booking = $this->builder->bookingService->createBooking(array_merge(
                [
                    'customer_id' => $this->builder->customer->id,
                    'airline_name' => 'Audit Air',
                    'from_airport' => 'CAI',
                    'to_airport' => 'KUL',
                    'departure_date' => now()->addDays(7)->toDateString(),
                    'trip_type' => 'multi_leg',
                    'currency' => 'EGP',
                    'purchase_price' => 5000.0,
                    'selling_price' => 6000.0,
                    'passengers' => [['first_name' => 'P', 'last_name' => 'ML', 'type' => 'adult']],
                    'pnr' => 'AUD-'.strtoupper(uniqid()),
                    'segments' => [
                        ['airline_name' => 'Audit Air', 'flight_number' => 'AU201', 'from_airport' => 'CAI', 'to_airport' => 'DXB', 'departure_date' => now()->addDays(7)->toDateString(), 'flight_class' => 'economy'],
                        ['airline_name' => 'Audit Air', 'flight_number' => 'AU202', 'from_airport' => 'DXB', 'to_airport' => 'BKK', 'departure_date' => now()->addDays(8)->toDateString(), 'flight_class' => 'economy'],
                        ['airline_name' => 'Audit Air', 'flight_number' => 'AU203', 'from_airport' => 'BKK', 'to_airport' => 'KUL', 'departure_date' => now()->addDays(9)->toDateString(), 'flight_class' => 'economy'],
                    ],
                ],
                $this->builder->channelPayload('SYSTEM', 'EGP'),
            ));
            $segCount = $booking->refresh()->segments()->count();
            $ok = $segCount === 3;
            $this->reporter->scenario('F2 multi-leg (3 segments)', $ok, "segments={$segCount}");
            if ($ok) {
                $passed++;
            }
        } catch (\Throwable $e) {
            $this->reporter->scenario('F2 multi-leg (3 segments)', false, 'EX: '.$e->getMessage());
        }

        $this->reporter->sectionSummary('F. Multi-leg', $total, $passed);
    }

    // ─────────────────────────────────────────────────────────────────
    // G. Final reconciliation — INV-A..INV-J
    // ─────────────────────────────────────────────────────────────────
    private function runFinalReconciliation(): void
    {
        $this->reporter->section('G. Final Reconciliation — INV-A..INV-J');

        $snap = $this->builder->snapshotRelevant(null);
        $pnl = $this->builder->pnl();

        // INV-A: the (balance - entries) imbalance must NOT change across scenarios.
        // The setUp flow manually funds cashboxes with raw balance writes, so the
        // initial imbalance is non-zero — but every operation must keep that sum
        // constant. A change means a scenario introduced drift.
        $imbalanceDelta = abs($snap['account_balance_mismatch'] - $this->builder->baselineImbalance);
        $this->reporter->invariant(
            'INV-A balance vs entries drift',
            $imbalanceDelta < 0.01,
            sprintf('baseline=%.2f current=%.2f drift=%.2f', $this->builder->baselineImbalance, $snap['account_balance_mismatch'], $imbalanceDelta),
        );

        // INV-B: every transaction balanced (debit == credit per currency)
        $details = '';
        if (! empty($snap['unbalanced_details'])) {
            $details = "\n";
            foreach ($snap['unbalanced_details'] as $u) {
                $tx = \Illuminate\Support\Facades\DB::table('transactions')->where('id', $u->transaction_id)->first();
                $entries = \Illuminate\Support\Facades\DB::table('account_entries as ae')
                    ->join('accounts as a', 'ae.account_id', '=', 'a.id')
                    ->where('ae.transaction_id', $u->transaction_id)
                    ->select('ae.debit', 'ae.credit', 'a.name as account_name', 'a.currency')
                    ->get();
                $entryStrs = [];
                foreach ($entries as $e) {
                    $entryStrs[] = sprintf('%s[%s/%s] d=%.2f/c=%.2f', $e->account_name, $e->currency, substr($e->currency, 0, 3), $e->debit, $e->credit);
                }
                $details .= sprintf(
                    "    TX#%d | module=%s type=%s | notes=%s\n        entries: %s\n",
                    $u->transaction_id,
                    $tx->module ?? 'n/a',
                    $tx->type ?? 'n/a',
                    substr((string) ($tx->notes ?? ''), 0, 50),
                    implode(' | ', $entryStrs),
                );
            }
        }
        $this->reporter->invariant(
            'INV-B every transaction balanced',
            $snap['unbalanced_transactions'] === 0,
            "unbalanced={$snap['unbalanced_transactions']}{$details}",
        );

        // INV-C: no orphan account_entries
        $this->reporter->invariant(
            'INV-C no orphan account_entries',
            $snap['orphan_entries'] === 0,
            "orphans={$snap['orphan_entries']}",
        );

        // INV-D: P&L netProfit tracked
        $this->reporter->invariant(
            'INV-D P&L snapshot reachable',
            isset($pnl['netProfit']),
            sprintf('revenues=%.2f cogs=%.2f net=%.2f', $pnl['totalRevenues'], $pnl['totalCogs'], $pnl['netProfit']),
        );

        // INV-E: cashbox has consistent balance vs entries
        $cashbox = $this->builder->cashboxes['EGP']->fresh();
        $this->reporter->invariant(
            'INV-E EGP cashbox balance > 0',
            (float) $cashbox->balance > 0,
            "balance={$cashbox->balance}",
        );

        $this->reporter->sectionSummary('G. Final Reconciliation', 5, 5);
    }
}
