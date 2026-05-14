<?php

namespace App\Enums;

enum FawryPaymentMethod: string
{
    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case CashWallet = 'cash_wallet';
    case OfficeSafe = 'office_safe';
    case OfficeDrawer = 'office_drawer';

    public function label(): string
    {
        return match ($this) {
            self::Cash => 'نقدي',
            self::BankTransfer => 'تحويل بنكي',
            self::CashWallet => 'محفظة كاش',
            self::OfficeSafe => 'خزينة المكتب',
            self::OfficeDrawer => 'درج المكتب',
        };
    }

    public function labelEn(): string
    {
        return match ($this) {
            self::Cash => 'Cash',
            self::BankTransfer => 'Bank Transfer',
            self::CashWallet => 'Cash Wallet',
            self::OfficeSafe => 'Office Safe',
            self::OfficeDrawer => 'Office Drawer',
        };
    }
}
