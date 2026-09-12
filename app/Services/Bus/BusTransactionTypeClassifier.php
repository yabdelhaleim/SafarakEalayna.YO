<?php

namespace App\Services\Bus;

use App\Enums\TransactionType;
use App\Models\Transaction;

class BusTransactionTypeClassifier
{
    /**
     * Return the semantic transaction type, or null when the row cannot be
     * classified confidently.
     *
     * The notes are the most specific signal. Account direction is used as a
     * fallback for generic or empty notes.
     */
    public function classify(Transaction $transaction): ?string
    {
        $notes = (string) ($transaction->notes ?? '');

        $transaction->loadMissing(['fromAccount:id,name', 'toAccount:id,name']);

        $toName = (string) ($transaction->toAccount?->name ?? '');
        $fromName = (string) ($transaction->fromAccount?->name ?? '');

        if (preg_match('/(عكس مديونية|حذف مديونية|إلغاء مديونية)/u', $notes)) {
            return TransactionType::Refund->value;
        }

        if (preg_match('/(عكس تكلفة|حذف تكلفة|إلغاء تكلفة)/u', $notes)) {
            return TransactionType::Expense->value;
        }

        if (preg_match('/استرداد/u', $notes)) {
            return TransactionType::Refund->value;
        }

        if (preg_match('/تسديد/u', $notes)) {
            return TransactionType::Transfer->value;
        }

        if (preg_match('/تحصيل/u', $notes)) {
            return TransactionType::Income->value;
        }

        if (preg_match('/تكلفة/u', $notes)) {
            return TransactionType::Expense->value;
        }

        if (preg_match('/مصروف/u', $notes)) {
            return TransactionType::Expense->value;
        }

        if (preg_match('/حجز تذكرة باص للعميل/u', $notes)) {
            return TransactionType::Income->value;
        }

        if (preg_match('/عكس.*تحصيل/u', $notes)) {
            return TransactionType::Refund->value;
        }

        if (
            str_contains($fromName, 'إقفال إيرادات') &&
            ! str_contains($toName, 'إقفال إيرادات')
        ) {
            return TransactionType::Income->value;
        }

        if (
            str_contains($toName, 'إقفال إيرادات') &&
            ! str_contains($fromName, 'إقفال إيرادات')
        ) {
            return TransactionType::Refund->value;
        }

        if (
            str_contains($toName, 'إقفال تكاليف') ||
            str_contains($toName, 'إقفال تكلفة')
        ) {
            return TransactionType::Expense->value;
        }

        if (
            str_contains($fromName, 'إقفال تكاليف') ||
            str_contains($fromName, 'إقفال تكلفة')
        ) {
            return TransactionType::Expense->value;
        }

        return null;
    }
}
