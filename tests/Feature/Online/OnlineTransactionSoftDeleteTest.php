<?php

namespace Tests\Feature\Online;

use App\Enums\OnlineTransactionStatus;
use App\Models\Account;
use App\Models\Online\OnlineTransaction;
use App\Models\Transaction;
use App\Services\Finance\LedgerClearingAccounts;
use Illuminate\Support\Facades\DB;

/**
 * Soft-delete (cancel) flow tests — the core of Phase 10.
 *
 * The Online module implements "delete" as a financial cancel:
 *   1. Reverse every GL transaction linked via related_id.
 *   2. For walk-in clients, reclaim any residual credit on the unified
 *      walk-in AR mirror (FIFO re-allocation + vault credit memo).
 *   3. Stamp the row with status=cancelled + cancelled_by/cancelled_at.
 *   4. Soft-delete the row (deleted_at) via ModelDeletionGuard.
 *
 * These tests assert each step is correct AND that the operation is
 * idempotent (a second `delete()` call is a no-op).
 */
class OnlineTransactionSoftDeleteTest extends OnlineTestCase
{
    public function test_delete_full_payment_booking_returns_balances_to_baseline(): void
    {
        $vaultStart = $this->cashbox->balance;
        $walkInArId = app(LedgerClearingAccounts::class)->onlineWalkInArAccountId();
        $arStart = $this->glBalance($walkInArId);

        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_name' => 'عميل حذف ١',
            'customer_phone' => '0100A',
            'purchase_price' => 100,
            'selling_price' => 250,
            'amount_paid' => 250,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'reference_number' => 'SD-1',
        ]);

        // Sanity: vault received the cash.
        $this->assertEqualsWithDelta(
            $vaultStart + 250.0,
            $this->accountBalance($this->cashbox->id),
            0.01,
        );

        $this->service->delete($tx);

        // All balances should return to the baseline.
        $this->assertEqualsWithDelta(
            $vaultStart,
            $this->accountBalance($this->cashbox->id),
            0.01,
            'Vault should return to baseline after full-payment cancel.',
        );
        $this->assertEqualsWithDelta(
            $arStart,
            $this->glBalance($walkInArId),
            0.01,
            'Walk-in AR mirror should return to baseline.',
        );
        $this->assertOnlineLedgerBalanced();
        $this->assertSoftDeleted($tx);
    }

    public function test_delete_partial_payment_booking_restores_remaining_debt(): void
    {
        $customer = $this->makeCustomer('عميل حذف ٢', '0100B');

        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'customer_phone' => $customer->phone,
            'purchase_price' => 50,
            'selling_price' => 300,
            'amount_paid' => 80,  // partial → 220 debt
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'reference_number' => 'SD-2',
        ]);

        $customerAccount = Account::find($customer->account_id);
        $debtBeforeCancel = $this->glBalance($customerAccount->id);
        $this->assertEqualsWithDelta(220.0, $debtBeforeCancel, 0.01);

        $this->service->delete($tx);

        $this->assertEqualsWithDelta(
            0.0,
            $this->glBalance($customerAccount->id),
            0.01,
            'After cancelling a partial-payment tx, the customer AR mirror should be back to 0 (the sale income is reversed).',
        );
        $this->assertOnlineLedgerBalanced();
    }

    public function test_delete_is_idempotent_second_call_is_noop(): void
    {
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_name' => 'عميل حذف ٣',
            'customer_phone' => '0100C',
            'purchase_price' => 0,
            'selling_price' => 200,
            'amount_paid' => 200,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'reference_number' => 'SD-3',
        ]);

        $this->service->delete($tx);
        $vaultAfterFirst = $this->accountBalance($this->cashbox->id);

        // Second call: must NOT change anything.
        $result = $this->service->delete($tx);
        $this->assertTrue($result, 'second delete() must return true (no-op)');
        $this->assertEqualsWithDelta(
            $vaultAfterFirst,
            $this->accountBalance($this->cashbox->id),
            0.01,
            'second delete() must not re-reverse the GL.',
        );
        $this->assertOnlineLedgerBalanced();
    }

    public function test_direct_delete_outside_service_throws(): void
    {
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_name' => 'عميل حذف ٤',
            'customer_phone' => '0100D',
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'reference_number' => 'SD-4',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/لا يمكن حذف معاملات الخدمات الإلكترونية/');

        // Direct call outside the service must throw.
        OnlineTransaction::run(function () use ($tx) {
            // Wrap in `run` to ensure the guard is at the lowest level: even
            // inside run(), a direct $tx->delete() outside the canonical
            // service flow should be checked. We do NOT call run() here —
            // we verify the guard outside run().
        });
        $tx->delete();
    }

    public function test_cancelled_row_invisible_from_default_index_visible_with_trashed(): void
    {
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_name' => 'عميل حذف ٥',
            'customer_phone' => '0100E',
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'reference_number' => 'SD-5',
        ]);

        $this->service->delete($tx);

        $this->assertNull(OnlineTransaction::find($tx->id), 'default scope must hide cancelled row');
        $this->assertNotNull(OnlineTransaction::withTrashed()->find($tx->id), 'withTrashed must surface cancelled row for audit');
    }

    public function test_cancellation_stamps_audit_fields(): void
    {
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_name' => 'عميل حذف ٦',
            'customer_phone' => '0100F',
            'purchase_price' => 0,
            'selling_price' => 50,
            'amount_paid' => 50,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'reference_number' => 'SD-6',
        ]);

        $this->service->delete($tx);

        $row = DB::table('online_transactions')->where('id', $tx->id)->first();
        $this->assertSame(OnlineTransactionStatus::Cancelled->value, $row->status);
        $this->assertNotNull($row->cancelled_at);
        $this->assertSame($this->user->id, (int) $row->cancelled_by);
        $this->assertNotNull($row->deleted_at);
    }

    public function test_walk_in_overpayment_reclaim_returns_money_to_vault(): void
    {
        $clearing = app(LedgerClearingAccounts::class);
        $walkInArId = $clearing->onlineWalkInArAccountId();
        $vaultStart = $this->cashbox->balance;

        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_name' => 'Walkin Overpay',
            'customer_phone' => '0100G',
            'purchase_price' => 0,
            'selling_price' => 500,
            'amount_paid' => 500,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'reference_number' => 'SD-7',
        ]);

        // Simulate the customer paying extra via the generic
        // CustomerController::payDebt flow (which is what happens in real
        // usage for registered walk-in clients). The pay-debt entry has no
        // related_id, so the cancel reversal won't see it — the walk-in AR
        // mirror will keep a residual credit, which our reclamation step
        // must convert to a vault credit memo.
        $this->service->update($tx, ['amount_paid' => 600.0]);

        // Manually post a 100 "extra payment" from the walk-in AR mirror
        // back to the vault, mirroring the CustomerController::payDebt path.
        app(\App\Services\Finance\TransactionService::class)->recordJournalTransfer([
            'amount' => 100.0,
            'from_account_id' => $walkInArId,
            'to_account_id' => $this->cashbox->id,
            'module' => 'online',
            'related_type' => null,  // generic, not tied to this tx
            'related_id' => null,
            'notes' => 'محاكاة دفع إضافي عبر CustomerController::payDebt',
            'created_by' => $this->user->id,
        ]);

        $vaultBeforeCancel = $this->accountBalance($this->cashbox->id);

        $this->service->delete($tx);

        $vaultAfterCancel = $this->accountBalance($this->cashbox->id);

        // After cancel: the original cash should be returned (500 in,
        // 500 out via the AR-into-vault flow reversal) and the 100 "extra"
        // should stay in the vault. Net Δ = 0.
        $this->assertEqualsWithDelta(
            $vaultStart,
            $vaultAfterCancel,
            0.01,
            "Walk-in AR reclamation must return the overpayment to the vault (Δ={$vaultAfterCancel} - {$vaultStart}).",
        );
        $this->assertOnlineLedgerBalanced();
    }
}
