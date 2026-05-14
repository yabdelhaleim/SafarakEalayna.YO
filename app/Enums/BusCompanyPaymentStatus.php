<?php

namespace App\Enums;

enum BusCompanyPaymentStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';

    public function label(): string
    {
        return ucfirst(strtolower($this->name));
    }
}
