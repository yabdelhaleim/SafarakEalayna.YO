<?php

namespace App\Enums;

enum FawryOperationType: string
{
    case Withdrawal = 'withdrawal';
    case Deposit = 'deposit';
    case Payment = 'payment';
    case TravelPermit = 'travel_permit';

    public function label(): string
    {
        return match ($this) {
            self::Withdrawal => 'سحب',
            self::Deposit => 'إيداع',
            self::Payment => 'سداد',
            self::TravelPermit => 'تصريح سفر',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::Withdrawal => 'Withdrawal',
            self::Deposit => 'Deposit',
            self::Payment => 'Payment',
            self::TravelPermit => 'Travel Permit',
        };
    }
}
