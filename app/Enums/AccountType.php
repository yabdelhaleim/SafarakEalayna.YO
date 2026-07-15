<?php

namespace App\Enums;

enum AccountType: string
{
    case Cashbox = 'cashbox';
    case Wallet = 'wallet';
    case Bank = 'bank';
    // 'treasury' and 'post' removed in Phase 3.5b cleanup (2026-07-14).
    // Their AccountType cases were retired after the DB schema
    // (migration 2026_07_09_010000 etc.) removed them from the
    // accounts.type ENUM. Accounts previously labelled "Treasury" or "Post"
    // are now recorded as Bank or Cashbox with a free-text name.
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Expense = 'expense';
    case Revenue = 'revenue';
    case Liability = 'liability';
    case Owner = 'owner';

    public function label(): string
    {
        return match ($this) {
            self::Cashbox => 'خزينة نقدي',
            self::Wallet => 'محفظة إلكترونية',
            self::Bank => 'حساب بنكي',
            self::Customer => 'حساب عميل',
            self::Supplier => 'حساب مورد',
            self::Expense => 'مصروفات',
            self::Revenue => 'إيرادات',
            self::Liability => 'التزامات (دائنون)',
            self::Owner => 'حساب داخلي (نظام)',
        };
    }
}
