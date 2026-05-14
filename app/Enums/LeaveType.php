<?php

namespace App\Enums;

enum LeaveType
{
    case Annual = 'annual';
    case Sick = 'sick';
    case Maternity = 'maternity';
    case Paternity = 'paternity';
    case Emergency = 'emergency';
    case Unpaid = 'unpaid';

    public function label(): string
    {
        return match ($this) {
            self::Annual => 'Annual',
            self::Sick => 'Sick',
            self::Maternity => 'Maternity',
            self::Paternity => 'Paternity',
            self::Emergency => 'Emergency',
            self::Unpaid => 'Unpaid',
        };
    }
}
