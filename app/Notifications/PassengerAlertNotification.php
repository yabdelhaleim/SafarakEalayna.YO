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

    /**
     * Create a new notification instance.
     */
    public function __construct(Passenger $passenger, int $daysBefore)
    {
        $this->passenger = $passenger;
        $this->daysBefore = $daysBefore;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $booking = $this->passenger->booking;
        $name = trim($this->passenger->first_name . ' ' . $this->passenger->last_name);
        
        $departureDate = null;
        if ($booking && $booking->departure_date) {
            $departureDate = is_string($booking->departure_date) 
                ? $booking->departure_date 
                : $booking->departure_date->format('Y-m-d');
        }

        $message = "";
        if ($this->daysBefore === 0) {
            $message = "يسافر اليوم الراكب {$name} إلى {$booking?->destination} (PNR: {$booking?->pnr})";
        } elseif ($this->daysBefore === 1) {
            $message = "يسافر غداً الراكب {$name} إلى {$booking?->destination} (PNR: {$booking?->pnr})";
        } else {
            $message = "يسافر بعد {$this->daysBefore} أيام الراكب {$name} إلى {$booking?->destination} (PNR: {$booking?->pnr})";
        }

        return [
            'passenger_id' => $this->passenger->id,
            'passenger_name' => $name,
            'flight_booking_id' => $booking?->id,
            'pnr' => $booking?->pnr,
            'origin' => $booking?->origin,
            'destination' => $booking?->destination,
            'departure_date' => $departureDate,
            'departure_time' => $booking?->departure_time,
            'days_before' => $this->daysBefore,
            'message' => $message,
        ];
    }
}
