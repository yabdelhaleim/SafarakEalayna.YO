<?php

namespace App\Enums;

enum LoanType
{
    case Advance = 'advance';
    case Loan = 'loan';

    public function label(): string
    {
        return match ($this) {
            self::Advance => 'Advance',
            self::Loan => 'Loan',
        };
    }
}
