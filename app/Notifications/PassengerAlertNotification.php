<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use App\Models\Flight\FlightPassenger as Passenger;
use App\Models\Flight\FlightSegment;

class PassengerAlertNotification extends Notification
{
    use Queueable;

    public const LEG_OUTBOUND = 'outbound';
    public const LEG_RETURN   = 'return';
    public const LEG_SEGMENT  = 'segment';

    protected Passenger $passenger;
    protected int $daysBefore;
    protected string $legType;
    protected ?FlightSegment $segment;

    public function __construct(
        Passenger $passenger,
        int $daysBefore,
        string $legType = self::LEG_OUTBOUND,
        ?FlightSegment $segment = null
    ) {
        $this->passenger  = $passenger;
        $this->daysBefore = $daysBefore;
        // Allow 'outbound' | 'return' | 'segment' — anything else falls back
        // to outbound so we never mis-render an unknown leg.
        $this->legType = in_array($legType, [self::LEG_OUTBOUND, self::LEG_RETURN, self::LEG_SEGMENT], true)
            ? $legType
            : self::LEG_OUTBOUND;
        $this->segment = $segment;
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $booking = $this->passenger->booking;
        $name    = trim($this->passenger->first_name . ' ' . $this->passenger->last_name);
        $pnr     = $booking?->pnr;

        // Resolve the leg's airports, date, time, and Arabic label based on
        // leg type. Return reverses the route; segment pulls everything from
        // the segment row.
        $fromAirport  = '—';
        $toAirport    = '—';
        $direction    = '— → —';
        $departureDate = null;
        $departureTime = null;
        $legLabelAr   = 'ذهاب';
        $legNumber    = null;

        if ($this->legType === self::LEG_RETURN && $booking) {
            $fromAirport  = $booking->to_airport   ?? $booking->destination ?? '—';
            $toAirport    = $booking->from_airport ?? $booking->origin      ?? '—';
            $direction    = "{$fromAirport} → {$toAirport}";
            $departureDate = $booking->return_date
                ? (is_string($booking->return_date) ? $booking->return_date : $booking->return_date->format('Y-m-d'))
                : null;
            $departureTime = $booking->return_time ?? null;
            $legLabelAr   = 'عودة';
        } elseif ($this->legType === self::LEG_SEGMENT && $this->segment) {
            $fromAirport = $this->segment->from_airport ?? '—';
            $toAirport   = $this->segment->to_airport   ?? '—';
            $direction   = "{$fromAirport} → {$toAirport}";
            $departureDate = $this->segment->departure_date
                ? (is_string($this->segment->departure_date) ? $this->segment->departure_date : $this->segment->departure_date->format('Y-m-d'))
                : null;
            $departureTime = $this->segment->departure_time ?? null;
            // Stable leg number for the "خط سير N" label so the same segment
            // always renders the same number across notifications.
            $legNumber  = $this->segment->sort_order ?? $this->segment->id;
            $legLabelAr = "خط سير {$legNumber}";
        } else {
            // outbound (default)
            $fromAirport = $booking?->from_airport ?? $booking?->origin      ?? '—';
            $toAirport   = $booking?->to_airport   ?? $booking?->destination ?? '—';
            $direction   = "{$fromAirport} → {$toAirport}";
            $departureDate = ($booking && $booking->departure_date)
                ? (is_string($booking->departure_date) ? $booking->departure_date : $booking->departure_date->format('Y-m-d'))
                : null;
            $departureTime = $booking?->departure_time;
            $legLabelAr   = 'ذهاب';
        }

        // نص التنبيه حسب عدد الأيام ونوع الرحلة
        $verb = $this->legType === self::LEG_RETURN ? 'يعود' : 'يسافر';
        $legSuffix = $this->legType === self::LEG_OUTBOUND ? '' : " ({$legLabelAr})";
        if ($this->daysBefore === 0) {
            $message = "{$verb} اليوم الراكب {$name} ({$direction}){$legSuffix} (PNR: {$pnr})";
        } elseif ($this->daysBefore === 1) {
            $message = "{$verb} غداً الراكب {$name} ({$direction}){$legSuffix} (PNR: {$pnr})";
        } else {
            $message = "{$verb} بعد {$this->daysBefore} أيام الراكب {$name} ({$direction}){$legSuffix} (PNR: {$pnr})";
        }

        return [
            'passenger_id'      => $this->passenger->id,
            'passenger_name'    => $name,
            'flight_booking_id' => $booking?->id,
            'pnr'               => $pnr,
            // from/to صحيح دائماً — لا يُعتمد على origin/destination
            'from_airport'      => $fromAirport,
            'to_airport'        => $toAirport,
            'direction'         => $direction,
            // احتياطي للتوافق مع الكود القديم
            'origin'            => $fromAirport,
            'destination'       => $toAirport,
            'departure_date'    => $departureDate,
            'departure_time'    => $departureTime,
            'days_before'       => $this->daysBefore,
            'leg_type'          => $this->legType,
            'leg_label_ar'      => $legLabelAr,
            'segment_id'        => $this->segment?->id,
            'message'           => $message,
        ];
    }
}
