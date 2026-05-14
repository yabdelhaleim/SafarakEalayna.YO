<?php

namespace App\Enums;

enum Currency: string
{
    case EGP = 'EGP'; // Egyptian Pound
    case KWD = 'KWD'; // Kuwaiti Dinar
    case SAR = 'SAR'; // Saudi Riyal
    case USD = 'USD'; // US Dollar

    public function getName(): string
    {
        return match ($this) {
            self::EGP => 'جنيه مصري',
            self::KWD => 'دينار كويتي',
            self::SAR => 'ريال سعودي',
            self::USD => 'دولار أمريكي',
        };
    }

    public function getSymbol(): string
    {
        return match ($this) {
            self::EGP => 'ج.م',
            self::KWD => 'د.ك',
            self::SAR => 'ر.س',
            self::USD => '$',
        };
    }
}
