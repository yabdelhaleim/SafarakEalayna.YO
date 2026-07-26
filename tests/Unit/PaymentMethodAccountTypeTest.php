<?php

namespace Tests\Unit;

use App\Enums\AccountType;
use App\Support\Finance\PaymentMethodAccountType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PaymentMethodAccountTypeTest extends TestCase
{
    #[DataProvider('paymentMethodProvider')]
    public function test_it_resolves_payment_methods_to_their_collection_account_type(
        string $paymentMethod,
        AccountType $expectedType,
    ): void {
        $this->assertSame($expectedType, PaymentMethodAccountType::resolve($paymentMethod));
    }

    public function test_it_returns_null_for_an_unmapped_payment_method(): void
    {
        $this->assertNull(PaymentMethodAccountType::resolve('custom_unmapped_method'));
    }

    public function test_it_matches_the_resolved_method_to_the_account_type(): void
    {
        $this->assertTrue(PaymentMethodAccountType::matches('cash', AccountType::Cashbox));
        $this->assertFalse(PaymentMethodAccountType::matches('cash', AccountType::Bank));
    }

    public static function paymentMethodProvider(): array
    {
        return [
            'cash' => ['cash', AccountType::Cashbox],
            'cash EGP' => ['cash_egp', AccountType::Cashbox],
            'office safe' => ['office_safe', AccountType::Cashbox],
            'bank transfer' => ['bank_transfer', AccountType::Bank],
            'credit card' => ['credit_card', AccountType::Bank],
            'postal transfer' => ['postal_transfer', AccountType::Bank],
            'mobile money' => ['mobile_money', AccountType::Wallet],
            'cash wallet' => ['cash_wallet', AccountType::Wallet],
            'provider wallet' => ['vodafone_cash', AccountType::Wallet],
        ];
    }
}
