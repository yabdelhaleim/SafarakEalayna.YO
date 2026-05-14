<?php

namespace App\Enums;

enum InvoiceType: string
{
    case Flight = 'flight';
    case Bus = 'bus';
    case Service = 'service';
    case Online = 'online';
    case HajjUmrah = 'hajj_umrah';
    case Visa = 'visa';
    case General = 'general';

    public function label(): string
    {
        return match ($this) {
            self::Flight => 'Flight Booking',
            self::Bus => 'Bus Booking',
            self::Service => 'Service',
            self::Online => 'Online Service',
            self::HajjUmrah => 'Hajj & Umrah',
            self::Visa => 'Visa',
            self::General => 'General',
        };
    }
}
