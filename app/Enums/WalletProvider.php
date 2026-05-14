<?php

namespace App\Enums;

/**
 * نوع المحفظة الإلكترونية المرتبطة بحساب قيود (Account type = wallet).
 * يمكن تسجيل أكثر من حساب لنفس النوع مع أرقام مختلفة (مثلاً عدة فودافون كاش).
 */
enum WalletProvider: string
{
    case VodafoneCash = 'vodafone_cash';
    case Instapay = 'instapay';
    case EtisalatCash = 'etisalat_cash';
    case OrangeCash = 'orange_cash';
    case WePay = 'we_pay';
    case Paymob = 'paymob';
    case CashWalletGeneric = 'cash_wallet';
    case Postal = 'postal';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::VodafoneCash => 'فودافون كاش',
            self::Instapay => 'إنستاباي',
            self::EtisalatCash => 'اتصالات كاش',
            self::OrangeCash => 'أورانج كاش',
            self::WePay => 'WE Pay',
            self::Paymob => 'Paymob / بوابة دفع',
            self::CashWalletGeneric => 'محفظة كاش (عام)',
            self::Postal => 'بريد / مصاري',
            self::Other => 'أخرى',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function optionsForSelect(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
