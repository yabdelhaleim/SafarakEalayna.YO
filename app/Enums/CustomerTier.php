<?php

namespace App\Enums;

enum CustomerTier: string
{
    case STANDARD = 'STANDARD';
    case PREMIUM = 'PREMIUM';
    case VIP = 'VIP';
    case AGENT = 'AGENT';

    public function label(): string
    {
        return match($this) {
            self::STANDARD => 'عميل عادي',
            self::PREMIUM => 'عميل مميز',
            self::VIP => 'عميل VIP ⭐️',
            self::AGENT => 'وكيل',
        };
    }
}
