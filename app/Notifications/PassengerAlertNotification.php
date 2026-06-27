<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Models\Flight\FlightPassenger as Passenger;

class PassengerAlertNotification extends Notification
{
    use Queueable;

    protected $passenger;
    protected $daysBefore;

    public function __construct(Passenger $passenger, int $daysBefore)
    {
        $this->passenger  = $passenger;
        $this->daysBefore = $daysBefore;
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $booking = $this->passenger->booking;
        $name    = trim($this->passenger->first_name . ' ' . $this->passenger->last_name);

        $departureDate = null;
        if ($booking && $booking->departure_date) {
            $departureDate = is_string($booking->departure_date)
                ? $booking->departure_date
                : $booking->departure_date->format('Y-m-d');
        }

        // from_airport → to_airport (دائماً صحيح بعكس origin/destination)
        $fromAirport = $booking?->from_airport ?? $booking?->origin ?? '—';
        $toAirport   = $booking?->to_airport   ?? $booking?->destination ?? '—';
        $direction   = "{$fromAirport} → {$toAirport}";

        // نص التنبيه حسب عدد الأيام
        if ($this->daysBefore === 0) {
            $message = "يسافر اليوم الراكب {$name} ({$direction}) (PNR: {$booking?->pnr})";
        } elseif ($this->daysBefore === 1) {
            $message = "يسافر غداً الراكب {$name} ({$direction}) (PNR: {$booking?->pnr})";
        } else {
            $message = "يسافر بعد {$this->daysBefore} أيام الراكب {$name} ({$direction}) (PNR: {$booking?->pnr})";
        }

        return [
            'passenger_id'      => $this->passenger->id,
            'passenger_name'    => $name,
            'flight_booking_id' => $booking?->id,
            'pnr'               => $booking?->pnr,
            // from/to صحيح دائماً — لا يُعتمد على origin/destination
            'from_airport'      => $fromAirport,
            'to_airport'        => $toAirport,
            'direction'         => $direction,
            // احتياطي للتوافق مع الكود القديم
            'origin'            => $fromAirport,
            'destination'       => $toAirport,
            'departure_date'    => $departureDate,
            'departure_time'    => $booking?->departure_time,
            'days_before'       => $this->daysBefore,
            'message'           => $message,
        ];
    }
}
