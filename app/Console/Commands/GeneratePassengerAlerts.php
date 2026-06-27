<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Flight\FlightPassenger as Passenger;
use App\Notifications\PassengerAlertNotification;
use App\Enums\FlightBookingStatus;
use Carbon\Carbon;

class GeneratePassengerAlerts extends Command
{
    protected $signature   = 'app:generate-passenger-alerts';
    protected $description = 'Generate travel date alerts for passengers based on user preferences';

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

                $passengers = Passenger::whereHas('booking', function ($query) use ($targetDate) {
                    $query->whereDate('departure_date', $targetDate)
                          ->whereNotIn('status', [
                              FlightBookingStatus::CANCELLED->value,
                              FlightBookingStatus::REFUNDED->value,
                          ]);
                })->get();

                foreach ($passengers as $passenger) {
                    $alreadyNotified = $user->notifications()
                        ->where('type', PassengerAlertNotification::class)
                        ->where('data->passenger_id', $passenger->id)
                        ->where('data->days_before', $days)
                        ->where('data->departure_date', $targetDate)
                        ->exists();

                    if (! $alreadyNotified) {
                        $user->notify(new PassengerAlertNotification($passenger, $days));
                        $this->info("  → أُرسل تنبيه للمستخدم {$user->id} عن الراكب {$passenger->id}");
                    } else {
                        $this->line("  → تنبيه مرسل مسبقاً للراكب {$passenger->id}");
                    }
                }
            }
        }

        $this->info('Passenger travel alert generation completed.');
        return Command::SUCCESS;
    }
}
