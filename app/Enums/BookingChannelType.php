<?php

namespace App\Enums;

enum BookingChannelType: string
{
    case SYSTEM = 'SYSTEM';
    case SIGN = 'SIGN';
    case GROUP = 'GROUP';

    public function label(): string
    {
        return match($this) {
            self::SYSTEM => 'نظام GDS / NDC',
            self::SIGN => 'بورصة / وكيل مباشر',
            self::GROUP => 'حجز مجموعة',
        };
    }
}
