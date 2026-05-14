<?php

namespace App\Enums;

enum BusPaymentStatus: string
{
    case Pending = 'pending';
    case Partial = 'partial';
    case Paid = 'paid';
    case Overdue = 'overdue';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'معلق',
            self::Partial => 'مدفوع جزئياً',
            self::Paid => 'مدفوع',
            self::Overdue => 'متأخر',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Partial => 'blue',
            self::Paid => 'success',
            self::Overdue => 'error',
        };
    }
}
