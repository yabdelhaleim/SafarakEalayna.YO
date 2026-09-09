<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightPassenger as Passenger;
use App\Models\Flight\FlightSegment;
use App\Notifications\PassengerAlertNotification;
use App\Enums\FlightBookingStatus;
use Carbon\Carbon;

class GeneratePassengerAlerts extends Command
{
    protected $signature   = 'app:generate-passenger-alerts';
    protected $description = 'Generate travel date alerts for passengers based on user preferences (outbound + return + segments)';

    public function handle()
    {
        $this->info('Starting passenger travel alert generation...');
        $currentTime = now()->format('H:i:s');
        $users       = User::where('is_active', true)->get();

        foreach ($users as $user) {
            $alertTime  = $user->travel_alert_time ?? '09:00:00';
            $daysBefore = (int) ($user->travel_alert_days_before ?? 1);

            if ($currentTime < $alertTime) {
                $this->line("User ID {$user->id}: وقت التنبيه {$alertTime} لم يحن بعد (الآن: {$currentTime})");
                continue;
            }

            // نولّد تنبيهات لـ (daysBefore) و 0 (يوم السفر نفسه) إذا كانا مختلفين
            $daysToCheck = array_unique(array_filter([$daysBefore, 0], fn ($d) => $d >= 0));

            foreach ($daysToCheck as $days) {
                $targetDate = Carbon::today()->addDays($days)->toDateString();

                $this->info("User ID {$user->id} | days={$days} | targetDate={$targetDate}");

                $passengers = Passenger::whereHas('booking', function ($query) {
                        $query->whereNotIn('status', [
                            FlightBookingStatus::CANCELLED->value,
                            FlightBookingStatus::REFUNDED->value,
                        ]);
                    })
                    ->with(['booking', 'booking.segments'])
                    ->get();

                foreach ($passengers as $passenger) {
                    $booking = $passenger->booking;
                    if (! $booking) {
                        continue;
                    }

                    // Determine which legs of this booking fall on $targetDate.
                    // Segments are the canonical representation when present;
                    // otherwise we fall back to the booking's outbound/return.
                    foreach ($this->resolveLegsForDate($booking, $targetDate) as [$legType, $segment]) {
                        $alreadyNotified = $this->alreadyNotified($user, $passenger->id, $days, $targetDate, $legType, $segment);

                        if ($alreadyNotified) {
                            $this->line("  → تنبيه مرسل مسبقاً للراكب {$passenger->id} ({$legType})");
                            continue;
                        }

                        $user->notify(new PassengerAlertNotification($passenger, $days, $legType, $segment));
                        $this->info("  → أُرسل تنبيه للمستخدم {$user->id} عن الراكب {$passenger->id} ({$legType})");
                    }
                }
            }
        }

        $this->info('Passenger travel alert generation completed.');
        return Command::SUCCESS;
    }

    /**
     * Return a list of [legType, segment|null] pairs whose date equals $targetDate.
     *
     * When the booking has segments, those rows are the source of truth — this
     * matches the manifest's behavior of rendering every segment as its own row
     * (transit legs included). Otherwise we fall back to outbound and return
     * derived from the booking's own departure_date / return_date.
     *
     * @return array<int, array{0:string, 1:?FlightSegment}>
     */
    private function resolveLegsForDate(FlightBooking $booking, string $targetDate): array
    {
        $segments = $booking->segments;
        if ($segments && $segments->count() > 0) {
            $matches = [];
            foreach ($segments as $segment) {
                $segmentDate = $segment->departure_date
                    ? (is_string($segment->departure_date) ? $segment->departure_date : $segment->departure_date->format('Y-m-d'))
                    : null;
                if ($segmentDate === $targetDate) {
                    $matches[] = [PassengerAlertNotification::LEG_SEGMENT, $segment];
                }
            }

            return $matches;
        }

        $matches = [];
        $departureDate = $booking->departure_date
            ? (is_string($booking->departure_date) ? $booking->departure_date : $booking->departure_date->format('Y-m-d'))
            : null;
        if ($departureDate === $targetDate) {
            $matches[] = [PassengerAlertNotification::LEG_OUTBOUND, null];
        }

        $returnDate = $booking->return_date
            ? (is_string($booking->return_date) ? $booking->return_date : $booking->return_date->format('Y-m-d'))
            : null;
        if ($returnDate === $targetDate && strtolower((string) $booking->trip_type) === 'round_trip') {
            $matches[] = [PassengerAlertNotification::LEG_RETURN, null];
        }

        return $matches;
    }

    /**
     * Check whether the user already received a matching notification.
     *
     * Dedupe key = (passenger, days_before, departure_date, leg identity).
     * For segments the leg identity is the segment_id; for booking-level legs
     * it's the leg_type. Old notifications (pre-leg_type) have leg_type NULL,
     * and are treated as outbound so the existing dedupe semantics are
     * preserved across the migration.
     */
    private function alreadyNotified(
        User $user,
        int $passengerId,
        int $days,
        string $targetDate,
        string $legType,
        ?FlightSegment $segment
    ): bool {
        $query = $user->notifications()
            ->where('type', PassengerAlertNotification::class)
            ->where('data->passenger_id', $passengerId)
            ->where('data->days_before', $days)
            ->where('data->departure_date', $targetDate);

        if ($segment) {
            return $query->where('data->segment_id', $segment->id)->exists();
        }

        return $query->where(function ($q) use ($legType) {
            $q->where('data->leg_type', $legType)
                ->orWhere(function ($q2) use ($legType) {
                    // Backwards compatibility: pre-fix notifications had no
                    // leg_type key. Treat those as outbound so we don't double
                    // notify outbound on the first run after deploy.
                    if ($legType === PassengerAlertNotification::LEG_OUTBOUND) {
                        $q2->whereNull('data->leg_type');
                    }
                });
        })->exists();
    }
}
