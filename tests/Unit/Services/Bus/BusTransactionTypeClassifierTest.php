<?php

namespace Tests\Unit\Services\Bus;

use App\Enums\AccountType;
use App\Enums\TransactionType;
use App\Models\Account;
use App\Models\Transaction;
use App\Services\Bus\BusTransactionTypeClassifier;
use Tests\TestCase;

class BusTransactionTypeClassifierTest extends TestCase
{
    private function tx(string $notes, ?Account $from = null, ?Account $to = null): Transaction
    {
        $transaction = new Transaction;
        $transaction->notes = $notes;

        if ($from) {
            $transaction->setRelation('fromAccount', $from);
        }
        if ($to) {
            $transaction->setRelation('toAccount', $to);
        }

        return $transaction;
    }

    private function account(string $name, AccountType $type): Account
    {
        $account = new Account;
        $account->name = $name;
        $account->type = $type;

        return $account;
    }

    public function test_supplier_debt_settlement_is_transfer(): void
    {
        $classifier = app(BusTransactionTypeClassifier::class);

        $from = $this->account('خزينة المكتب (EGP)', AccountType::Cashbox);
        $to = $this->account('حساب شركة باصات: حورس باص', AccountType::Supplier);

        $transaction = $this->tx('تسديد دين شركة باصات', $from, $to);

        $this->assertSame(
            TransactionType::Transfer->value,
            $classifier->classify($transaction)
        );
    }

    public function test_generic_settlement_with_today_date_is_transfer(): void
    {
        $classifier = app(BusTransactionTypeClassifier::class);

        $from = $this->account('خزينة حورس باص', AccountType::Bank);
        $to = $this->account('حساب شركة باصات: حورس باص', AccountType::Supplier);

        $transaction = $this->tx('تسديد يوم 2 /8', $from, $to);

        $this->assertSame(
            TransactionType::Transfer->value,
            $classifier->classify($transaction)
        );
    }

    public function test_booking_cost_posting_is_expense(): void
    {
        $classifier = app(BusTransactionTypeClassifier::class);

        $from = $this->account('حساب شركة باصات: حورس باص', AccountType::Supplier);
        $to = $this->account('إقفال تكاليف الباصات', AccountType::Owner);

        $transaction = $this->tx('تكلفة حجز باص #123 — القاهرة - دار السلام', $from, $to);

        $this->assertSame(
            TransactionType::Expense->value,
            $classifier->classify($transaction)
        );
    }

    public function test_cost_reversal_is_expense(): void
    {
        $classifier = app(BusTransactionTypeClassifier::class);

        $from = $this->account('إقفال تكاليف الباصات', AccountType::Owner);
        $to = $this->account('حساب شركة باصات: حورس باص', AccountType::Supplier);

        $transaction = $this->tx('عكس تكلفة حجز باص #32 (حذف إداري شامل)', $from, $to);

        $this->assertSame(
            TransactionType::Expense->value,
            $classifier->classify($transaction)
        );
    }

    public function test_customer_collection_is_income(): void
    {
        $classifier = app(BusTransactionTypeClassifier::class);

        $from = $this->account('حساب عميل: 1', AccountType::Customer);
        $to = $this->account('خزينة المكتب (EGP)', AccountType::Cashbox);

        $transaction = $this->tx('تحصيل دفعة حجز باص #45', $from, $to);

        $this->assertSame(
            TransactionType::Income->value,
            $classifier->classify($transaction)
        );
    }

    public function test_cash_refund_is_refund(): void
    {
        $classifier = app(BusTransactionTypeClassifier::class);

        $from = $this->account('خزينة المكتب (EGP)', AccountType::Cashbox);
        $to = $this->account('حساب عميل: 1', AccountType::Customer);

        $transaction = $this->tx('استرداد حجز باص #45', $from, $to);

        $this->assertSame(
            TransactionType::Refund->value,
            $classifier->classify($transaction)
        );
    }
}
