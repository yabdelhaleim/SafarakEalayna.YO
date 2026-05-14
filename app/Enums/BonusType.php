<?php

namespace App\Enums;

enum BonusType: string
{
    case Bonus = 'bonus';
    case Deduction = 'deduction';

    public function label(): string
    {
        return match ($this) {
            self::Bonus => 'Bonus',
            self::Deduction => 'Deduction',
        };
    }
}
