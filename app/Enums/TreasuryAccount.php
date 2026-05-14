<?php

namespace App\Enums;

enum TreasuryAccount: string
{
    case TREASURY_POST_JAARI = 'TREASURY_POST_JAARI';
    case TREASURY_POST_FADDI = 'TREASURY_POST_FADDI';
    case TREASURY_POST_YAWM = 'TREASURY_POST_YAWM';
    case TREASURY_SAVING_YASSER = 'TREASURY_SAVING_YASSER';
    case TREASURY_CASH_EGP = 'TREASURY_CASH_EGP';
    case TREASURY_CASH_KWD = 'TREASURY_CASH_KWD';
    case TREASURY_CASH_SAR = 'TREASURY_CASH_SAR';
    case TREASURY_CASH_USD = 'TREASURY_CASH_USD';
    case TREASURY_BANK_MISR_SAFARK = 'TREASURY_BANK_MISR_SAFARK';
    case TREASURY_BANK_MISR_YASSER = 'TREASURY_BANK_MISR_YASSER';
    case TREASURY_BANK_ALEX = 'TREASURY_BANK_ALEX';
    case TREASURY_BANK_AHLY = 'TREASURY_BANK_AHLY';
    case TREASURY_WALLET_YASSER = 'TREASURY_WALLET_YASSER';
    case TREASURY_WALLET_YASSER2 = 'TREASURY_WALLET_YASSER2';
    case TREASURY_WALLET_ARAFA = 'TREASURY_WALLET_ARAFA';

    public function label(): string
    {
        return match($this) {
            self::TREASURY_POST_JAARI => 'سفرك علينا - جاري (بريد)',
            self::TREASURY_POST_FADDI => 'سفرك علينا - فضي (بريد)',
            self::TREASURY_POST_YAWM => 'سفرك علينا - يوم بيوم (بريد)',
            self::TREASURY_SAVING_YASSER => 'ياسر محمود - توفير (بريد)',
            self::TREASURY_CASH_EGP => 'نقدي مصري',
            self::TREASURY_CASH_KWD => 'نقدي دينار',
            self::TREASURY_CASH_SAR => 'نقدي ريال',
            self::TREASURY_CASH_USD => 'نقدي دولار',
            self::TREASURY_BANK_MISR_SAFARK => 'بنك مصر / سفرك علينا',
            self::TREASURY_BANK_MISR_YASSER => 'بنك مصر / ياسر محمود',
            self::TREASURY_BANK_ALEX => 'بنك اسكندرية / عرفه أحمد',
            self::TREASURY_BANK_AHLY => 'بنك أهلي / سفرك علينا',
            self::TREASURY_WALLET_YASSER => 'ياسر 01024607766 (محفظة)',
            self::TREASURY_WALLET_YASSER2 => 'ياسر محمود 01070985102 (محفظة)',
            self::TREASURY_WALLET_ARAFA => 'عرفه أحمد 01070985101 (محفظة)',
        };
    }

    public function currency(): string
    {
        return match($this) {
            self::TREASURY_CASH_KWD => 'KWD',
            self::TREASURY_CASH_SAR => 'SAR',
            self::TREASURY_CASH_USD => 'USD',
            default => 'EGP',
        };
    }
}
