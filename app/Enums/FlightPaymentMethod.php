<?php

namespace App\Enums;

enum FlightPaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case CashWallet = 'cash_wallet';
    case PostalTransfer = 'postal_transfer';
    case OfficeSafe = 'office_safe';
    case OfficeDrawer = 'office_drawer';
    case Mixed = 'mixed';
    case VodafoneCash = 'vodafone_cash';
    case Instapay = 'instapay';

    public function label(): string
    {
        return match($this) {
            self::Cash => 'نقدي مصري',
            self::BankTransfer => 'بنك',
            self::CashWallet => 'محفظة كاش',
            self::PostalTransfer => 'بريد',
            self::OfficeSafe => 'خزينة المكتب',
            self::OfficeDrawer => 'درج المكتب',
            self::Mixed => 'مختلط',
            self::VodafoneCash => 'فودافون كاش',
            self::Instapay => 'إنستاباي',
        };
    }

    public static function forDropdown(): array
    {
        return [
            self::Cash->value => 'نقدي مصري',
            self::BankTransfer->value => 'بنك',
            self::CashWallet->value => 'محفظة كاش',
            self::PostalTransfer->value => 'بريد',
            self::OfficeSafe->value => 'خزينة المكتب',
            self::OfficeDrawer->value => 'درج المكتب',
            self::Mixed->value => 'مختلط',
            self::VodafoneCash->value => 'فودافون كاش',
            self::Instapay->value => 'إنستاباي',
        ];
    }
}
