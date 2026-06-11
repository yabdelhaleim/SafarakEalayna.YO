<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Passenger;
use App\Notifications\PassengerAlertNotification;
use App\Enums\FlightBookingStatus;
use Carbon\Carbon;

class GeneratePassengerAlerts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:generate-passenger-alerts';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate travel date alerts/notifications for passengers based on user preferences';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting passenger travel alert generation...');
        $currentTime = now()->format('H:i:s');
        $users = User::where('is_active', true)->get();

        foreach ($users as $user) {
            // Get user's preferred alert time and days before
            $alertTime = $user->travel_alert_time ?? '09:00:00';
            $daysBefore = $user->travel_alert_days_before ?? 1;

            // Check if current time is equal to or after the alert time
            if ($currentTime >= $alertTime) {
                $targetDate = Carbon::today()->addDays($daysBefore)->toDateString();

                $this->info("User ID {$user->id} is due for check. Target date: {$targetDate} ({$daysBefore} days before)");

                $passengers = Passenger::whereHas('booking', function ($query) use ($targetDate) {
                    $query->whereDate('departure_date', $targetDate)
                          ->whereNotIn('status', [
                              FlightBookingStatus::CANCELLED,
                              FlightBookingStatus::REFUNDED
                          ]);
                })->get();

                foreach ($passengers as $passenger) {
                    // Check if already notified
                    $alreadyNotified = $user->notifications()
                        ->where('type', PassengerAlertNotification::class)
                        ->where('data->passenger_id', $passenger->id)
                        ->where('data->days_before', $daysBefore)
                        ->exists();

                    if (!$alreadyNotified) {
                        $user->notify(new PassengerAlertNotification($passenger, $daysBefore));
                        $this->info("Notified User {$user->id} about Passenger {$passenger->id}");
                    }
                }
            } else {
                $this->line("User ID {$user->id} alert time {$alertTime} not reached yet (current time: {$currentTime})");
            }
        }

        $this->info('Passenger travel alert generation completed.');
        return Command::SUCCESS;
    }
}
