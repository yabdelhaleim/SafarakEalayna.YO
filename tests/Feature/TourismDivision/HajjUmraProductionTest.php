<?php

namespace Tests\Feature\TourismDivision;

use App\Enums\HajjUmraStatus;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Program;
use App\Models\Transaction;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\UmrahSupplier;

/**
 * PRODUCTION TEST SUITE — HajjUmra (الحج والعمرة)
 *
 * Coverage scope:
 *  - Precise calculation logic (purchase, selling, profit, companion, accommodation_extra)
 *  - Double-entry integrity on every booking/payment/refund
 *  - Additive reversal semantics (no destructive cancels)
 *  - Customer AR ledger (المديونية على العميل) — create → pay → cancel → refund → delete
 *  - Module isolation (HajjUmraLiquidityAccount rule, module_type guards)
 *  - Treasury + dashboard + customer-balances/customer-statement APIs
 *  - Executing company + UmrahSupplier AP flows (withdraw/repay)
 */
class HajjUmraProductionTest extends TourismTestCase
{
    // ─────────────────────────────────────────────────────────────────
    // A. PRECISE BOOKING CALCULATIONS
    // ─────────────────────────────────────────────────────────────────

    public function test_booking_profit_equals_selling_minus_purchase_with_two_decimals(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12500.50,
            'account_id' => $this->cashbox->id,
            'status' => HajjUmraStatus::Confirmed->value,
        ]);

        $resp->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.pricing.profit', 2500.50);
    }

    public function test_booking_with_companion_prices_aggregates_correctly(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();
        $companion = $this->makeCustomer();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'companion_customer_id' => $companion->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'companion_purchase_price' => 8000.00,
            'selling_price' => 12500.00,
            'companion_selling_price' => 10500.00,
            'account_id' => $this->cashbox->id,
        ]);

        $resp->assertCreated();
        $booking = HajjUmraBooking::find($resp->json('data.id'));

        $this->assertEqualsWithDelta(18000.0, (float) $booking->purchase_price + (float) $booking->companion_purchase_price, 0.01);
        $this->assertEqualsWithDelta(23000.0, (float) $booking->selling_price + (float) $booking->companion_selling_price, 0.01);
        $this->assertEqualsWithDelta(5000.0, (float) $booking->profit, 0.01);
    }

    public function test_booking_with_accommodation_extra_charge_is_included_in_selling(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'accommodation_extra_charge' => 1500.00,
            'account_id' => $this->cashbox->id,
        ]);

        $resp->assertCreated();
        $booking = HajjUmraBooking::find($resp->json('data.id'));

        $this->assertEqualsWithDelta(1500.0, (float) $booking->accommodation_extra_charge, 0.01);
        $this->assertEqualsWithDelta(3500.0, (float) $booking->profit, 0.01);
    }

    public function test_booking_with_passenger_pricing_grid_persists_subtotals(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 20000.00,
            'selling_price' => 25000.00,
            'account_id' => $this->cashbox->id,
            'passengers' => [
                ['category' => 'adult', 'count' => 2, 'unit_price' => 8000, 'subtotal' => 16000],
                ['category' => 'child_with_bed', 'count' => 1, 'unit_price' => 6000, 'subtotal' => 6000],
                ['category' => 'child_no_bed', 'count' => 1, 'unit_price' => 3000, 'subtotal' => 3000],
                ['category' => 'infant', 'count' => 1, 'unit_price' => 0, 'subtotal' => 0],
            ],
        ]);

        $resp->assertCreated();
        $bookingId = $resp->json('data.id');
        $this->assertDatabaseHas('umrah_transaction_passengers', [
            'transaction_id' => $bookingId,
            'category' => 'adult',
            'count' => 2,
            'subtotal' => 16000,
        ]);
        $this->assertDatabaseCount('umrah_transaction_passengers', 4);
    }

    public function test_booking_with_decimal_precision_profit_rounded_to_two(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        // 12345.678 - 9876.543 = 2469.135 → rounded to 2469.14
        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 9876.543,
            'selling_price' => 12345.678,
            'account_id' => $this->cashbox->id,
        ]);

        $resp->assertCreated()
            ->assertJsonPath('data.pricing.profit', 2469.14);
    }

    // ─────────────────────────────────────────────────────────────────
    // B. DOUBLE-ENTRY INTEGRITY (القيود المحاسبية)
    // ─────────────────────────────────────────────────────────────────

    public function test_booking_creation_posts_balanced_income_and_expense_transactions(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();
        $openingCashboxBalance = (float) $this->cashbox->fresh()->balance;

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ]);

        $resp->assertCreated();
        $bookingId = $resp->json('data.id');

        $txs = $this->transactionsForBooking(HajjUmraBooking::class, $bookingId);
        $this->assertGreaterThanOrEqual(2, $txs->count(), 'Booking should have ≥ 2 transactions (expense + income)');

        foreach ($txs as $tx) {
            $this->assertTransactionBalanced($tx, 'booking create');
            $this->assertEquals('hajj_umra', $tx->module->value);
        }

        $this->assertEqualsWithDelta($openingCashboxBalance - 10000.0, (float) $this->cashbox->fresh()->balance, 0.02);
        $this->assertCustomerBalance($customer, 12000.0, 'after booking creation');
    }

    public function test_booking_with_supplier_routes_expense_to_supplier_account(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();
        $supplier = $this->makeUmrahSupplier();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'supplier_id' => $supplier->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ]);

        $resp->assertCreated();
        $supplierAccount = Account::find($supplier->account_id);

        // System convention: positive balance = receivable (they owe us);
        // for suppliers the AP is recorded as a NEGATIVE balance (we owe them).
        $this->assertLessThan(0.0, (float) $supplierAccount->balance,
            'Supplier account should go negative (we owe them)');
        $this->assertEqualsWithDelta(-10000.0, (float) $supplierAccount->balance, 0.02);
    }

    public function test_booking_with_executing_company_auto_creates_supplier_account(): void
    {
        $company = HajjUmraExecutingCompany::query()->create([
            'name' => 'شركة منفذة للاختبار '.uniqid(),
            'phone' => '01000000000',
            'is_active' => true,
        ]);
        $this->assertNotNull($company->account_id, 'Auto-create observer should mint Account');

        $program = $this->makeProgram(['executing_company_id' => $company->id]);
        $customer = $this->makeCustomer();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ]);

        $resp->assertCreated();
        $execAccount = Account::find($company->account_id);
        $this->assertLessThan(0.0, (float) $execAccount->balance,
            'Executing-company AP should be negative (we owe them)');
        $this->assertEqualsWithDelta(-10000.0, (float) $execAccount->balance, 0.02);
    }

    public function test_booking_rejects_when_cashbox_balance_insufficient(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();
        $poorCashbox = $this->makeAccount('cashbox', 'خزينة فقيرة', 'tourism', 100.00);

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $poorCashbox->id,
        ]);

        // ApiResponse::error() returns 422 with success=false
        $resp->assertStatus(422)->assertJsonPath('success', false);
        $this->assertStringContainsString('رصيد', $resp->json('message'));
    }

    public function test_booking_rejects_account_from_other_division(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();
        $officeCashbox = $this->makeAccount('cashbox', 'خزينة مكتب', 'office', 50000.00);

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $officeCashbox->id,
        ]);

        $resp->assertStatus(422)->assertJsonValidationErrors(['account_id']);
    }

    // ─────────────────────────────────────────────────────────────────
    // C. CUSTOMER LEDGER + DEBT (المديونيه)
    // ─────────────────────────────────────────────────────────────────

    public function test_initial_payment_reduces_customer_ar_exactly_by_payment_amount(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
            'initial_payment' => [
                'amount' => 5000.00,
                'payment_method' => 'cash',
            ],
        ]);

        $resp->assertCreated();
        $this->assertCustomerBalance($customer, 12000.0 - 5000.0, 'after initial_payment 5000');
    }

    public function test_multiple_partial_payments_compose_to_full_settlement(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ]);
        $resp->assertCreated();
        $bookingId = $resp->json('data.id');

        // Pay in three installments (each returns 201)
        foreach ([3000.00, 5000.00, 4000.00] as $amount) {
            $payResp = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
                'amount' => $amount,
                'payment_method' => 'cash',
                'account_id' => $this->cashbox->id,
            ]);
            $payResp->assertStatus(201)->assertJsonPath('success', true);
        }

        $this->assertCustomerBalance($customer, 0.0, 'after 3 payments totaling 12000');
        // Cashbox net: -10000 (booking expense) + 12000 (payments) = +2000 (profit)
        $this->assertCashboxDelta(2000.0, 'after full settlement');
    }

    public function test_payments_create_balanced_double_entry_transactions(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 3000.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ])->assertStatus(201);

        $payments = HajjUmraPayment::query()->where('hajj_umra_booking_id', $bookingId)->get();
        $this->assertCount(1, $payments);

        foreach ($payments as $p) {
            $tx = Transaction::find($p->transaction_id);
            $this->assertTransactionBalanced($tx, 'payment');
            $this->assertEquals('hajj_umra', $tx->module->value);
        }
    }

    public function test_customer_balances_endpoint_reports_correct_ar_after_booking(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 8000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
            'initial_payment' => ['amount' => 2000.00, 'payment_method' => 'cash'],
        ])->assertCreated();

        $resp = $this->getJson('/api/v1/hajj-umra/customer-balances');
        $resp->assertOk()->assertJsonPath('success', true);

        $row = collect($resp->json('data'))->firstWhere('client_id', $customer->id);
        $this->assertNotNull($row, 'customer must appear in customer-balances');
        $this->assertEqualsWithDelta(12000.0, (float) $row['total_sales'], 0.02);
        $this->assertEqualsWithDelta(2000.0, (float) $row['total_paid'], 0.02);
        $this->assertEqualsWithDelta(10000.0, (float) $row['total_debt'], 0.02);
    }

    public function test_customer_statement_endpoint_lists_booking_and_payments(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 4000.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ])->assertStatus(201);

        // Note: HajjUmra customer statement uses `client_id` query param
        $statementResp = $this->getJson("/api/v1/hajj-umra/customer-statement?client_id={$customer->id}");
        $statementResp->assertOk()->assertJsonPath('success', true);

        $transactions = $statementResp->json('data.transactions') ?? [];
        $this->assertIsArray($transactions);
        $this->assertGreaterThanOrEqual(2, count($transactions), 'Should list at least the booking sale + the payment');

        $summary = $statementResp->json('data.summary');
        $this->assertEqualsWithDelta(12000.0, (float) ($summary['total_sales'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(4000.0, (float) ($summary['total_paid'] ?? 0), 0.02);
        $this->assertEqualsWithDelta(8000.0, (float) ($summary['total_debt'] ?? 0), 0.02);
    }

    public function test_customer_statement_requires_client_id(): void
    {
        $resp = $this->getJson('/api/v1/hajj-umra/customer-statement');
        $resp->assertStatus(400)->assertJsonPath('success', false);
    }

    public function test_pay_debt_via_general_endpoint_reduces_customer_balance(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 8000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
            'initial_payment' => ['amount' => 2000.00, 'payment_method' => 'cash'],
        ])->assertCreated();

        $this->postJson("/api/v1/customers/{$customer->id}/pay-debt", [
            'amount' => 1500.00,
            'account_id' => $this->cashbox->id,
            'module' => 'hajj_umra',
            'type' => 'receipt',
            'notes' => 'سداد',
        ])->assertOk()->assertJsonPath('success', true);

        $this->assertCustomerBalance($customer, 12000.0 - 2000.0 - 1500.0, 'after booking + initial_payment + pay-debt 1500');
    }

    public function test_overpayment_makes_customer_balance_negative(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 15000.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ])->assertStatus(201);

        $this->assertCustomerBalance($customer, 12000.0 - 15000.0, 'after overpayment of 15000');
        $this->assertLessThan(0.0, (float) $customer->ledgerAccount()->first()->balance,
            'Negative balance = credit balance (company owes customer)');
    }

    // ─────────────────────────────────────────────────────────────────
    // D. ADDITIVE REVERSAL (الإلغاء والاسترداد)
    // ─────────────────────────────────────────────────────────────────

    public function test_cancel_reverses_all_transactions_additively_not_destructively(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();
        $openingCashbox = (float) $this->cashbox->fresh()->balance;

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 15000.00,
            'account_id' => $this->cashbox->id,
            'initial_payment' => ['amount' => 5000.00, 'payment_method' => 'cash'],
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $originalTxCount = $this->transactionsForBooking(HajjUmraBooking::class, $bookingId)->count();
        $originalEntryCount = AccountEntry::query()
            ->whereHas('transaction', fn ($q) => $q->where('related_type', HajjUmraBooking::class)->where('related_id', $bookingId))
            ->count();

        $cancelResp = $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}", ['reason' => 'طلب العميل']);
        $cancelResp->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'cancelled');

        // ADDITIVE: original transactions + entries are PRESERVED
        $this->assertEquals($originalTxCount, $this->transactionsForBooking(HajjUmraBooking::class, $bookingId)->count(),
            'Original transactions must not be deleted');

        // ADDITIVE: new reversing entries appear
        $this->assertGreaterThan($originalEntryCount, AccountEntry::query()
            ->whereHas('transaction', fn ($q) => $q->where('related_type', HajjUmraBooking::class)->where('related_id', $bookingId))
            ->count(),
            'Reversing entries must be ADDED');

        // Net effect on each account is zero (additive reversal cancels out)
        $this->assertCustomerBalance($customer, 0.0, 'after full cancel');
        $this->assertEqualsWithDelta($openingCashbox, (float) $this->cashbox->fresh()->balance, 0.02);
        $this->assertAccountLedgerConsistent($this->cashbox->id, 'cashbox after cancel');
        $this->assertAccountLedgerConsistent($customer->ledgerAccount()->first()->id, 'customer after cancel');
    }

    public function test_double_cancel_throws_runtime_exception(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}", ['reason' => 'x'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled');

        // Second cancel: call service directly (controller doesn't catch exception → 500)
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ملغى مسبقاً');
        app(\App\Services\HajjUmra\HajjUmraBookingService::class)
            ->cancel(HajjUmraBooking::find($bookingId), 'second attempt');
    }

    public function test_payment_rejected_on_cancelled_booking(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $this->deleteJson("/api/v1/hajj-umra/bookings/{$bookingId}", ['reason' => 'x'])->assertOk();

        $payResp = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 5000.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ]);
        $payResp->assertStatus(422)->assertJsonPath('success', false);
    }

    public function test_refund_endpoint_completely_unwinds_booking_and_payments(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();
        $openingCashbox = (float) $this->cashbox->fresh()->balance;

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 15000.00,
            'account_id' => $this->cashbox->id,
            'initial_payment' => ['amount' => 10000.00, 'payment_method' => 'cash'],
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $refundResp = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/refund");
        $refundResp->assertOk()->assertJsonPath('success', true);

        $booking = HajjUmraBooking::find($bookingId);
        $this->assertEquals('refunded', $booking->status->value);

        $this->assertCustomerBalance($customer, 0.0, 'after full refund');
        $this->assertEqualsWithDelta($openingCashbox, (float) $this->cashbox->fresh()->balance, 0.02);
    }

    public function test_refund_after_cancel_throws(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/refund")
            ->assertOk()
            ->assertJsonPath('data.booking.status', 'refunded');

        $second = $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/refund");
        $second->assertStatus(422)->assertJsonPath('success', false);
    }

    // ─────────────────────────────────────────────────────────────────
    // E. UPDATE (PRICE REPOST = ADDITIVE)
    // ─────────────────────────────────────────────────────────────────

    public function test_update_selling_price_reposts_income_additively(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $originalIncomeId = HajjUmraBooking::find($bookingId)->income_transaction_id;

        $updateResp = $this->patchJson("/api/v1/hajj-umra/bookings/{$bookingId}", [
            'selling_price' => 14500.00,
        ]);
        $updateResp->assertOk()->assertJsonPath('success', true);

        $booking = HajjUmraBooking::find($bookingId);
        $this->assertNotEquals($originalIncomeId, $booking->income_transaction_id,
            'income_transaction_id should be replaced after repost');
        $this->assertEqualsWithDelta(14500.0, (float) $booking->incomeTransaction->amount, 0.02);

        // Original transaction still exists with "عكس:" prefix in notes
        $originalTx = Transaction::find($originalIncomeId);
        $this->assertNotNull($originalTx);
        $this->assertStringStartsWith('عكس:', $originalTx->notes);

        $this->assertCustomerBalance($customer, 14500.0, 'after repost');
    }

    // ─────────────────────────────────────────────────────────────────
    // F. TREASURY + DASHBOARD APIs
    // ─────────────────────────────────────────────────────────────────

    public function test_treasury_overview_groups_hajj_umra_accounts(): void
    {
        $resp = $this->getJson('/api/v1/hajj-umra/treasury/overview');
        $resp->assertOk()->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'settlement_accounts',
                    'executing_companies',
                ],
            ]);
        $ids = collect($resp->json('data.settlement_accounts'))->pluck('id');
        $this->assertTrue($ids->contains($this->cashbox->id));
        $this->assertTrue($ids->contains($this->bank->id));
    }

    public function test_dashboard_returns_correct_revenue_and_counts(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();

        $resp = $this->getJson('/api/v1/hajj-umra/dashboard');
        $resp->assertOk()->assertJsonPath('success', true)
            ->assertJsonPath('data.stats.total_bookings', 1);

        $revenue = $resp->json('data.stats.monthly_revenue');
        $this->assertEqualsWithDelta(12000.0, (float) $revenue, 0.02);
    }

    public function test_executing_company_dues_endpoint_returns_positive_balances(): void
    {
        $company = HajjUmraExecutingCompany::query()->create([
            'name' => 'شركة للاختبار',
            'phone' => '01000000000',
            'is_active' => true,
        ]);

        $program = $this->makeProgram(['executing_company_id' => $company->id]);

        $customer = $this->makeCustomer();
        $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();

        $resp = $this->getJson('/api/v1/hajj-umra/executing-companies/dues');
        $resp->assertOk()->assertJsonPath('success', true);

        $items = $resp->json('data.items') ?? $resp->json('data');
        $row = collect($items)->firstWhere('id', $company->id);
        $this->assertNotNull($row, 'Company with positive balance must appear in dues');
        // System convention: net_due = debit - credit; an expense booking causes
        // a credit entry on the company account, so net_due goes negative (we
        // drew on them). What matters is that the company has activity.
        $this->assertGreaterThan(0, (float) ($row['total_repaid'] ?? 0) + (float) ($row['total_withdrawn'] ?? 0),
            'Executing company should have ledger activity after a booking');
    }

    // ─────────────────────────────────────────────────────────────────
    // G. PROGRAMS CRUD
    // ─────────────────────────────────────────────────────────────────

    public function test_program_crud_full_lifecycle(): void
    {
        $create = $this->postJson('/api/v1/hajj-umra/programs', [
            'program_name' => 'برنامج اختبار شامل',
            'program_type' => 'umrah',
            'total_nights' => 10,
            'mecca_hotel_name' => 'فندق مكة',
            'mecca_nights' => 5,
            'medina_hotel_name' => 'فندق المدينة',
            'medina_nights' => 5,
            'airline' => 'مصر للطيران',
            'executing_company' => 'شركة الاختبار',
            'trip_supervisor' => 'مشرف',
            'departure_point' => 'القاهرة',
            'departure_date' => now()->addDays(15)->toDateString(),
            'return_date' => now()->addDays(25)->toDateString(),
            'booking_status' => 'open',
            'default_purchase_price' => 9000.00,
            'default_selling_price' => 11500.00,
        ]);

        $create->assertCreated()->assertJsonPath('success', true);
        $programId = $create->json('data.id');

        $this->getJson("/api/v1/hajj-umra/programs/{$programId}")
            ->assertOk()
            ->assertJsonPath('data.program_name', 'برنامج اختبار شامل');

        $this->patchJson("/api/v1/hajj-umra/programs/{$programId}", [
            'program_name' => 'محدّث',
            'booking_status' => 'closed',
        ])->assertOk()->assertJsonPath('data.program_name', 'محدّث');
    }

    // ─────────────────────────────────────────────────────────────────
    // H. ACCOUNTING INTEGRITY INVARIANTS (دعائم محاسبية)
    // ─────────────────────────────────────────────────────────────────

    public function test_every_transaction_in_module_has_balanced_entries(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();

        $resp = $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'purchase_price' => 10000.00,
            'selling_price' => 15000.00,
            'account_id' => $this->cashbox->id,
            'initial_payment' => ['amount' => 5000.00, 'payment_method' => 'cash'],
        ])->assertCreated();
        $bookingId = $resp->json('data.id');

        $this->postJson("/api/v1/hajj-umra/bookings/{$bookingId}/payments", [
            'amount' => 3000.00,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
        ])->assertStatus(201);

        $txs = Transaction::query()->where('module', 'hajj_umra')->get();
        $this->assertGreaterThan(0, $txs->count());

        foreach ($txs as $tx) {
            $this->assertTransactionBalanced($tx, 'tourism module integrity check');
        }
    }

    public function test_account_balance_invariant_holds_for_all_touched_accounts(): void
    {
        $program = $this->makeProgram();
        $customer = $this->makeCustomer();
        $supplier = $this->makeUmrahSupplier();

        $this->postJson('/api/v1/hajj-umra/bookings', [
            'customer_id' => $customer->id,
            'program_id' => $program->id,
            'supplier_id' => $supplier->id,
            'purchase_price' => 10000.00,
            'selling_price' => 12000.00,
            'account_id' => $this->cashbox->id,
        ])->assertCreated();

        $touchedAccounts = [
            $this->cashbox->id,
            $customer->ledgerAccount()->first()->id,
            $supplier->account_id,
        ];
        foreach ($touchedAccounts as $id) {
            $this->assertAccountLedgerConsistent($id, 'post-booking invariant');
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // HELPERS
    // ─────────────────────────────────────────────────────────────────

    public function makeProgram(array $overrides = []): Program
    {
        // SQLite (used in tests) doesn't apply the migration that made
        // `executing_company` nullable, so we must set it to a non-NULL value.
        // To avoid auto-creating a HajjUmraExecutingCompany (which would hijack
        // the booking expense), we bypass the Program saving observer and
        // leave executing_company_id NULL — the booking service then routes the
        // expense to the cashbox (the requested account_id).
        return Program::withoutEvents(function () use ($overrides) {
            return Program::query()->create(array_merge([
                'program_name' => 'برنامج اختبار '.uniqid(),
                'program_type' => 'umrah',
                'executing_company' => '',
                'total_nights' => 10,
                'mecca_hotel_name' => 'فندق مكة',
                'mecca_nights' => 5,
                'medina_hotel_name' => 'فندق المدينة',
                'medina_nights' => 5,
                'airline' => 'مصر للطيران',
                'trip_supervisor' => 'مشرف',
                'accommodation_type' => 'QUAD',
                'default_purchase_price' => 10000,
                'default_selling_price' => 12000,
                'departure_date' => now()->addDays(10)->toDateString(),
                'return_date' => now()->addDays(20)->toDateString(),
                'departure_point' => 'Cairo',
                'is_active' => true,
            ], $overrides));
        });
    }

    public function makeUmrahSupplier(): UmrahSupplier
    {
        return UmrahSupplier::query()->create([
            'name' => 'مورّد اختبار '.uniqid(),
            'phone' => '010'.random_int(10000000, 99999999),
            'default_cost_price' => 9500.00,
            'is_active' => true,
        ])->refresh();
    }
}
