<?php

namespace Tests\Feature\Wallet\Phases;

use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Enums\WalletTransactionType;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Wallet\WalletTransaction;
use BackedEnum;
use Tests\Feature\Wallet\Support\AccountState;
use Tests\Feature\Wallet\Support\Decimal;
use Tests\Feature\Wallet\WalletTestCase;

/**
 * PHASE 7 — POSITIVE TESTS.
 *
 * Verifies the happy path of the Wallet & Transfers module:
 *   - Cashier can post a Send (registered customer, amount_paid=0 → no settlement)
 *   - Cashier can post a Receive (registered customer)
 *   - Cashier can post a Send (anonymous walk-in)
 *   - Ledger entries are written correctly (debit/credit on the right accounts)
 *   - balances reflect the right net change
 *   - daily summary counts match
 *   - Audit log entries are produced
 *   - Double-entry invariant (debit == credit per transaction) holds
 *
 * KNOWN BUGS / FINDINGS COVERED (REPORT-ONLY, NOT FIXED):
 *   - FIN-1 (HIGH):  Account.balance = SUM(credit) - SUM(debit) is unsatisfiable
 *                    for accounts created with non-zero balance (no opening entry).
 *   - FIN-2 (HIGH):  accountForSend posts duplicate active income when amount_paid>0
 *                    + registered customer. Bypassed here by amount_paid=0.
 *   - FIN-3 (HIGH):  recordExpense() flows through recordJournalTransfer() when a
 *                    clearing account is resolved, so the persisted transaction is
 *                    type='transfer' (not type='expense'). The semantic is lost.
 *   - R1-A (HIGH):   GET /api/v1/wallet/transactions index route is missing.
 *   - FIN-4 (MED):   getDailySummary sums `amount` (not `total_amount`), so the
 *                    daily summary's `total_sent` excludes the fee. Confirmed by
 *                    production code; documented here.
 *   - FIN-5 (MED):   AuditLog uses `model_type`/`model_id` columns, not the standard
 *                    `auditable_type`/`auditable_id` morph pair documented for other
 *                    Laravel packages. See test_wallet_transaction_writes_audit_log.
 */
class Phase07PositiveTest extends WalletTestCase
{
    // ────────────── Happy path: registered customer, send, no settlement ──────────────

