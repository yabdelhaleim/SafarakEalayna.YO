<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Paid = 'paid';
    case PartiallyPaid = 'partially_paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Paid => 'Paid',
            self::PartiallyPaid => 'Partially Paid',
            self::Overdue => 'Overdue',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'gray',
            self::Sent => 'blue',
            self::Paid => 'green',
            self::PartiallyPaid => 'yellow',
            self::Overdue => 'red',
            self::Cancelled => 'gray',
        };
    }

    public function canBePaid(): bool
    {
        return in_array($this, [self::Sent, self::PartiallyPaid, self::Overdue]);
    }

    public function canBeCancelled(): bool
    {
        return in_array($this, [self::Draft, self::Sent]);
    }
}
