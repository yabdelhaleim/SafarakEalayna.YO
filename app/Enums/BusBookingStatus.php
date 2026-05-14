<?php

namespace App\Enums;

enum BusBookingStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst(strtolower($this->name));
    }
}