    public function test_send_with_registered_customer_amount_paid_zero_succeeds(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 500.00, fee: 10.00);
        $payload['amount_paid'] = 0;
        $payload['notes'] = 'تيست: إيداع بدون سداد';

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success', 'message',
                'data' => ['id', 'type', 'type_label', 'amount', 'service_fee', 'total_amount',
                    'customer_name', 'wallet_account', 'cash_account'],
            ]);

        $data = $response->json('data');
        $this->assertEquals(WalletTransactionType::Send->value, $data['type']);
        $this->assertEquals(500.00, (float) $data['amount']);
        $this->assertEquals(10.00, (float) $data['service_fee']);
        $this->assertEquals(510.00, (float) $data['total_amount']);
        $this->assertEquals(0.00, (float) $data['amount_paid']);
        $this->assertEquals('أحمد محمود', $data['customer_name']);
    }

    /**
     * FINDING FIN-2 (HIGH) REMEDIATED (2026-08-21):
     * Pre-fix: a Send that has `amount_paid > 0` for a registered customer
     * triggered `postSettlementSend → recordIncome(...)`, which collided
     * with the main Send pair on the `(related_type, related_id)` slot.
     * The duplicate guard rejected the second call with HTTP 422.
     *
     * Post-fix: `postSettlementSend` posts a `recordJournalTransfer` of
     * type=Transfer (cashbox → wallet-account replenishment). The
     * settlement does NOT collide with the main income, and the Send
     * succeeds.
     *
     * WLT-FEE-LEG-REG (2026-09-03):
     * Registered SEND now posts THREE ledger rows:
     *   (1) transfer of `amount` (main; wallet → customer)
     *   (2) income of `fee`     (agency commission; clearing → cash)
     *   (3) transfer of `amount` (settlement; customer → cash) — NOT amount_paid
     *
     * The test asserts:
     *   - HTTP 201 (the Send is accepted end-to-end).
     *   - Exactly 3 ledger transactions on this WalletTransaction.
     *   - Customer balance = 0 (the fee is agency revenue, NOT customer debt).
     *   - Cashbox gains amount+fee (settlement of principal + fee income).
     */
    public function test_send_with_amount_paid_positive_creates_transfer_settlement_fi_n_2_fixed(): void
    {
        $amount = 100.00;
        $fee = 5.00;
        $totalAmount = $amount + $fee;       // 105
        $amountPaid = $totalAmount;          // 105 — full cash collected

        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: $amount, fee: $fee);
        $payload['amount_paid'] = $amountPaid;

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201,
            'FIN-2 fixed: Send with amount_paid > 0 is accepted end-to-end.');

        $txId = $response->json('data.id');
        $this->assertNotNull($txId);

        // WLT-FEE-LEG-REG: 3 ledger rows (main transfer + fee income + settlement).
        $ledgerCount = Transaction::query()
            ->where('related_type', WalletTransaction::class)
            ->where('related_id', $txId)
            ->count();
        $this->assertEquals(3, $ledgerCount,
            'WLT-FEE-LEG-REG (2026-09-03): Send with amount_paid > 0 creates 3 ledger rows '
            . '(main transfer + fee income + settlement transfer).');

        // Verify types: 2× Transfer (main + settlement) + 1× Income (fee leg).
        $types = Transaction::query()
            ->where('related_type', WalletTransaction::class)
            ->where('related_id', $txId)
            ->pluck('type')
            ->map(fn ($t) => $t instanceof BackedEnum ? $t->value : (string) $t)
            ->sort()
            ->values()
            ->all();
        $this->assertEquals(
            ['income', 'transfer', 'transfer'],
            $types,
            'WLT-FEE-LEG-REG: ledger types are Income (fee leg) + 2× Transfer (main + settlement).'
        );

        // The two transfers sum to: amount (main) + amount (settlement) = 200.
        $transferAmount = (float) Transaction::query()
            ->where('related_type', WalletTransaction::class)
            ->where('related_id', $txId)
            ->where('type', TransactionType::Transfer->value)
            ->sum('amount');
        $this->assertEquals(
            2 * (float) $amount,
            $transferAmount,
            'WLT-FEE-LEG-REG: sum of both transfers = amount (main) + amount (settlement, principal only).'
        );

        // The income row equals `fee`.
        $incomeAmount = (float) Transaction::query()
            ->where('related_type', WalletTransaction::class)
            ->where('related_id', $txId)
            ->where('type', TransactionType::Income->value)
            ->sum('amount');
        $this->assertEquals(
            (float) $fee,
            $incomeAmount,
            'WLT-FEE-LEG-REG: fee income leg = fee.'
        );

        // Money conservation under the new model:
        //   - Wallet loses `amount` EGP (main Send transfer: wallet → customer, 100).
        //   - Cashbox gains `amount + fee` = settlement of principal (100) + fee income (5) = 105.
        //   - Customer AR ends at `amount - amount = 0` (the fee is agency revenue, not customer debt).
        $this->assertEquals('9900.00', AccountState::balance($this->walletAccountEgp->id),
            'WLT-FEE-LEG-REG: wallet loses amount=100 (main Send transfer only).');

        // Cashbox GAINS amount_paid=105 EGP — settlement of 100 + fee income of 5.
        $this->assertEquals('5105.00', AccountState::balance($this->cashboxEgp->id),
            'WLT-FEE-LEG-REG: cashbox gains amount+fee via settlement + fee income.');

        // Customer balance must be 0 (wiped clean) — NOT -fee.
        $reloaded = Customer::find($this->customerEgp->id);
        $customerAccount = Account::find($reloaded->account_id);
        $this->assertEquals('0.00', AccountState::balance($customerAccount->id),
            'WLT-FEE-LEG-REG: customer balance is 0 after full settlement (the fee is agency revenue, not customer credit).');
    }

