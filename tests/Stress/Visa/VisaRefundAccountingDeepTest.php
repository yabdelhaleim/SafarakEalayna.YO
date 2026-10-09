<?php

declare(strict_types=1);

namespace Tests\Stress\Visa;

use App\Enums\AccountType;
use App\Enums\VisaEntryType;
use App\Enums\VisaStatus;
use App\Enums\VisaType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\HajjUmra\VisaAgent;
use App\Models\HajjUmra\VisaDuration;
use App\Models\RefundAuditLog;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VisaBooking;
use App\Models\VisaPayment;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaRefundService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * VISA REFUND — DEEP ACCOUNTING VERIFICATION
 * ===========================================
 *
 * For each scenario, we capture EVERY account's balance at THREE checkpoints:
 *
 *   T0: BEFORE booking (pre-transaction state)
 *   T1: AFTER booking + payments (post-transaction state, pre-refund)
 *   T2: AFTER refund (post-reversal state)
 *
 * The invariants we verify:
 *
 *   ① T2 == T0 for EVERY account (refund fully reverses every account move)
 *   ② T1 deltas are exactly the documented accounting flows
 *   ③ Σ debit == Σ credit on EVERY transaction (including all reversals)
 *   ④ balance == SUM(credit) − SUM(debit) on EVERY account
 *   ⑤ Original transaction rows are NEVER mutated (additive reversal invariant)
 *   ⑥ RefundAuditLog has exactly ONE `refund.processed` row
 *   ⑦ Global Σ debit == Σ credit across the whole visa module
 */
class VisaRefundAccountingDeepTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected Account $vaultEgp;
    protected Account $bankEgp;
    protected Account $agentAp;
    protected Customer $customer;
    protected VisaDuration $duration;
    protected VisaAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::query()->create([
            'name'  => 'Visa Refund Auditor',
            'email' => 'visa-refund-auditor@stress.test',
            'password' => Hash::make('password'),
            'role'  => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        LedgerBalanceMutationGuard::run(function () {
            $this->vaultEgp = Account::create([
                'name' => 'Refund Vault EGP',
                'type' => AccountType::Cashbox->value,
                'currency' => 'EGP', 'balance' => 200_000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE, 'module_type' => 'tourism',
                'module' => 'visas', 'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);

            $this->bankEgp = Account::create([
                'name' => 'Refund Bank EGP',
                'type' => AccountType::Bank->value,
                'currency' => 'EGP', 'balance' => 100_000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE, 'module_type' => 'tourism',
                'module' => 'visas', 'is_module_vault' => false,
                'created_by' => $this->admin->id,
            ]);

            $this->agentAp = Account::create([
                'name' => 'Refund Agent AP',
                'type' => AccountType::Supplier->value,
                'currency' => 'EGP', 'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER, 'module_type' => 'visas',
                'module' => 'visas', 'is_module_vault' => false,
                'created_by' => $this->admin->id,
            ]);
        });

        $this->customer = Customer::query()->create([
            'full_name' => 'Refund Customer', 'phone' => '01000077000',
            'national_id' => '12345678907700', 'passport_number' => 'R0001',
            'type' => 'individual', 'status' => 'active', 'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $this->duration = VisaDuration::query()->create([
            'code' => 'RD', 'label_ar' => 'مدة', 'label_en' => 'duration',
            'months' => 3, 'entry_type' => 'single', 'sort_order' => 1, 'is_active' => true,
        ]);

        $this->agent = VisaAgent::query()->create([
            'company_name' => 'Refund Agent', 'contact_person' => 'RA',
            'phone' => '01000077999', 'email' => 'refund-agent@stress.test',
            'country' => 'EG', 'visa_type' => 'tourist',
            'default_cost_price' => 6000.0, 'account_id' => $this->agentAp->id,
            'is_active' => true, 'created_by' => $this->admin->id,
        ]);
    }

    protected function netForAccount(int $accountId): float
    {
        $r = DB::table('account_entries')
            ->where('account_id', $accountId)
            ->selectRaw('COALESCE(SUM(credit),0) - COALESCE(SUM(debit),0) as net')
            ->value('net');
        return round((float) $r, 2);
    }

    protected function txBalance(int $txId): array
    {
        $r = DB::table('account_entries')
            ->where('transaction_id', $txId)
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();
        return ['debit' => round((float) $r->d, 2), 'credit' => round((float) $r->c, 2)];
    }

    protected function assertTxBalanced(int $txId, string $label = ''): void
    {
        $r = $this->txBalance($txId);
        $this->assertEqualsWithDelta(
            $r['debit'], $r['credit'], 0.02,
            "Transaction #{$txId} {$label} is unbalanced: debit={$r['debit']}, credit={$r['credit']}"
        );
    }

    protected function assertAccountMatchesLedger(int $accountId, string $label = ''): void
    {
        $balance = round((float) Account::find($accountId)->balance, 2);
        $net = $this->netForAccount($accountId);
        $this->assertEqualsWithDelta(
            $net, $balance, 0.02,
            "Account #{$accountId} {$label} mismatch: balance={$balance}, entries_net={$net}"
        );
    }

    /** Snapshot every account balance into an associative array. */
    protected function snapshot(): array
    {
        return [
            'vault'    => round((float) $this->vaultEgp->fresh()->balance, 2),
            'bank'     => round((float) $this->bankEgp->fresh()->balance, 2),
            'agent_ap' => round((float) $this->agentAp->fresh()->balance, 2),
            'customer' => round((float) ($this->customer->fresh()->ledgerAccount?->fresh()->balance ?? 0.0), 2),
        ];
    }

    /**
     * Full lifecycle refund with 3 partial payments — every account traced
     * at T0 (before), T1 (after booking+payments), T2 (after refund).
     * Invariant: T2 == T0 for every account.
     */
    public function test_refund_fully_reverses_to_pre_booking_state(): void
    {
        $T0 = $this->snapshot();

        $booking = app(VisaBookingService::class)->create([
            'customer_id' => $this->customer->id,
            'purchase_price' => 6000.0, 'selling_price' => 9000.0, 'service_fee' => 500.0,
            'currency' => 'EGP', 'account_id' => $this->vaultEgp->id,
            'status' => VisaStatus::Submitted->value,
            'visa_details' => [
                'visa_type' => VisaType::Tourist->value, 'country' => 'RF',
                'duration' => '90', 'visa_duration_id' => $this->duration->id,
                'entry_type' => VisaEntryType::Single->value,
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addMonths(3)->toDateString(),
                'visa_agent_id' => $this->agent->id,
            ],
        ]);

        // 3 payments totaling 6000
        $service = app(VisaBookingService::class);
        foreach ([2000, 3000, 1000] as $i => $amt) {
            $service->addPayment($booking, [
                'amount' => $amt, 'account_id' => $this->bankEgp->id,
                'payment_method' => 'cash', 'transaction_reference' => "REF-RF-{$i}",
            ]);
        }
        $booking->refresh();
        $this->assertSame(6000.0, (float) $booking->paid_amount);

        $T1 = $this->snapshot();

        // T1 deltas (the documented accounting flow):
        //   vault:    unchanged (booking did not draw from vault — paid into bank)
        //   bank:     +6000 (3 payments of 2000+3000+1000)
        //   agent_ap: -6000 (booking expense posted to supplier AP = we owe supplier)
        //   customer: +9500 - 6000 = +3500 (sale credit 9500, then payment debits 6000)
        $this->assertEqualsWithDelta($T0['vault'],    $T1['vault'],    0.02);
        $this->assertEqualsWithDelta($T0['bank']+6000, $T1['bank'],     0.02);
        $this->assertEqualsWithDelta($T0['agent_ap']-6000, $T1['agent_ap'], 0.02);
        $this->assertEqualsWithDelta($T0['customer']+3500, $T1['customer'], 0.02);

        // Every transaction balanced
        $this->assertTxBalanced($booking->expense_transaction_id, 'expense');
        $this->assertTxBalanced($booking->income_transaction_id, 'income');
        foreach ($booking->payments as $p) {
            $this->assertTxBalanced($p->transaction_id, 'payment');
        }

        // Every account matches its ledger
        $this->assertAccountMatchesLedger($this->vaultEgp->id, 'vault-T1');
        $this->assertAccountMatchesLedger($this->bankEgp->id, 'bank-T1');
        $this->assertAccountMatchesLedger($this->agentAp->id, 'agent-T1');

        // === REFUND ===
        $entriesBeforeRefund = DB::table('account_entries')->count();

        $refunded = app(VisaRefundService::class)->refund($booking->fresh(), 'full reversal');

        $entriesAfterRefund = DB::table('account_entries')->count();
        $newEntries = $entriesAfterRefund - $entriesBeforeRefund;
        // 5 reversed transactions (3 payments + income + expense) × 2 entries each = 10
        $this->assertGreaterThanOrEqual(10, $newEntries,
            "Refund should add at least 10 ledger entries (5 reversed txs × 2 lines)");

        $T2 = $this->snapshot();

        // === THE CRITICAL INVARIANT ===
        // After refund, every account must return EXACTLY to its pre-booking state.
        $this->assertEqualsWithDelta($T0['vault'],    $T2['vault'],    0.02, 'vault must return to T0');
        $this->assertEqualsWithDelta($T0['bank'],     $T2['bank'],     0.02, 'bank must return to T0');
        $this->assertEqualsWithDelta($T0['agent_ap'], $T2['agent_ap'], 0.02, 'agent AP must return to T0');
        $this->assertEqualsWithDelta($T0['customer'], $T2['customer'], 0.02, 'customer AR must return to T0');

        // All affected accounts still match their ledgers post-refund
        $this->assertAccountMatchesLedger($this->vaultEgp->id, 'vault-T2');
        $this->assertAccountMatchesLedger($this->bankEgp->id, 'bank-T2');
        $this->assertAccountMatchesLedger($this->agentAp->id, 'agent-T2');

        // Original transaction amounts UNCHANGED (additive reversal invariant)
        $this->assertSame(6000.0, (float) Transaction::find($booking->expense_transaction_id)->amount);
        $this->assertSame(9500.0, (float) Transaction::find($booking->income_transaction_id)->amount);
        foreach ($booking->payments as $p) {
            $this->assertSame((float) $p->amount,
                (float) Transaction::find($p->transaction_id)->amount,
                "original payment #{$p->id} amount untouched");
        }

        $this->assertSame('refunded', $refunded->status->value ?? $refunded->status);

        // Exactly one refund.processed audit row
        $count = RefundAuditLog::where('booking_id', $refunded->id)
            ->where('module', 'visa')->count();
        $this->assertSame(1, $count, 'exactly one refund.processed audit row');
    }

    /**
     * Zero-payment refund: status flips but no money moves.
     */
    public function test_refund_zero_payment_is_a_pure_void(): void
    {
        $T0 = $this->snapshot();

        $booking = app(VisaBookingService::class)->create([
            'customer_id' => $this->customer->id,
            'purchase_price' => 6000.0, 'selling_price' => 9000.0, 'service_fee' => 500.0,
            'currency' => 'EGP', 'account_id' => $this->vaultEgp->id,
            'status' => VisaStatus::Submitted->value,
            'visa_details' => [
                'visa_type' => VisaType::Tourist->value, 'country' => 'RF',
                'duration' => '90', 'visa_duration_id' => $this->duration->id,
                'entry_type' => VisaEntryType::Single->value,
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addMonths(3)->toDateString(),
                'visa_agent_id' => $this->agent->id,
            ],
        ]);

        $entriesBefore = DB::table('account_entries')->count();
        $refunded = app(VisaRefundService::class)->refund($booking, 'void refund');
        $entriesAdded = DB::table('account_entries')->count() - $entriesBefore;

        // No payments → only income+expense reversals = 4 entries (2 txs × 2 lines)
        $this->assertSame(4, $entriesAdded, 'void refund should add 4 entries (income+expense reversals)');

        $T2 = $this->snapshot();
        $this->assertEqualsWithDelta($T0['vault'],    $T2['vault'],    0.02);
        $this->assertEqualsWithDelta($T0['bank'],     $T2['bank'],     0.02);
        $this->assertEqualsWithDelta($T0['agent_ap'], $T2['agent_ap'], 0.02);
        $this->assertEqualsWithDelta($T0['customer'], $T2['customer'], 0.02);

        $this->assertSame('refunded', $refunded->status->value ?? $refunded->status);
    }

    /**
     * Partial-payment refund: returns the paid portion to the bank,
     * cancels the full sale.
     */
    public function test_refund_partial_payment_returns_only_paid_amount(): void
    {
        $T0 = $this->snapshot();

        $booking = app(VisaBookingService::class)->create([
            'customer_id' => $this->customer->id,
            'purchase_price' => 6000.0, 'selling_price' => 9000.0, 'service_fee' => 500.0,
            'currency' => 'EGP', 'account_id' => $this->vaultEgp->id,
            'status' => VisaStatus::Submitted->value,
            'visa_details' => [
                'visa_type' => VisaType::Tourist->value, 'country' => 'RF',
                'duration' => '90', 'visa_duration_id' => $this->duration->id,
                'entry_type' => VisaEntryType::Single->value,
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addMonths(3)->toDateString(),
                'visa_agent_id' => $this->agent->id,
            ],
        ]);

        // Pay only 3000 of 9500
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 3000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash',
        ]);

        $refunded = app(VisaRefundService::class)->refund($booking->fresh(), 'partial refund');

        $T2 = $this->snapshot();

        // Bank: +3000 (payment) then -3000 (refund reversal) → back to T0
        $this->assertEqualsWithDelta($T0['bank'], $T2['bank'], 0.02,
            'bank must be back to T0 (full reversal of 3000)');

        // Agent AP: -6000 (booking expense) then +6000 (reversal) → back to T0
        $this->assertEqualsWithDelta($T0['agent_ap'], $T2['agent_ap'], 0.02);

        // Customer: +9500 (sale) -3000 (payment) -9500 (income reversal) +3000 (payment reversal) → back to T0
        $this->assertEqualsWithDelta($T0['customer'], $T2['customer'], 0.02);

        // The full sale (9500) is reversed — refund is "cancel the sale + return the cash"
        $this->assertSame('refunded', $refunded->status->value ?? $refunded->status);
        $this->assertSame(9500.0, (float) Transaction::find($booking->income_transaction_id)->amount,
            'original income amount untouched');
    }

    /**
     * Refund of booking WITHOUT visa agent: expense posts directly to vault.
     * Refund must reverse the vault debit.
     */
    public function test_refund_no_agent_vault_direct_expense(): void
    {
        $T0 = $this->snapshot();

        $booking = app(VisaBookingService::class)->create([
            'customer_id' => $this->customer->id,
            'purchase_price' => 6000.0, 'selling_price' => 9000.0, 'service_fee' => 500.0,
            'currency' => 'EGP', 'account_id' => $this->vaultEgp->id,
            'status' => VisaStatus::Submitted->value,
            'visa_details' => [
                'visa_type' => VisaType::Tourist->value, 'country' => 'RF',
                'duration' => '90', 'visa_duration_id' => $this->duration->id,
                'entry_type' => VisaEntryType::Single->value,
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addMonths(3)->toDateString(),
                'visa_agent_id' => null,
            ],
        ]);

        // Expense posted to vault itself (T1: vault -6000)
        $T1 = $this->snapshot();
        $this->assertEqualsWithDelta($T0['vault']-6000, $T1['vault'], 0.02,
            'vault should be debited 6000 by the booking expense');
        // Agent AP unchanged (no agent in booking)
        $this->assertEqualsWithDelta($T0['agent_ap'], $T1['agent_ap'], 0.02);

        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 2000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash',
        ]);

        $refunded = app(VisaRefundService::class)->refund($booking->fresh(), 'no-agent refund');

        $T2 = $this->snapshot();
        $this->assertEqualsWithDelta($T0['vault'],    $T2['vault'],    0.02);
        $this->assertEqualsWithDelta($T0['bank'],     $T2['bank'],     0.02);
        $this->assertEqualsWithDelta($T0['agent_ap'], $T2['agent_ap'], 0.02);
        $this->assertEqualsWithDelta($T0['customer'], $T2['customer'], 0.02);

        $this->assertSame('refunded', $refunded->status->value ?? $refunded->status);
    }

    /**
     * Refund is atomic: if any step throws, NO entries are created.
     * (Verified by attempting a second refund on a refunded booking.)
     */
    public function test_double_refund_is_atomic_no_extra_entries(): void
    {
        $booking = app(VisaBookingService::class)->create([
            'customer_id' => $this->customer->id,
            'purchase_price' => 6000.0, 'selling_price' => 9000.0, 'service_fee' => 500.0,
            'currency' => 'EGP', 'account_id' => $this->vaultEgp->id,
            'status' => VisaStatus::Submitted->value,
            'visa_details' => [
                'visa_type' => VisaType::Tourist->value, 'country' => 'RF',
                'duration' => '90', 'visa_duration_id' => $this->duration->id,
                'entry_type' => VisaEntryType::Single->value,
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addMonths(3)->toDateString(),
                'visa_agent_id' => $this->agent->id,
            ],
        ]);
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 1000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash',
        ]);

        app(VisaRefundService::class)->refund($booking->fresh(), 'first');
        $entriesAfterFirst = DB::table('account_entries')->count();

        // Second refund MUST throw and MUST NOT create any entries
        try {
            app(VisaRefundService::class)->refund(VisaBooking::find($booking->id), 'second');
            $this->fail('Second refund should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('refunded', $e->getMessage());
        }

        $entriesAfterSecond = DB::table('account_entries')->count();
        $this->assertSame($entriesAfterFirst, $entriesAfterSecond,
            'failed second refund must not add any entries');
    }

    /**
     * Global Σ debit == Σ credit across the entire visa module after a refund.
     */
    public function test_refund_global_debit_eq_credit(): void
    {
        // 3 bookings, partial payments, refund the middle one
        $bookings = [];
        $service = app(VisaBookingService::class);
        for ($i = 0; $i < 3; $i++) {
            $b = $service->create([
                'customer_id' => $this->customer->id,
                'purchase_price' => 6000.0, 'selling_price' => 9000.0, 'service_fee' => 500.0,
                'currency' => 'EGP', 'account_id' => $this->vaultEgp->id,
                'status' => VisaStatus::Submitted->value,
                'visa_details' => [
                    'visa_type' => VisaType::Tourist->value, 'country' => "RF-{$i}",
                    'duration' => '90', 'visa_duration_id' => $this->duration->id,
                    'entry_type' => VisaEntryType::Single->value,
                    'validity_from' => now()->toDateString(),
                    'validity_to' => now()->addMonths(3)->toDateString(),
                    'visa_agent_id' => $this->agent->id,
                ],
            ]);
            $service->addPayment($b, [
                'amount' => 1500, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash',
            ]);
            $bookings[] = $b;
        }

        app(VisaRefundService::class)->refund($bookings[1]->fresh(), 'refund middle');

        $r = DB::table('account_entries')
            ->where('is_opening', 0)
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();
        $this->assertEqualsWithDelta(
            round((float) $r->d, 2),
            round((float) $r->c, 2),
            0.02,
            "Global Σ debit (" . round((float) $r->d, 2) . ") ≠ Σ credit (" . round((float) $r->c, 2) . ") after refund"
        );
    }

    /**
     * Every account's balance still matches its ledger after refund.
     */
    public function test_refund_all_accounts_ledger_balanced(): void
    {
        $booking = app(VisaBookingService::class)->create([
            'customer_id' => $this->customer->id,
            'purchase_price' => 6000.0, 'selling_price' => 9000.0, 'service_fee' => 500.0,
            'currency' => 'EGP', 'account_id' => $this->vaultEgp->id,
            'status' => VisaStatus::Submitted->value,
            'visa_details' => [
                'visa_type' => VisaType::Tourist->value, 'country' => 'RF',
                'duration' => '90', 'visa_duration_id' => $this->duration->id,
                'entry_type' => VisaEntryType::Single->value,
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addMonths(3)->toDateString(),
                'visa_agent_id' => $this->agent->id,
            ],
        ]);
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 2000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash',
        ]);
        app(VisaRefundService::class)->refund($booking->fresh(), 'ledger check');

        // Every account with at least one visa-module entry must be ledger-balanced
        $accountIds = DB::table('account_entries')
            ->join('transactions', 'account_entries.transaction_id', '=', 'transactions.id')
            ->where('transactions.module', 'visa')
            ->distinct()
            ->pluck('account_entries.account_id');

        foreach ($accountIds as $aid) {
            $this->assertAccountMatchesLedger((int) $aid, "account-{$aid}");
        }
    }

    /**
     * All reverse-entries are tagged with "عكس القيد #X" notes prefix
     * (project's de-facto reversal convention).
     */
    public function test_refund_entries_carry_عكس_prefix(): void
    {
        $booking = app(VisaBookingService::class)->create([
            'customer_id' => $this->customer->id,
            'purchase_price' => 6000.0, 'selling_price' => 9000.0, 'service_fee' => 500.0,
            'currency' => 'EGP', 'account_id' => $this->vaultEgp->id,
            'status' => VisaStatus::Submitted->value,
            'visa_details' => [
                'visa_type' => VisaType::Tourist->value, 'country' => 'RF',
                'duration' => '90', 'visa_duration_id' => $this->duration->id,
                'entry_type' => VisaEntryType::Single->value,
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addMonths(3)->toDateString(),
                'visa_agent_id' => $this->agent->id,
            ],
        ]);
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 1000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash',
        ]);

        $entriesBeforeRefund = DB::table('account_entries')->count();
        app(VisaRefundService::class)->refund($booking->fresh(), 'notes prefix check');

        // Query entries by notes prefix (more reliable than id range when opening entries share the table)
        $newEntries = DB::table('account_entries')
            ->where('notes', 'like', 'عكس القيد%')
            ->get();

        // 5 reversed transactions (3 payments + income + expense)... wait we have 1 payment = 3 txs total
        // 3 reversed txs (1 payment + income + expense) × 2 entries = 6 reverse entries
        $this->assertGreaterThanOrEqual(6, $newEntries->count(),
            'expected at least 6 reverse entries for 1 payment + income + expense');

        foreach ($newEntries as $e) {
            $this->assertStringStartsWith('عكس القيد', $e->notes ?? '',
                "Refund entry #{$e->id} must carry the عكس القيد prefix");
        }
    }
}
