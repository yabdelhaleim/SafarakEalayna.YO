<?php

namespace App\Enums;

enum TransactionModule: string
{
    case Flight = 'flight';
    case Bus = 'bus';
    case Wallet = 'wallet';
    case Online = 'online';
    case Fawry = 'fawry';
    case HajjUmra = 'hajj_umra';
    case Visa = 'visa';
    case Tourism = 'tourism';
    case Office = 'office';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Flight => 'حجوزات الطيران',
            self::Bus => 'حجوزات الباصات',
            self::Wallet => 'المحافظ والتحويلات',
            self::Online => 'الخدمات الإلكترونية',
            self::Fawry => 'فوري',
            self::HajjUmra => 'الحج والعمرة',
            self::Visa => 'التأشيرات',
            self::Tourism => 'السياحة',
            self::Office => 'المكتب',
            self::General => 'عام',
        };
    }
}
