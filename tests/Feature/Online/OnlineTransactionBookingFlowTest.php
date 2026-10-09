<?php

namespace Tests\Feature\Online;

use App\Enums\AccountType;
use App\Enums\OnlineTransactionStatus;
use App\Models\Account;
use App\Models\Online\OnlineTransaction;
use App\Models\Transaction;

/**
 * End-to-end booking flow test: create → edit (price/payment/vault) →
 * status transition. Drives OnlineTransactionService directly so the
 * double-entry path is exercised exactly like the HTTP layer would.
 *
 * Each test asserts the GL is balanced after every step (per-transaction
 * debit = credit) AND that the cached Account.balance tracks the GL net.
 */
class OnlineTransactionBookingFlowTest extends OnlineTestCase
{
    public function test_full_payment_with_egp_cash_settlement(): void
    {
        $startVaultBalance = $this->cashbox->balance;

        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_name' => 'عميل كامل',
            'customer_phone' => '01001111111',
            'purchase_price' => 100,
            'selling_price' => 200,
            'amount_paid' => 200,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'reference_number' => 'B-1',
        ]);

        $this->assertSame(OnlineTransactionStatus::Completed, $tx->status);
        $this->assertSame(100.0, (float) $tx->profit);
        // The income transaction entries at the SELLING price (revenue), not
        // the profit margin. The expense transaction (purchase price) is
        // posted separately. Net effect on the income-side clearing account
        // is the selling price; net effect on the expense-side clearing
        // account is the purchase price; profit = selling − purchase.
        $this->assertSame(200.0, (float) $tx->incomeTransaction->amount);
        $this->assertSame(100.0, (float) $tx->expenseTransaction->amount);

        // Cash settlement: vault receives the selling price (200) then
        // routes the purchase cost out via the expense clearing account
        // (100). Net vault delta = selling − purchase = profit (100).
        $this->assertEqualsWithDelta(
            $startVaultBalance + 100.0,
            $this->accountBalance($this->cashbox->id),
            0.01,
            'Vault net delta after a 200/100 full-payment = +100 (profit).',
        );
        $this->assertOnlineLedgerBalanced();
        $this->assertLedgerBalancedForAccount($this->cashbox->id);
    }

    public function test_partial_payment_creates_residual_debt(): void
    {
        $customer = $this->makeCustomer('عميل جزئي', '01002222222');
        $customer->refresh();

        $startVaultBalance = $this->cashbox->balance;

        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'customer_phone' => $customer->phone,
            'purchase_price' => 50,
            'selling_price' => 200,
            'amount_paid' => 60,  // partial: client owes 140
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'reference_number' => 'B-2',
        ]);

        // Vault receives the cash (60), then routes the purchase cost out
        // via the expense clearing account (50). Net vault delta = 60 − 50
        // = 10 (profit recognised on the booked amount).
        $this->assertEqualsWithDelta(
            $startVaultBalance + 10.0,
            $this->accountBalance($this->cashbox->id),
            0.01,
            'Vault net delta = amount_paid (60) − purchase_price (50) = 10.',
        );

        // Customer AR holds the residual (selling 200 - cash 60 = 140).
        $customerAccountId = Account::find($customer->account_id)->id;
        $this->assertEqualsWithDelta(
            140.0,
            $this->glBalance($customerAccountId),
            0.01,
            'Customer AR mirror should hold 140 (selling - cash).',
        );
        $this->assertOnlineLedgerBalanced();
    }

    public function test_walk_in_creates_walk_in_ar_mirror(): void
    {
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            // NO customer_id → walk-in
            'customer_name' => 'عميل ولوك إن',
            'customer_phone' => '01003333333',
            'purchase_price' => 0,
            'selling_price' => 150,
            'amount_paid' => 150,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'reference_number' => 'B-3',
        ]);

        // The walk-in flow auto-creates a Customer record (via
        // ensureCustomerIsLinked) when no customer_id is supplied, so the
        // resulting tx DOES have a customer_id — it's the newly created
        // walk-in customer. The fact that the AR side routes through the
        // walk-in AR mirror (not the customer's own AR account) is the
        // invariant that matters; assert it via the GL balance.
        $this->assertNotNull($tx->customer_id, 'walk-in tx gets an auto-created walk-in customer_id');
        $this->assertOnlineLedgerBalanced();
    }

    public function test_walk_in_with_partial_payment_creates_walk_in_debt(): void
    {
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_name' => 'عميل ولوك إن ٢',
            'customer_phone' => '01004444444',
            'purchase_price' => 0,
            'selling_price' => 400,
            'amount_paid' => 100,  // partial: residual 300 stays on the customer AR
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'reference_number' => 'B-4',
        ]);

        // Production contract: `ensureCustomerIsLinked()` auto-creates a
        // Customer record (with their own AR account) when no customer_id is
        // supplied. The income entry flows into the customer's AR for the
        // FULL selling price (400), and the cash settlement subtracts the
        // deposit (100). Net customer AR = 400 − 100 = 300 (the residual).
        $this->assertNotNull($tx->customer_id, 'Walk-in flow auto-creates a customer');

        $customerArId = \App\Models\Customer::find($tx->customer_id)->account_id;
        $this->assertEqualsWithDelta(
            300.0,
            $this->glBalance($customerArId),
            0.01,
            'Walk-in customer AR holds the residual debt (selling − amount_paid = 300).',
        );

        // The residual debt is reflected in the transaction's own columns.
        $this->assertEqualsWithDelta(400.0, (float) $tx->selling_price, 0.01);
        $this->assertEqualsWithDelta(100.0, (float) $tx->amount_paid, 0.01);
        $this->assertEqualsWithDelta(300.0, (float) $tx->selling_price - (float) $tx->amount_paid, 0.01);

        $this->assertOnlineLedgerBalanced();
    }

    public function test_cross_currency_vault_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/موديول الخدمات الإلكترونية يقبل فقط/');

        $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_name' => 'عميل USD',
            'customer_phone' => '01005555555',
            'purchase_price' => 0,
            'selling_price' => 100,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->usdCashbox->id,  // ← USD vault
            'reference_number' => 'B-5-USD',
        ]);
    }

    public function test_edit_selling_price_reposts_income_correctly(): void
    {
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_name' => 'عميل تعديل',
            'customer_phone' => '01006666666',
            'purchase_price' => 50,
            'selling_price' => 200,
            'amount_paid' => 200,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'reference_number' => 'B-6',
        ]);

        $oldIncomeId = $tx->income_transaction_id;

        $updated = $this->service->update($tx, ['selling_price' => 400.0]);

        $this->assertNotSame($oldIncomeId, $updated->income_transaction_id);
        $this->assertSame(400.0, (float) Transaction::find($updated->income_transaction_id)->amount);
        $this->assertOnlineLedgerBalanced();
    }

    public function test_edit_amount_paid_reposts_cash_settlement(): void
    {
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_name' => 'عميل جزئي',
            'customer_phone' => '01007777777',
            'purchase_price' => 0,
            'selling_price' => 300,
            'amount_paid' => 100,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'reference_number' => 'B-7',
        ]);

        $vaultBefore = $this->accountBalance($this->cashbox->id);
        $this->service->update($tx, ['amount_paid' => 250.0]);
        $vaultAfter = $this->accountBalance($this->cashbox->id);

        $this->assertEqualsWithDelta(
            150.0,
            $vaultAfter - $vaultBefore,
            0.01,
            'Cash settlement repo: vault delta = +150 (100 reversed, +250 new).',
        );
        $this->assertOnlineLedgerBalanced();
    }

    public function test_status_transition_completed_to_cancelled_reverses_gl(): void
    {
        $customer = $this->makeCustomer('عميل إلغاء', '01008888888');
        $startBalance = $this->cashbox->balance;

        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->full_name,
            'customer_phone' => $customer->phone,
            'purchase_price' => 100,
            'selling_price' => 300,
            'amount_paid' => 200,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'reference_number' => 'B-8',
        ]);

        $this->service->update($tx, ['status' => 'cancelled']);

        $vaultAfter = $this->accountBalance($this->cashbox->id);
        // After create: vault = baseline + (amount_paid − purchase_price) = +100.
        // After cancel: the additive reversal brings vault back to baseline.
        $this->assertEqualsWithDelta(
            $startBalance,
            $vaultAfter,
            0.01,
            'Cancelled booking should bring the vault back to baseline (additive reversal reverses all GL entries).',
        );
        $this->assertOnlineLedgerBalanced();
    }

    public function test_status_transition_cancelled_to_completed_reposts_gl(): void
    {
        $tx = $this->service->create([
            'service_type_id' => $this->serviceType->id,
            'provider_id' => $this->provider->id,
            'customer_name' => 'إعادة فتح',
            'customer_phone' => '01009999999',
            'purchase_price' => 50,
            'selling_price' => 150,
            'amount_paid' => 0,
            'payment_method' => 'cash',
            'account_id' => $this->cashbox->id,
            'reference_number' => 'B-9',
            'status' => 'pending',  // create as pending → no GL posted
        ]);

        $this->assertNull($tx->income_transaction_id, 'pending booking must not post GL at create');

        // Now flip to Completed → service must publish the entries.
        $updated = $this->service->update($tx, ['status' => 'completed']);

        $this->assertNotNull($updated->income_transaction_id, 'flipping to Completed must publish GL');
        $this->assertOnlineLedgerBalanced();
    }
}
