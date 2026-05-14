<?php

namespace App\Enums;

enum CustomerTier: string
{
    case STANDARD = 'STANDARD';
    case PREMIUM = 'PREMIUM';

    public function label(): string
    {
        return match($this) {
            self::STANDARD => 'عميل عادي',
            self::PREMIUM => 'عميل مميز',
        };
    }
}
