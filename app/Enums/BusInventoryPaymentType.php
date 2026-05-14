<?php

namespace App\Enums;

enum BusInventoryPaymentType: string
{
    case Cash = 'cash';
    case Deferred = 'deferred';

    public function label(): string
    {
        return ucfirst(strtolower($this->name));
    }
}
