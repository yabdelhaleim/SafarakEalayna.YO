<?php

namespace App\Enums;

enum FlightClass: string
{
    case Economy = 'economy';
    case Business = 'business';
    case First = 'first';

    public function label(): string
    {
        return ucfirst(strtolower($this->name));
    }
}
