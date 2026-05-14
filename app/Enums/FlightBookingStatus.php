<?php

namespace App\Enums;

enum FlightBookingStatus: string
{
    case PENDING = 'PENDING';
    case CONFIRMED = 'CONFIRMED';
    case CANCELLED = 'CANCELLED';
    case REFUNDED = 'REFUNDED';
    case PARTIALLY_REFUNDED = 'PARTIALLY_REFUNDED';

    public function label(): string
    {
        return match($this) {
            self::PENDING => 'قيد الانتظار',
            self::CONFIRMED => 'تم التأكيد',
            self::CANCELLED => 'تم الإلغاء',
            self::REFUNDED => 'تم الاسترداد',
            self::PARTIALLY_REFUNDED => 'مسترد جزئياً',
        };
    }

    public static function forDropdown(): array
    {
        return [
            self::PENDING->value => self::PENDING->label(),
            self::CONFIRMED->value => self::CONFIRMED->label(),
            self::CANCELLED->value => self::CANCELLED->label(),
            self::REFUNDED->value => self::REFUNDED->label(),
            self::PARTIALLY_REFUNDED->value => self::PARTIALLY_REFUNDED->label(),
        ];
    }
}
