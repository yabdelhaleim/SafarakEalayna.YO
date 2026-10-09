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
 * VISA SOFT DELETE — DEEP ACCOUNTING VERIFICATION
 * ================================================
 *
 * For each scenario we snapshot every account at:
 *   T0 = before booking (pre-transaction state)
 *   T1 = after booking + payments (post-transaction state)
 *   T2 = after soft delete (post-reversal state)
 *
 * The critical invariant: T2 == T0 for EVERY account.
 *
 * Soft delete must:
 *   ① Revert EVERY account move (vault, bank, agent AP, customer AR)
 *   ② Soft-delete the visa_bookings row (deleted_at not null)
 *   ③ Soft-delete every visa_payment row tied to that booking
 *   ④ Each transaction's additive inverse entry created (`عكس القيد #X`)
 *   ⑤ Original transaction rows NEVER mutated
 *   ⑥ Idempotent: second delete throws RuntimeException
 *   ⑦ delete after cancel blocked (status=cancelled)
 *   ⑧ delete after refund blocked (status=refunded)
 */
class VisaDeleteAccountingDeepTest extends TestCase
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
            'name'  => 'Visa Delete Auditor',
            'email' => 'visa-delete-auditor@stress.test',
            'password' => Hash::make('password'),
            'role'  => 'admin',
            'is_active' => true,
        ]);
        Sanctum::actingAs($this->admin, ['*']);

        LedgerBalanceMutationGuard::run(function () {
            $this->vaultEgp = Account::create([
                'name' => 'Delete Vault EGP',
                'type' => AccountType::Cashbox->value,
                'currency' => 'EGP', 'balance' => 200_000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE, 'module_type' => 'tourism',
                'module' => 'visas', 'is_module_vault' => true,
                'created_by' => $this->admin->id,
            ]);

            $this->bankEgp = Account::create([
                'name' => 'Delete Bank EGP',
                'type' => AccountType::Bank->value,
                'currency' => 'EGP', 'balance' => 100_000.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OFFICE, 'module_type' => 'tourism',
                'module' => 'visas', 'is_module_vault' => false,
                'created_by' => $this->admin->id,
            ]);

            $this->agentAp = Account::create([
                'name' => 'Delete Agent AP',
                'type' => AccountType::Supplier->value,
                'currency' => 'EGP', 'balance' => 0.0,
                'is_active' => true,
                'owner_type' => Account::OWNER_TYPE_OWNER, 'module_type' => 'visas',
                'module' => 'visas', 'is_module_vault' => false,
                'created_by' => $this->admin->id,
            ]);
        });

        $this->customer = Customer::query()->create([
            'full_name' => 'Delete Customer', 'phone' => '01000066000',
            'national_id' => '12345678906600', 'passport_number' => 'D0001',
            'type' => 'individual', 'status' => 'active', 'currency' => 'EGP',
            'created_by' => $this->admin->id,
        ]);

        $this->duration = VisaDuration::query()->create([
            'code' => 'DD', 'label_ar' => 'مدة حذف', 'label_en' => 'delete duration',
            'months' => 3, 'entry_type' => 'single', 'sort_order' => 1, 'is_active' => true,
        ]);

        $this->agent = VisaAgent::query()->create([
            'company_name' => 'Delete Agent', 'contact_person' => 'DA',
            'phone' => '01000066999', 'email' => 'delete-agent@stress.test',
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

    protected function assertTxBalanced(int $txId, string $label = ''): void
    {
        $r = DB::table('account_entries')
            ->where('transaction_id', $txId)
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();
        $this->assertEqualsWithDelta(
            $r->d, $r->c, 0.02,
            "Transaction #{$txId} {$label} unbalanced: d={$r->d}, c={$r->c}"
        );
    }

    protected function assertAccountMatchesLedger(int $accountId, string $label = ''): void
    {
        $balance = round((float) Account::find($accountId)->balance, 2);
        $net = $this->netForAccount($accountId);
        $this->assertEqualsWithDelta($net, $balance, 0.02,
            "Account #{$accountId} {$label} mismatch: balance={$balance}, entries_net={$net}");
    }

    protected function snapshot(): array
    {
        return [
            'vault'    => round((float) $this->vaultEgp->fresh()->balance, 2),
            'bank'     => round((float) $this->bankEgp->fresh()->balance, 2),
            'agent_ap' => round((float) $this->agentAp->fresh()->balance, 2),
            'customer' => round((float) ($this->customer->fresh()->ledgerAccount?->fresh()->balance ?? 0.0), 2),
        ];
    }

    protected function makeBooking(array $overrides = []): VisaBooking
    {
        return app(VisaBookingService::class)->create(array_merge([
            'customer_id' => $this->customer->id,
            'purchase_price' => 6000.0, 'selling_price' => 9000.0, 'service_fee' => 500.0,
            'currency' => 'EGP', 'account_id' => $this->vaultEgp->id,
            'status' => VisaStatus::Submitted->value,
            'visa_details' => [
                'visa_type' => VisaType::Tourist->value, 'country' => 'DL',
                'duration' => '90', 'visa_duration_id' => $this->duration->id,
                'entry_type' => VisaEntryType::Single->value,
                'validity_from' => now()->toDateString(),
                'validity_to' => now()->addMonths(3)->toDateString(),
                'visa_agent_id' => $this->agent->id,
            ],
        ], $overrides));
    }

    // ───────────────────────────────────────────────────────────────────────────

    /**
     * Soft-delete with 3 partial payments brings every account back to T0.
     */
    public function test_delete_brings_all_accounts_back_to_T0(): void
    {
        $T0 = $this->snapshot();

        $booking = $this->makeBooking();

        // 3 payments totaling 6000
        $service = app(VisaBookingService::class);
        foreach ([2000, 3000, 1000] as $i => $amt) {
            $service->addPayment($booking, [
                'amount' => $amt, 'account_id' => $this->bankEgp->id,
                'payment_method' => 'cash', 'transaction_reference' => "DL-REF-{$i}",
            ]);
        }
        $booking->refresh();
        $this->assertSame(6000.0, (float) $booking->paid_amount);

        $T1 = $this->snapshot();

        // T1 deltas:
        //   vault:    0 (booking+payments didn't draw from vault)
        //   bank:     +6000 (3 payments received)
        //   agent_ap: -6000 (booking expense → we owe supplier)
        //   customer: +9500 (sale) -6000 (3 payments) = +3500
        $this->assertEqualsWithDelta($T0['vault'],    $T1['vault'],    0.02);
        $this->assertEqualsWithDelta($T0['bank']+6000, $T1['bank'],     0.02);
        $this->assertEqualsWithDelta($T0['agent_ap']-6000, $T1['agent_ap'], 0.02);
        $this->assertEqualsWithDelta($T0['customer']+3500, $T1['customer'], 0.02);

        // === DELETE ===
        $ok = app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->admin->id);
        $this->assertTrue($ok);

        // Booking is soft-deleted
        $trashed = VisaBooking::withTrashed()->findOrFail($booking->id);
        $this->assertNotNull($trashed->deleted_at);

        // Every payment row is soft-deleted
        foreach ($trashed->payments()->withTrashed()->get() as $p) {
            $this->assertNotNull($p->deleted_at, "payment #{$p->id} should be soft-deleted");
        }

        $T2 = $this->snapshot();

        // === THE CRITICAL INVARIANT: T2 == T0 for every account ===
        $this->assertEqualsWithDelta($T0['vault'],    $T2['vault'],    0.02,
            "vault must return to T0 after delete: T0={$T0['vault']}, T2={$T2['vault']}");
        $this->assertEqualsWithDelta($T0['bank'],     $T2['bank'],     0.02,
            "bank must return to T0 after delete: T0={$T0['bank']}, T2={$T2['bank']}");
        $this->assertEqualsWithDelta($T0['agent_ap'], $T2['agent_ap'], 0.02,
            "agent AP must return to T0 after delete: T0={$T0['agent_ap']}, T2={$T2['agent_ap']}");
        $this->assertEqualsWithDelta($T0['customer'], $T2['customer'], 0.02,
            "customer AR must return to T0 after delete: T0={$T0['customer']}, T2={$T2['customer']}");

        // Every affected account still matches its ledger after delete
        $this->assertAccountMatchesLedger($this->vaultEgp->id, 'vault-post-delete');
        $this->assertAccountMatchesLedger($this->bankEgp->id, 'bank-post-delete');
        $this->assertAccountMatchesLedger($this->agentAp->id, 'agent-post-delete');

        // Every original transaction balanced
        $this->assertTxBalanced($booking->expense_transaction_id, 'expense');
        $this->assertTxBalanced($booking->income_transaction_id, 'income');
        foreach ($booking->payments as $p) {
            $this->assertTxBalanced($p->transaction_id, 'payment');
        }

        // Original transaction amounts UNCHANGED
        $this->assertSame(6000.0, (float) Transaction::find($booking->expense_transaction_id)->amount);
        $this->assertSame(9500.0, (float) Transaction::find($booking->income_transaction_id)->amount);
        foreach ($booking->payments as $p) {
            $this->assertSame((float) $p->amount,
                (float) Transaction::find($p->transaction_id)->amount);
        }
    }

    /**
     * Soft-delete of unpaid booking — only expense + income to reverse.
     */
    public function test_delete_unpaid_booking_vaults_return_to_T0(): void
    {
        $T0 = $this->snapshot();

        $booking = $this->makeBooking();

        $entriesBefore = DB::table('account_entries')->count();
        $ok = app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->admin->id);
        $this->assertTrue($ok);

        // 2 reversed transactions (income + expense) × 2 entries = 4 new entries
        $this->assertSame(4, DB::table('account_entries')->count() - $entriesBefore);

        $T2 = $this->snapshot();
        $this->assertEqualsWithDelta($T0['vault'],    $T2['vault'],    0.02);
        $this->assertEqualsWithDelta($T0['bank'],     $T2['bank'],     0.02);
        $this->assertEqualsWithDelta($T0['agent_ap'], $T2['agent_ap'], 0.02);
        $this->assertEqualsWithDelta($T0['customer'], $T2['customer'], 0.02);
    }

    /**
     * Soft-delete with fully-paid booking — must still revert every account.
     */
    public function test_delete_fully_paid_booking_returns_everything(): void
    {
        $T0 = $this->snapshot();

        $booking = $this->makeBooking();

        // Pay the full 9500 (selling+fee)
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 9500, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash',
        ]);
        $booking->refresh();
        $this->assertTrue($booking->is_fully_paid);

        $T1 = $this->snapshot();
        // T1:
        //   vault: 0
        //   bank: +9500
        //   agent_ap: -6000
        //   customer: +9500 - 9500 = 0
        $this->assertEqualsWithDelta($T1['customer'], 0.0, 0.02,
            "fully paid booking should have customer AR at 0");

        app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->admin->id);

        $T2 = $this->snapshot();
        $this->assertEqualsWithDelta($T0['vault'],    $T2['vault'],    0.02);
        $this->assertEqualsWithDelta($T0['bank'],     $T2['bank'],     0.02);
        $this->assertEqualsWithDelta($T0['agent_ap'], $T2['agent_ap'], 0.02);
        $this->assertEqualsWithDelta($T0['customer'], $T2['customer'], 0.02);

        // Verify the booking is gone (soft-delete)
        $this->assertNull(VisaBooking::find($booking->id));
        $this->assertNotNull(VisaBooking::withTrashed()->find($booking->id)->deleted_at);
    }

    /**
     * Soft-delete without visa agent: expense goes directly to the vault,
     * delete must reverse the vault debit.
     */
    public function test_delete_no_agent_vault_direct_reversal(): void
    {
        $T0 = $this->snapshot();

        $booking = $this->makeBooking(['visa_details' => [
            'visa_type' => VisaType::Tourist->value, 'country' => 'DL',
            'duration' => '90', 'visa_duration_id' => $this->duration->id,
            'entry_type' => VisaEntryType::Single->value,
            'validity_from' => now()->toDateString(),
            'validity_to' => now()->addMonths(3)->toDateString(),
            'visa_agent_id' => null,
        ]]);

        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 3000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash',
        ]);

        $T1 = $this->snapshot();
        // T1 (no-agent booking):
        //   vault: -6000 (booking expense)
        //   bank: +3000
        //   agent_ap: 0 (no agent in booking)
        //   customer: +9500 - 3000 = +6500
        $this->assertEqualsWithDelta($T0['vault']-6000, $T1['vault'], 0.02);
        $this->assertEqualsWithDelta($T0['bank']+3000,  $T1['bank'],  0.02);
        $this->assertEqualsWithDelta($T0['agent_ap'],   $T1['agent_ap'], 0.02);

        app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->admin->id);

        $T2 = $this->snapshot();
        $this->assertEqualsWithDelta($T0['vault'],    $T2['vault'],    0.02);
        $this->assertEqualsWithDelta($T0['bank'],     $T2['bank'],     0.02);
        $this->assertEqualsWithDelta($T0['agent_ap'], $T2['agent_ap'], 0.02);
        $this->assertEqualsWithDelta($T0['customer'], $T2['customer'], 0.02);
    }

    /**
     * Idempotent: a second delete throws RuntimeException, no extra entries.
     */
    public function test_double_delete_is_atomic_no_extra_entries(): void
    {
        $booking = $this->makeBooking();
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 2000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash',
        ]);

        app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->admin->id);
        $entriesAfterFirst = DB::table('account_entries')->count();

        try {
            app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->admin->id);
            $this->fail('Second delete should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('محذوف', $e->getMessage());
        }

        $this->assertSame($entriesAfterFirst, DB::table('account_entries')->count(),
            'failed second delete must not create any entries');
    }

    /**
     * Delete-after-cancel is blocked — the cancel already reversed everything.
     */
    public function test_delete_after_cancel_is_blocked(): void
    {
        $booking = $this->makeBooking();
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 1000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash',
        ]);

        app(VisaRefundService::class)->cancel($booking, 'cancel first');
        $entriesAfterCancel = DB::table('account_entries')->count();

        try {
            app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->admin->id);
            $this->fail('Delete after cancel should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('ملغى', $e->getMessage());
        }

        $this->assertSame($entriesAfterCancel, DB::table('account_entries')->count(),
            'failed delete-after-cancel must not create any entries');
    }

    /**
     * Delete-after-refund is blocked — refund already reversed everything.
     */
    public function test_delete_after_refund_is_blocked(): void
    {
        $booking = $this->makeBooking();
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 1000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash',
        ]);

        app(VisaRefundService::class)->refund($booking->fresh(), 'refund first');
        $entriesAfterRefund = DB::table('account_entries')->count();

        try {
            app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->admin->id);
            $this->fail('Delete after refund should have thrown');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('مسترد', $e->getMessage());
        }

        $this->assertSame($entriesAfterRefund, DB::table('account_entries')->count(),
            'failed delete-after-refund must not create any entries');
    }

    /**
     * Global Σ debit == Σ credit across the entire visa module after delete.
     */
    public function test_delete_global_debit_eq_credit(): void
    {
        // 3 bookings, partial payments, delete the middle one
        $bookings = [];
        $service = app(VisaBookingService::class);
        for ($i = 0; $i < 3; $i++) {
            $b = $service->create([
                'customer_id' => $this->customer->id,
                'purchase_price' => 6000.0, 'selling_price' => 9000.0, 'service_fee' => 500.0,
                'currency' => 'EGP', 'account_id' => $this->vaultEgp->id,
                'status' => VisaStatus::Submitted->value,
                'visa_details' => [
                    'visa_type' => VisaType::Tourist->value, 'country' => "DL-{$i}",
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

        app(VisaRefundService::class)->deleteWithReversal($bookings[1]->id, $this->admin->id);

        $r = DB::table('account_entries')
            ->where('is_opening', 0)
            ->selectRaw('COALESCE(SUM(debit),0) as d, COALESCE(SUM(credit),0) as c')
            ->first();
        $this->assertEqualsWithDelta(
            round((float) $r->d, 2),
            round((float) $r->c, 2),
            0.02,
            "Global Σ debit (" . round((float) $r->d, 2) . ") ≠ Σ credit (" . round((float) $r->c, 2) . ") after delete"
        );
    }

    /**
     * Every reverse-entry carries the "عكس القيد #X" prefix.
     */
    public function test_delete_reverse_entries_carry_عكس_prefix(): void
    {
        $booking = $this->makeBooking();
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 1000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash',
        ]);

        app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->admin->id);

        $newEntries = DB::table('account_entries')
            ->where('notes', 'like', 'عكس القيد%')
            ->get();

        // 3 reversed txs (1 payment + income + expense) × 2 entries = 6
        $this->assertGreaterThanOrEqual(6, $newEntries->count());

        foreach ($newEntries as $e) {
            $this->assertStringStartsWith('عكس القيد', $e->notes ?? '');
        }
    }

    /**
     * Every affected account still matches its ledger after delete.
     */
    public function test_delete_all_accounts_ledger_balanced(): void
    {
        $booking = $this->makeBooking();
        app(VisaBookingService::class)->addPayment($booking, [
            'amount' => 2000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash',
        ]);

        app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->admin->id);

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
     * Soft-delete preserves audit trail: the original transactions remain in
     * the DB (not deleted, not modified) and only their inverse entries
     * are added.
     */
    public function test_delete_preserves_original_transactions(): void
    {
        $booking = $this->makeBooking();
        $origExpenseId = $booking->expense_transaction_id;
        $origIncomeId  = $booking->income_transaction_id;

        $service = app(VisaBookingService::class);
        $service->addPayment($booking, ['amount' => 1000, 'account_id' => $this->bankEgp->id, 'payment_method' => 'cash']);
        $origPayId = $booking->payments()->first()->transaction_id;

        app(VisaRefundService::class)->deleteWithReversal($booking->id, $this->admin->id);

        // Original transactions still in DB
        $this->assertNotNull(Transaction::find($origExpenseId), 'original expense tx must remain');
        $this->assertNotNull(Transaction::find($origIncomeId),  'original income tx must remain');
        $this->assertNotNull(Transaction::find($origPayId),    'original payment tx must remain');

        // Each original tx has exactly 2 entries (original) + 2 entries (inverse)
        foreach ([$origExpenseId, $origIncomeId, $origPayId] as $txId) {
            $count = DB::table('account_entries')->where('transaction_id', $txId)->count();
            $this->assertSame(4, $count,
                "Transaction #{$txId} must have 4 entries (2 original + 2 inverse), got {$count}");
        }
    }
}
