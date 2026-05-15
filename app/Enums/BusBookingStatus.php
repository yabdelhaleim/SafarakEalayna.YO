<?php

namespace App\Enums;

enum BusBookingStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';
    case PartiallyRefunded = 'partially_refunded';

    public function label(): string
    {
        return match($this) {
            self::Pending => 'قيد الانتظار',
            self::Paid => 'مدفوع',
            self::Cancelled => 'تم الإلغاء',
            self::Refunded => 'تم الاسترداد',
            self::PartiallyRefunded => 'مسترد جزئياً',
        };
    }
}
