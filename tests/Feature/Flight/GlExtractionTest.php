<?php

namespace Tests\Feature\Flight;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightPayment;
use App\Models\Flight\RefundRequest;
use App\Models\Transaction;
use App\Models\Treasury;
use App\Models\User;
use App\Services\Flight\FlightBookingService;
use App\Services\Flight\FlightCarrierRechargeService;
use App\Services\Flight\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * GL EXTRACTION TEST — استخراج القيود الفعلية من قاعدة بيانات الاختبار.
 * يطبع كل transaction / account_entry سطراً سطراً مع أسماء الحسابات الحقيقية.
 *
 * تذكر: اصطلاح النظام معكوس عن المحاسبة القياسية:
 *   credit = يزيد الرصيد  (balance goes UP)
 *   debit  = ينقص الرصيد  (balance goes DOWN)
 *   account.balance = SUM(credit) - SUM(debit)
 */
class GlExtractionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Account $tAcc;
    protected \App\Models\Flight\FlightCarrier $carrier;
    protected RefundService $rs;
    protected FlightBookingService $bs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rs = app(RefundService::class);
        $this->bs = app(FlightBookingService::class);

        $this->admin = User::create([
            'name' => 'GL Admin', 'email' => 'gla@rpt.test',
            'password' => bcrypt('pw'), 'role' => 'admin', 'is_active' => true,
        ]);
        $this->actingAs($this->admin);

        $this->tAcc = Account::create([
            'name' => 'GL-Treasury', 'type' => 'cashbox', 'balance' => 1000000.0,
            'currency' => 'EGP', 'is_active' => true,
            'owner_type' => 'office', 'module_type' => 'office', 'created_by' => $this->admin->id,
        ]);

        $this->carrier = \App\Models\Flight\FlightCarrier::create([
            'name' => 'GL-AirEg', 'code' => 'GLA', 'currency' => 'EGP',
            'balance' => 0.0, 'credit_limit' => 500000.0,
            'is_active' => true, 'created_by' => $this->admin->id,
        ]);
        app(FlightCarrierRechargeService::class)->rechargeFromAccount(
            $this->carrier, $this->tAcc, 300000.0, 'GL setup recharge'
        );
    }

    protected function mkBooking(Customer $c, float $sell, float $cost, bool $pay): FlightBooking
    {
        $b = $this->bs->createBooking([
            'customer_id' => $c->id, 'airline_name' => 'GL-AirEg',
            'from_airport' => 'CAI', 'to_airport' => 'JED',
            'departure_date' => now()->addDays(10)->toDateString(),
            'trip_type' => 'one_way', 'currency' => 'EGP',
            'purchase_price' => $cost, 'selling_price' => $sell,
            'flight_carrier_id' => $this->carrier->id, 'account_id' => $this->tAcc->id,
            'passengers' => [['name' => 'Pax', 'type' => 'adult']],
        ]);
        if ($pay) {
            $this->bs->addPayment($b, [
                'amount' => $sell, 'payment_method' => 'cash', 'account_id' => $this->tAcc->id,
            ]);
        }
        return $b->fresh();
    }

    /** Get ALL transaction IDs for a booking (booking + payments + refunds). */
    protected function txIdsFor(FlightBooking $booking): array
    {
        $fromBooking = Transaction::query()
            ->where('related_type', FlightBooking::class)
            ->where('related_id', $booking->id)
            ->pluck('id');

        $payIds = FlightPayment::where('flight_booking_id', $booking->id)->pluck('id');
        $fromPay = Transaction::query()
            ->where('related_type', FlightPayment::class)
            ->whereIn('related_id', $payIds)
            ->pluck('id');

        $refIds = RefundRequest::where('flight_booking_id', $booking->id)->pluck('id');
        $fromRef = Transaction::query()
            ->where('related_type', RefundRequest::class)
            ->whereIn('related_id', $refIds)
            ->pluck('id');

        return $fromBooking->merge($fromPay)->merge($fromRef)->unique()->sort()->values()->toArray();
    }

    /** Pull GL rows for given tx IDs. Returns array of rows + debit/credit totals. */
    protected function glRows(array $txIds): array
    {
        if (empty($txIds)) return ['rows' => [], 'debit' => 0, 'credit' => 0];

        $rows = DB::table('account_entries as ae')
            ->join('accounts as a', 'a.id', '=', 'ae.account_id')
            ->join('transactions as t', 't.id', '=', 'ae.transaction_id')
            ->whereIn('ae.transaction_id', $txIds)
            ->orderBy('ae.transaction_id')
            ->orderBy('ae.id')
            ->select([
                'ae.transaction_id',
                't.amount as tx_amount',
                't.notes as tx_notes',
                'a.id as account_id',
                'a.name as account_name',
                'a.type as account_type',
                'ae.debit',
                'ae.credit',
                'ae.balance_after',
            ])
            ->get()
            ->toArray();

        $debit  = array_sum(array_column($rows, 'debit'));
        $credit = array_sum(array_column($rows, 'credit'));
        return compact('rows', 'debit', 'credit');
    }

    protected function printGL(string $title, array $glData): void
    {
        echo "\n  ═══ $title ═══\n";
        printf("  %-5s  %-6s  %-38s  %-26s  %-10s  %-10s  %s\n",
            'TxID','AccID','Account Name','Type','Debit','Credit','Bal After');
        echo "  " . str_repeat('─', 112) . "\n";

        $byTx = [];
        foreach ($glData['rows'] as $r) {
            $byTx[$r->transaction_id][] = $r;
        }

        $grandD = 0.0; $grandC = 0.0;
        foreach ($byTx as $txId => $entries) {
            $note = mb_substr(strip_tags((string)($entries[0]->tx_notes ?? '')), 0, 55);
            $amt  = $entries[0]->tx_amount ?? '';
            echo "\n  [Tx #{$txId} | amount={$amt} | {$note}]\n";
            $txD = 0.0; $txC = 0.0;
            foreach ($entries as $e) {
                printf("  %-5s  %-6s  %-38s  %-26s  %-10s  %-10s  %s\n",
                    '', $e->account_id,
                    mb_substr($e->account_name, 0, 36),
                    $e->account_type,
                    number_format($e->debit, 2),
                    number_format($e->credit, 2),
                    number_format($e->balance_after, 2)
                );
                $txD += (float)$e->debit; $txC += (float)$e->credit;
            }
            $ok = abs($txD - $txC) < 0.01 ? '✅' : '❌ UNBALANCED';
            echo "      subtotal: DR=" . number_format($txD,2) . "  CR=" . number_format($txC,2) . "  $ok\n";
            $grandD += $txD; $grandC += $txC;
        }

        echo "\n  " . str_repeat('─', 112) . "\n";
        $ok = abs($grandD - $grandC) < 0.01 ? '✅ BALANCED' : '❌ diff='.($grandD-$grandC);
        printf("  GRAND TOTAL DR=%-12s CR=%-12s %s\n",
            number_format($grandD,2), number_format($grandC,2), $ok);
    }

    protected function printPnl(string $title, array $before, array $after): float
    {
        echo "\n  ─── P&L delta — $title ───\n";
        printf("  %-6s  %-38s  %-26s  %-12s  %-12s  %s\n",
            'AccID','Account Name','Type','Before','After','Change');
        echo "  " . str_repeat('─', 100) . "\n";

        $income  = 0.0;
        $expense = 0.0;

        foreach ($after as $aid => $balAfter) {
            $balBefore = $before[$aid] ?? 0.0;
            $change    = $balAfter - $balBefore;
            if (abs($change) < 0.005) continue;
            $acc  = Account::find($aid);
            if (!$acc) continue;
            $type = (string)($acc->type->value ?? $acc->type);

            printf("  %-6s  %-38s  %-26s  %-12s  %-12s  %s\n",
                $aid, mb_substr($acc->name,0,36), $type,
                number_format($balBefore,2), number_format($balAfter,2),
                number_format($change,2)
            );
            // System convention: income/revenue balance↑ = earned, expense balance↑ = spent
            if (in_array($type, ['income','revenue'], true))          $income  += $change;
            elseif (in_array($type, ['expense','refund_loss'], true)) $expense += $change;
        }

        echo "  " . str_repeat('─', 100) . "\n";
        $net = $income - $expense;
        echo "  Income/Revenue accounts delta:  " . number_format($income, 2) . "\n";
        echo "  Expense/Loss accounts delta:    " . number_format($expense, 2) . "\n";
        echo "  NET P&L:                        " . number_format($net, 2) . " EGP\n";
        return $net;
    }

    protected function snapshot(): array
    {
        return Account::all()->mapWithKeys(fn($a) => [(int)$a->id => (float)$a->fresh()->balance])->toArray();
    }

    // ══════════════════════════════════════════════════════════════════════
    public function test_print_gl_case1_customer_voluntary(): void
    {
        echo "\n\n";
        echo "════════════════════════════════════════════════════════════════════\n";
        echo "  CASE 1: Customer Voluntary Cancel\n";
        echo "  airline_penalty=1000, agency_commission=200\n";
        echo "  cost=7350, sell=7500\n";
        echo "  Expected: supplier_refund=6350, customer_refund=6300\n";
        echo "════════════════════════════════════════════════════════════════════\n";

        $c = Customer::create(['full_name'=>'C1','phone'=>'01011110001','email'=>'c1@gl.t']);
        $b = $this->mkBooking($c, 7500, 7350, true);

        $carrierAfterBooking = (float)$this->carrier->fresh()->balance;
        echo "\n  carrier.balance after booking = $carrierAfterBooking  (300000 − 7350 = 292650)\n";

        $snapBeforeRefund = $this->snapshot();

        $req = $this->rs->createRefundRequest([
            'flight_booking_id' => $b->id,
            'refund_case' => 'customer_voluntary',
            'airline_penalty' => 1000.0,
            'agency_commission' => 200.0,
            'destination' => 'customer_credit',
        ], $this->admin->id);

        $proc = $this->rs->processRefundRequest($req->id, $this->admin->id);

        $carrierAfterRefund = (float)$this->carrier->fresh()->balance;

        echo "\n  refund_request fields:\n";
        echo "    supplier_refund_amount = " . $proc->supplier_refund_amount . "  [7350−1000=6350]\n";
        echo "    supplier_refund_loss   = " . $proc->supplier_refund_loss . "\n";
        echo "    agency_commission      = " . $proc->agency_commission . "\n";
        echo "    airline_penalty        = " . $proc->airline_penalty . "\n";
        echo "    refund_amount          = " . $proc->refund_amount . "  [7500−1000−200=6300]\n";
        echo "    customer_credit_amount = " . $proc->customer_credit_amount . "\n";
        echo "    carrier balance change = " . ($carrierAfterRefund - $carrierAfterBooking) . "  [+6350 expected]\n";

        $allTxIds = $this->txIdsFor($b);
        $glData   = $this->glRows($allTxIds);
        $this->printGL('Case 1 — ALL GL entries (booking + payment + refund)', $glData);

        $snapAfterRefund = $this->snapshot();
        $netPnl = $this->printPnl('Case 1 — refund phase impact', $snapBeforeRefund, $snapAfterRefund);

        $plReport = app(\App\Services\Reports\ProfitLossReportService::class)->report([]);
        echo "\n  ─── ProfitLossReportService::report() for Case 1 ───\n";
        echo "    totalRevenues: " . $plReport['totalRevenues'] . "\n";
        echo "    totalCogs:     " . $plReport['totalCogs'] . "\n";
        echo "    totalExpenses: " . $plReport['totalExpenses'] . "\n";
        echo "    netProfit:     " . $plReport['netProfit'] . " EGP\n";

        // Assert GL is balanced
        $this->assertEqualsWithDelta($glData['debit'], $glData['credit'], 0.01, 'GL must be balanced');
        // Assert carrier got back supplier_refund_amount
        $this->assertEqualsWithDelta(6350.0, $carrierAfterRefund - $carrierAfterBooking, 0.01);
        // Assert customer_credit = 6300
        $this->assertEqualsWithDelta(6300.0, (float)$proc->customer_credit_amount, 0.01);
    }

    // ══════════════════════════════════════════════════════════════════════
    public function test_print_gl_case2a_airline_cancelled_taxes_on_agency(): void
    {
        echo "\n\n";
        echo "════════════════════════════════════════════════════════════════════\n";
        echo "  CASE 2a: Airline Cancelled — Taxes ON AGENCY\n";
        echo "  airline_refund=7100, non_refundable_taxes=250 (علينا)\n";
        echo "  cost=7350, sell=7500\n";
        echo "════════════════════════════════════════════════════════════════════\n";

        $c = Customer::create(['full_name'=>'C2a','phone'=>'01022220001','email'=>'c2a@gl.t']);
        $b = $this->mkBooking($c, 7500, 7350, true);

        $carrierAfterBooking = (float)$this->carrier->fresh()->balance;
        $snapBeforeRefund = $this->snapshot();

        $req = $this->rs->createRefundRequest([
            'flight_booking_id' => $b->id,
            'refund_case' => 'airline_cancelled',
            'airline_refund_amount' => 7100.0,
            'non_refundable_taxes_amount' => 250.0,
            'non_refundable_taxes_on_customer' => false,
            'apply_commission' => false,
            'destination' => 'customer_credit',
        ], $this->admin->id);
        $proc = $this->rs->processRefundRequest($req->id, $this->admin->id);

        $carrierAfterRefund = (float)$this->carrier->fresh()->balance;
        echo "\n  supplier_refund_amount = " . $proc->supplier_refund_amount . "  [7100]\n";
        echo "  supplier_refund_loss   = " . $proc->supplier_refund_loss . "  [250 خسارة علينا]\n";
        echo "  refund_amount (customer) = " . $proc->refund_amount . "  [7500 كامل]\n";
        echo "  carrier balance change   = " . ($carrierAfterRefund - $carrierAfterBooking) . "  [+7100]\n";

        $glData = $this->glRows($this->txIdsFor($b));
        $this->printGL('Case 2a — ALL GL entries', $glData);

        $snapAfter = $this->snapshot();
        $netPnl = $this->printPnl('Case 2a — refund phase', $snapBeforeRefund, $snapAfter);

        $plReport = app(\App\Services\Reports\ProfitLossReportService::class)->report([]);
        echo "\n  ─── ProfitLossReportService::report() for Case 2a ───\n";
        echo "    totalRevenues: " . $plReport['totalRevenues'] . "\n";
        echo "    totalCogs:     " . $plReport['totalCogs'] . "\n";
        echo "    totalExpenses: " . $plReport['totalExpenses'] . "\n";
        echo "    netProfit:     " . $plReport['netProfit'] . " EGP\n";

        $this->assertEqualsWithDelta($glData['debit'], $glData['credit'], 0.01);
        $this->assertEqualsWithDelta(250.0, (float)$proc->supplier_refund_loss, 0.01);
        $this->assertEqualsWithDelta(7500.0, (float)$proc->refund_amount, 0.01);
    }

    // ══════════════════════════════════════════════════════════════════════
    public function test_print_gl_case2b_airline_cancelled_taxes_on_customer(): void
    {
        echo "\n\n";
        echo "════════════════════════════════════════════════════════════════════\n";
        echo "  CASE 2b: Airline Cancelled — Taxes ON CUSTOMER\n";
        echo "  airline_refund=7100, non_refundable_taxes=250 (على العميل)\n";
        echo "  cost=7350, sell=7500\n";
        echo "════════════════════════════════════════════════════════════════════\n";

        $c = Customer::create(['full_name'=>'C2b','phone'=>'01033330001','email'=>'c2b@gl.t']);
        $b = $this->mkBooking($c, 7500, 7350, true);

        $carrierAfterBooking = (float)$this->carrier->fresh()->balance;
        $snapBeforeRefund = $this->snapshot();

        $req = $this->rs->createRefundRequest([
            'flight_booking_id' => $b->id,
            'refund_case' => 'airline_cancelled',
            'airline_refund_amount' => 7100.0,
            'non_refundable_taxes_amount' => 250.0,
            'non_refundable_taxes_on_customer' => true,
            'apply_commission' => false,
            'destination' => 'customer_credit',
        ], $this->admin->id);
        $proc = $this->rs->processRefundRequest($req->id, $this->admin->id);

        $carrierAfterRefund = (float)$this->carrier->fresh()->balance;
        echo "\n  supplier_refund_amount = " . $proc->supplier_refund_amount . "  [7100]\n";
        echo "  supplier_refund_loss   = " . $proc->supplier_refund_loss . "  [0 — الضرائب على العميل]\n";
        echo "  refund_amount (customer) = " . $proc->refund_amount . "  [7500−250=7250]\n";
        echo "  carrier balance change   = " . ($carrierAfterRefund - $carrierAfterBooking) . "  [+7100]\n";

        $glData = $this->glRows($this->txIdsFor($b));
        $this->printGL('Case 2b — ALL GL entries', $glData);

        $snapAfter = $this->snapshot();
        $netPnl = $this->printPnl('Case 2b — refund phase', $snapBeforeRefund, $snapAfter);

        $plReport = app(\App\Services\Reports\ProfitLossReportService::class)->report([]);
        echo "\n  ─── ProfitLossReportService::report() for Case 2b ───\n";
        echo "    totalRevenues: " . $plReport['totalRevenues'] . "\n";
        echo "    totalCogs:     " . $plReport['totalCogs'] . "\n";
        echo "    totalExpenses: " . $plReport['totalExpenses'] . "\n";
        echo "    netProfit:     " . $plReport['netProfit'] . " EGP\n";

        $this->assertEqualsWithDelta($glData['debit'], $glData['credit'], 0.01);
        $this->assertEqualsWithDelta(0.0, (float)$proc->supplier_refund_loss, 0.01);
        $this->assertEqualsWithDelta(7250.0, (float)$proc->refund_amount, 0.01);
    }

    // ══════════════════════════════════════════════════════════════════════
    public function test_print_gl_case3_overpayment(): void
    {
        echo "\n\n";
        echo "════════════════════════════════════════════════════════════════════\n";
        echo "  CASE 3: Overpayment — payment=8000 on selling=7500\n";
        echo "  cost=7000, sell=7500, overpayment=500\n";
        echo "════════════════════════════════════════════════════════════════════\n";

        $c = Customer::create(['full_name'=>'C3','phone'=>'01044440001','email'=>'c3@gl.t']);
        $b = $this->mkBooking($c, 7500, 7000, false);

        $snapBefore = $this->snapshot();
        $treaBalBefore = (float)$this->tAcc->fresh()->balance;

        $this->bs->addPayment($b, [
            'amount' => 8000.0, 'payment_method' => 'cash', 'account_id' => $this->tAcc->id,
            // QA FIX (2026-11-03): an overpayment is rejected by default; it is only
            // accepted when the caller explicitly allows it (the Vue client sends
            // allow_overpayment=true after the user confirms).
            'allow_overpayment' => true,
        ]);
        $bf = $b->fresh();

        $treaBalAfter = (float)$this->tAcc->fresh()->balance;

        echo "\n  After addPayment(8000):\n";
        echo "    booking.paid_amount      = " . $bf->paid_amount . "  [سقف selling_price=7500]\n";
        echo "    booking.remaining_amount = " . $bf->remaining_amount . "\n";
        echo "    CustomerCreditLedger bal = " . \App\Models\CustomerCreditLedger::getAvailableCredit($c->id) . "  [الزيادة 500]\n";
        echo "    treasury balance change  = " . ($treaBalAfter - $treaBalBefore) . "\n";
        echo "\n  NOTE: The treasury RECEIVES 7500 (bookingPaymentAmount, capped at remaining).\n";
        echo "        The 500 overpayment stays in CustomerCreditLedger (NOT in account_entries).\n";
        echo "        FlightPayment.amount = 7500 (capped). FlightPayment.original_amount = 8000.\n";

        $glData = $this->glRows($this->txIdsFor($b));
        $this->printGL('Case 3 — ALL GL entries (booking + payment phase only)', $glData);

        $snapAfter = $this->snapshot();
        $this->printPnl('Case 3 — payment impact', $snapBefore, $snapAfter);

        $this->assertEqualsWithDelta($glData['debit'], $glData['credit'], 0.01);
        $this->assertEqualsWithDelta(500.0,
            (float)\App\Models\CustomerCreditLedger::getAvailableCredit($c->id), 0.01);
    }

    // ══════════════════════════════════════════════════════════════════════
    public function test_print_account_directory(): void
    {
        // Create all 4 scenarios to build the account directory
        $c1 = Customer::create(['full_name'=>'Dir1','phone'=>'01055550001','email'=>'dir1@gl.t']);
        $b1 = $this->mkBooking($c1, 7500, 7350, true);
        $r1 = $this->rs->createRefundRequest([
            'flight_booking_id'=>$b1->id,'refund_case'=>'customer_voluntary',
            'airline_penalty'=>1000.0,'agency_commission'=>200.0,'destination'=>'customer_credit',
        ], $this->admin->id);
        $this->rs->processRefundRequest($r1->id, $this->admin->id);

        $txIds = collect($this->txIdsFor($b1));

        $usedAccIds = DB::table('account_entries')
            ->whereIn('transaction_id', $txIds)
            ->pluck('account_id')->unique()->sort()->values();

        echo "\n\n═══ ACCOUNT DIRECTORY (all accounts touched in Case 1) ═══\n";
        printf("  %-6s  %-40s  %-28s  %-8s  %s\n",'AccID','Name','Type','Currency','Balance');
        echo "  " . str_repeat('─', 100) . "\n";
        foreach (Account::whereIn('id', $usedAccIds)->orderBy('id')->get() as $a) {
            printf("  %-6s  %-40s  %-28s  %-8s  %s\n",
                $a->id, mb_substr($a->name,0,38),
                ($a->type->value??$a->type), $a->currency, number_format($a->balance,2));
        }
        $this->assertTrue(true);
    }
}
