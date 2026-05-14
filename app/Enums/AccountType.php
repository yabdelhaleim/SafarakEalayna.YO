<?php

namespace App\Enums;

enum AccountType: string
{
    case Cashbox = 'cashbox';
    case Wallet = 'wallet';
    case Bank = 'bank';
    case Treasury = 'treasury';
    case Post = 'post';
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Expense = 'expense';
    case Revenue = 'revenue';

    public function label(): string
    {
        return match ($this) {
            self::Cashbox => 'خزينة نقدي',
            self::Wallet => 'محفظة إلكترونية',
            self::Bank => 'حساب بنكي',
            self::Post => 'بريد',
            self::Treasury => 'خزينة عامة',
            self::Customer => 'حساب عميل',
            self::Supplier => 'حساب مورد',
            self::Expense => 'مصروفات',
            self::Revenue => 'إيرادات',
        };
    }
}
