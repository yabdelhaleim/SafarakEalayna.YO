<?php

namespace App\Support\Finance;

use App\Enums\AccountType;
use BackedEnum;

final class PaymentMethodAccountType
{
    /** @var list<string> */
    private const CASHBOX_METHODS = [
        'cash',
        'cash_egp',
        'cash_payment',
        'cashbox',
        'office_safe',
        'office_drawer',
    ];

    /** @var list<string> */
    private const BANK_METHODS = [
        'bank',
        'bank_transfer',
        'credit_card',
        'debit_card',
        'card',
        'card_payment',
        'post_office',
        'postal_transfer',
    ];

    /** @var list<string> */
    private const WALLET_METHODS = [
        'wallet',
        'cash_wallet',
        'mobile_money',
        'mobile_wallet',
        'e_wallet',
        'electronic_wallet',
        'vodafone_cash',
        'etisalat_cash',
        'orange_cash',
        'we_pay',
        'instapay',
        'paymob',
    ];

    public static function resolve(string|BackedEnum|null $paymentMethod): ?AccountType
    {
        $code = self::normalize($paymentMethod);

        if ($code === '') {
            return null;
        }

        if (in_array($code, self::WALLET_METHODS, true) || str_contains($code, 'wallet')) {
            return AccountType::Wallet;
        }

        if (
            in_array($code, self::BANK_METHODS, true)
            || str_contains($code, 'bank')
            || str_contains($code, 'card')
            || str_contains($code, 'postal')
            || str_contains($code, 'post_office')
        ) {
            return AccountType::Bank;
        }

        if (in_array($code, self::CASHBOX_METHODS, true)) {
            return AccountType::Cashbox;
        }

        return null;
    }

    public static function matches(string|BackedEnum|null $paymentMethod, string|BackedEnum|null $accountType): bool
    {
        $expected = self::resolve($paymentMethod);

        return $expected !== null && $expected->value === self::normalize($accountType);
    }

    private static function normalize(string|BackedEnum|null $value): string
    {
        if ($value instanceof BackedEnum) {
            $value = $value->value;
        }

        return strtolower(trim((string) $value));
    }
}