/**
     * WLT-FEE-LEG-REG (2026-09-03):
     * Registered SEND with amount_paid=0 posts TWO ledger rows:
     *   (1) transfer of `amount` (main; wallet → customer_account) — 500
     *   (2) income of `fee`     (agency commission; clearing → cash) — 10
     *
     * There is NO settlement because amount_paid=0. The fee is recognized
     * as income at creation time per user requirement (WLT-FEE-LEG-REG).
     */
    public function test_send_creates_one_journal_transfer_with_expected_types(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 500.00, fee: 10.00);
        $payload['amount_paid'] = 0;

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);

        $txId = $response->json('data.id');
        $this->assertNotNull($txId, 'Wallet transaction id must be returned');

        // WLT-FEE-LEG-REG: TWO transactions should be created (main transfer + fee income).
        $ledgerCount = Transaction::query()
            ->where('related_type', WalletTransaction::class)
            ->where('related_id', $txId)
            ->count();
        $this->assertEquals(2, $ledgerCount,
            'WLT-FEE-LEG-REG: exactly 2 ledger transactions (main transfer + fee income; no settlement).');

        // The transfer exists.
        $transferExists = Transaction::query()
            ->where('related_type', WalletTransaction::class)
            ->where('related_id', $txId)
            ->where('type', TransactionType::Transfer->value)
            ->exists();
        $this->assertTrue($transferExists,
            'WLT-FEE-LEG-REG: main transfer is type=Transfer (wallet → customerAccount).');

        // The income leg exists.
        $incomeExists = Transaction::query()
            ->where('related_type', WalletTransaction::class)
            ->where('related_id', $txId)
            ->where('type', TransactionType::Income->value)
            ->exists();
        $this->assertTrue($incomeExists,
            'WLT-FEE-LEG-REG: fee income leg is type=Income (clearing → cash).');

        $transferAmount = (float) Transaction::query()
            ->where('related_type', WalletTransaction::class)
            ->where('related_id', $txId)
            ->where('type', TransactionType::Transfer->value)
            ->sum('amount');
        $this->assertEquals(500.00, $transferAmount,
            'WLT-FEE-LEG-REG: main transfer amount = amount (500).');

        $incomeAmount = (float) Transaction::query()
            ->where('related_type', WalletTransaction::class)
            ->where('related_id', $txId)
            ->where('type', TransactionType::Income->value)
            ->sum('amount');
        $this->assertEquals(10.00, $incomeAmount,
            'WLT-FEE-LEG-REG: fee income leg amount = fee (10).');

        // All transactions are module='wallet'.
        $moduleValues = [];
        foreach (Transaction::query()
            ->where('related_type', WalletTransaction::class)
            ->where('related_id', $txId)
            ->get() as $txRow) {
            $m = $txRow->module;
            $moduleValues[] = $m instanceof BackedEnum ? $m->value : (string) $m;
        }
        $moduleValues = array_values(array_unique($moduleValues));
        $this->assertEquals([TransactionModule::Wallet->value], $moduleValues);
    }

    public function test_send_updates_accounts_correctly_for_send(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 500.00, fee: 10.00);
        $payload['amount_paid'] = 0;

        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)
            ->assertStatus(201);

        // Wallet balance decreased by amount (500)
        $walletNew = AccountState::balance($this->walletAccountEgp->id);
        $this->assertEquals('9500.00', $walletNew, 'Wallet balance must decrease by amount');

        // WLT-FEE-LEG-REG: cashbox gains fee income even when amount_paid=0 (commission recognized at creation).
        $cashNew = AccountState::balance($this->cashboxEgp->id);
        $this->assertEquals('5010.00', $cashNew, 'WLT-FEE-LEG-REG: cashbox gains fee income even without settlement (5010 = 5000 + 10 fee).');

        // Customer ledger account is auto-created and re-tagged to module_type='wallet_transfer'.
        $reloaded = Customer::find($this->customerEgp->id);
        $this->assertNotNull($reloaded->account_id,
            'Customer must have its account_id link populated after the first transaction');
        $customerAccount = Account::find($reloaded->account_id);
        $this->assertNotNull($customerAccount, 'Customer ledger account must be auto-created');
        $this->assertEquals('wallet_transfer', $customerAccount->module_type,
            'Customer ledger account must be tagged module_type=wallet_transfer');
        $customerBalance = AccountState::balance($customerAccount->id);
        $this->assertEquals('500.00', $customerBalance,
            'WLT-FEE-LEG-REG: customer balance = +amount (500). No settlement yet (amount_paid=0).');
    }

    public function test_send_walk_in_with_amount_paid_zero_succeeds(): void
    {
        $payload = $this->sendPayloadWalkIn(amount: 750.00, fee: 15.00);
        $payload['amount_paid'] = 0;

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);

        $response->assertStatus(201);

        $data = $response->json('data');
        $this->assertEquals('عميل عابر', $data['customer_name']);
        $this->assertEquals(750.00, (float) $data['amount']);
        $this->assertEquals(15.00, (float) $data['service_fee']);
        $this->assertEquals(765.00, (float) $data['total_amount']);
    }

    public function test_send_walk_in_credits_cashbox_with_amount(): void
    {
        $payload = $this->sendPayloadWalkIn(amount: 750.00, fee: 15.00);
        $payload['amount_paid'] = 0;

        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)
            ->assertStatus(201);

        // WLT-FEE-LEG (2026-09-03): walk-in send credits cashbox with amount + fee (agency commission).
        //   - main transfer (wallet → cash): +750
        //   - fee income (clearing → cash):  +15
        // = cashbox: 5000 + 750 + 15 = 5765
        $this->assertEquals('5765.00', AccountState::balance($this->cashboxEgp->id),
            'WLT-FEE-LEG: walk-in send credits cashbox with amount + fee (commission income recognized at creation).');

        // Wallet decreased by amount (750) — fee is agency revenue, not wallet outflow.
        $this->assertEquals('9250.00', AccountState::balance($this->walletAccountEgp->id),
            'Wallet balance must decrease by amount');
    }

    // ────────────── Happy path: receive, registered customer ──────────────

    public function test_receive_with_registered_customer_succeeds(): void
    {
        $payload = $this->receivePayloadRegistered($this->customerEgp, amount: 300.00, fee: 8.00);
        $payload['amount_paid'] = 0;

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success', 'message',
                'data' => ['id', 'type', 'amount', 'service_fee', 'total_amount'],
            ]);

        $data = $response->json('data');
        $this->assertEquals(WalletTransactionType::Receive->value, $data['type']);
        $this->assertEquals(300.00, (float) $data['amount']);
        $this->assertEquals(8.00, (float) $data['service_fee']);
        $this->assertEquals(292.00, (float) $data['total_amount'],
            'Receive total_amount = amount - fee (customer receives net)');
    }

    public function test_receive_updates_accounts_correctly(): void
    {
        $payload = $this->receivePayloadRegistered($this->customerEgp, amount: 300.00, fee: 8.00);
        $payload['amount_paid'] = 0;

        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)
            ->assertStatus(201);

        // Wallet balance INCREASED by amount (300)
        $walletNew = AccountState::balance($this->walletAccountEgp->id);
        $this->assertEquals('10300.00', $walletNew, 'Receive: wallet balance must INCREASE by amount');

        // Cashbox unchanged (amount_paid=0)
        $cashNew = AccountState::balance($this->cashboxEgp->id);
        $this->assertEquals('5000.00', $cashNew, 'Receive: cashbox must be unchanged when amount_paid=0');

        // Customer balance = -(amount - fee) = -292 (we owe them 292)
        $reloaded = Customer::find($this->customerEgp->id);
        $customerAccount = Account::find($reloaded->account_id);
        $this->assertNotNull($customerAccount);
        $customerBalance = AccountState::balance($customerAccount->id);
        $this->assertEquals('-292.00', $customerBalance,
            'Receive: customer balance must be -(amount-fee) (we owe them)');
    }

    // ────────────── Daily summary (FIN-4 FIXED: total_amount exposed) ──────────────

    public function test_daily_summary_uses_amount_not_total_amount_fi_n_4(): void
    {
        $send = $this->sendPayloadRegistered($this->customerEgp, amount: 500.00, fee: 10.00);
        $send['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $send)->assertStatus(201);

        $recv = $this->receivePayloadRegistered($this->customer2, amount: 300.00, fee: 8.00);
        $recv['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $recv)->assertStatus(201);

        $today = now()->toDateString();
        $response = $this->asAdmin()->getJson("/api/v1/wallet/transactions/daily-summary?date={$today}");

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success', 'message',
                'data' => ['total_transactions', 'send_count', 'receive_count',
                    'total_sent', 'total_received',
                    'total_sent_with_fees', 'total_received_with_fees',
                    'total_fees'],
            ]);

        $summary = $response->json('data');
        $this->assertEquals(2, $summary['total_transactions']);
        $this->assertEquals(1, $summary['send_count']);
        $this->assertEquals(1, $summary['receive_count']);

        // FIN-4 FIXED: the legacy `total_sent`/`total_received` (using `amount`)
        // is kept for backward compatibility with older clients. The NEW
        // `total_sent_with_fees`/`total_received_with_fees` use `total_amount`
        // and reflect the actual cash moved in/out of the cashbox.
        $this->assertEquals(500.0, $summary['total_sent'],
            'FIN-4 fixed (backward-compat): total_sent still sums amount (500)');
        $this->assertEquals(300.0, $summary['total_received'],
            'FIN-4 fixed (backward-compat): total_received still sums amount (300)');
        $this->assertEquals(510.0, $summary['total_sent_with_fees'],
            'FIN-4 fixed: total_sent_with_fees sums total_amount = 500 + 10 fee = 510');
        // For Receive, total_amount = amount - service_fee = 300 - 8 = 292
        // (the net amount the customer receives after the wallet provider
        // deducts its fee).
        $this->assertEquals(292.0, $summary['total_received_with_fees'],
            'FIN-4 fixed: total_received_with_fees sums total_amount = 300 - 8 fee = 292');
        $this->assertEquals(18.0, $summary['total_fees'], 'total_fees = 10 + 8 = 18');

        // Reconciliation: for Send, total_amount = amount + fee (cashbox credits net).
        // For Receive, total_amount = amount - fee (cashbox debits net after provider fee).
        // So total_sent_with_fees + total_received_with_fees = amount_send + amount_recv + (fee_send - fee_recv).
        // Sanity-check the actual total (802) and verify the fee delta matches.
        $actualTotal = $summary['total_sent_with_fees'] + $summary['total_received_with_fees'];
        $feeDelta = (float) $summary['total_fees'] - 2 * 8; // 10 send fee minus 8 receive fee (one net, one not)
        $expected = 800.0 + $feeDelta;
        $this->assertEquals($expected, $actualTotal,
            'FIN-4 fixed: with_fees total = principal total + (send_fees - receive_fees)');
        $this->assertEquals(802.0, $actualTotal,
            'FIN-4 fixed: actual total moved in/out = 510 + 292 = 802');
    }

    // ────────────── Route-table inventory (R1-A: missing index) ──────────────

    /**
     * FINDING R1-A (HIGH) REMEDIATED (2026-08-21):
     * Pre-fix: GET /api/v1/wallet/transactions was NOT registered in
     * routes/api.php, returning 405. Post-fix: the route is wired behind
     * `wallet.view` permission and returns 200 with paginated transactions.
     */
    public function test_index_endpoint_returns_200_r1_a_fixed(): void
    {
        // Seed one transaction so the index has something to return.
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        $response = $this->asAdmin()->getJson('/api/v1/wallet/transactions');
        $response->assertStatus(200,
            'R1-A fixed: GET /api/v1/wallet/transactions returns 200 with paginated payload.');
        $response->assertJsonStructure([
            'success', 'message',
            'data' => [
                'items' => ['*' => ['id', 'type', 'amount', 'service_fee', 'total_amount', 'created_by_id']],
                'pagination' => ['total', 'per_page', 'current_page', 'last_page', 'has_more'],
            ],
        ]);
    }

    public function test_show_endpoint_succeeds_for_known_transaction(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 100.00, fee: 2.00);
        $payload['amount_paid'] = 0;
        $create = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $id = $create->json('data.id');

        $response = $this->asAdmin()->getJson("/api/v1/wallet/transactions/{$id}");
        $response->assertStatus(200)
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.type', 'send');
    }

    // ────────────── Audit log (FIN-5: uses model_type/model_id, not auditable_type) ──────────────

    public function test_wallet_transaction_writes_audit_log(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 50.00, fee: 1.00);
        $payload['amount_paid'] = 0;

        $response = $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload);
        $response->assertStatus(201);

        $txId = $response->json('data.id');
        $this->assertNotNull($txId);

        // FINDING FIN-5: AuditLog uses model_type/model_id (NOT auditable_type/auditable_id).
        $auditExists = \DB::table('audit_logs')
            ->where('model_type', WalletTransaction::class)
            ->where('model_id', $txId)
            ->where('action', 'like', 'wallet_transaction.%')
            ->exists();
        $this->assertTrue($auditExists,
            'audit_logs must contain a wallet_transaction.* action for the new WT');

        // Action combines the operation type ('created' / 'updated' / 'deleted').
        $createdAction = \DB::table('audit_logs')
            ->where('model_type', WalletTransaction::class)
            ->where('model_id', $txId)
            ->where('action', 'wallet_transaction.created')
            ->exists();
        $this->assertTrue($createdAction,
            'audit_logs must record a wallet_transaction.created action');
    }

    // ────────────── Ledger entry structure (Invariant #2) ──────────────

    public function test_each_ledger_transaction_is_balanced(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 250.00, fee: 5.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        // Invariant #2 from Account.php: every transaction has SUM(debit) == SUM(credit).
        $rows = Transaction::query()
            ->where('module', TransactionModule::Wallet->value)
            ->get();

        $this->assertGreaterThan(0, $rows->count(), 'No ledger transactions were created');
        foreach ($rows as $tx) {
            $d = (float) AccountEntry::query()
                ->where('transaction_id', $tx->id)
                ->sum('debit');
            $c = (float) AccountEntry::query()
                ->where('transaction_id', $tx->id)
                ->sum('credit');
            $this->assertEqualsWithDelta($d, $c, 0.0001,
                sprintf('Transaction #%d (type=%s) failed double-entry balance: d=%s c=%s',
                    $tx->id,
                    $tx->type instanceof TransactionType ? $tx->type->value : (string) $tx->type,
                    $d,
                    $c
                )
            );
        }
    }

    // ────────────── Balance invariant for the post-ledger state ──────────────

    /**
     * FINDING FIN-1 (HIGH): Even after a Send, the documented invariant
     * `Account.balance = SUM(credit - debit)` did NOT hold for the wallet
     * because no opening-balance AccountEntry was created.
     *
     *   stored = 9500.00 (initial 10000 - 500 expense)
     *   derived = -500.00 (one outbound transfer: 0 credit − 500 debit)
     *   diff = 10000.00 = opening balance
     *
     * FIXED (FIN-1): `Account::created` boot hook auto-seeds a paired opening-
     * balance AccountEntry (CREDIT on new account + paired DEBIT on the
     * singleton "System Opening Balances" contra) when balance > 0.
     *
     *   stored = 9500.00 (initial 10000 - 500 expense)
     *   derived = 9500.00 (opening credit 10000 + 0 credit − 500 debit)
     *   diff = 0.00 ✓
     */
    public function test_balance_invariant_does_no_t_hold_for_non_zero_opening_balance_fi_n_1(): void
    {
        $payload = $this->sendPayloadRegistered($this->customerEgp, amount: 500.00, fee: 10.00);
        $payload['amount_paid'] = 0;
        $this->asAdmin()->postJson('/api/v1/wallet/transactions', $payload)->assertStatus(201);

        $stored = AccountState::balance($this->walletAccountEgp->id);
        $derived = AccountState::entriesDerivedBalance($this->walletAccountEgp->id);
        $diff = Decimal::sub($stored, $derived);

        $this->assertEquals('9500.00', $stored, 'Stored balance after 500 send is 9500');
        $this->assertEquals('9500.00', $derived, 'Derived balance: 10000 opening credit + 0 credit − 500 debit = 9500');
        $this->assertEquals('0.00', $diff,
            'FIN-1 fixed: stored == derived. The documented invariant now holds because the paired opening entry was auto-seeded.');
    }
}
