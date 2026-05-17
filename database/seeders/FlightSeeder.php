<?php

namespace Database\Seeders;

use App\Enums\FlightBookingStatus;
use App\Enums\FlightPaymentMethod;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class FlightSeeder extends Seeder
{
    public function run(): void
    {
        $adminId = cache('seed_admin_id') ?? 1;
        $employeeIds = cache('seed_employee_ids') ?? range(1, 10);
        $accountMap = cache('seed_account_map');
        $flightCashboxId = $accountMap['flight_cashbox'] ?? 2;

        $airlines = ['EgyptAir', 'Nile Air', 'Air Arabia', 'Emirates', 'Qatar Airways', 'Flydubai'];
        $origins = ['CAI', 'HBE', 'SSH', 'HRG', 'LXR', 'ASW'];
        $destinations = ['LHR', 'CDG', 'DXB', 'RUH', 'JED', 'IST', 'FCO', 'FRA', 'AMS', 'MAD'];
        $agentNames = ['Ahmed Ibrahim', 'Mohamed Ali', 'Ali Hassan', 'Omar Khaled', 'Khaled Mahmoud', 'Youssef Karim'];
        $tripTypes = ['one_way', 'round_trip'];
        $channels = ['manual', 'online'];
        $providers = ['Amadeus', 'Galileo', 'Sabre', 'Direct'];

        $customerIds = DB::table('customers')->pluck('id')->toArray();
        $twelveMonthsAgo = now()->subMonths(12);
        
        $maxTransactionId = DB::table('transactions')->max('id') ?? 0;

        $bookings = [];
        $passengers = [];
        $payments = [];
        $transactions = [];
        $accountEntries = [];

        for ($i = 1; $i <= 200; $i++) {
            $customerId = $customerIds[array_rand($customerIds)];
            $employeeId = $employeeIds[array_rand($employeeIds)];
            $createdBy = $employeeIds[array_rand($employeeIds)];
            $createdAt = $twelveMonthsAgo->copy()->addDays(rand(0, 365));

            $statusRoll = rand(1, 10);
            if ($statusRoll <= 6) {
                $status = FlightBookingStatus::CONFIRMED;
            } elseif ($statusRoll <= 8) {
                $status = FlightBookingStatus::PENDING;
            } elseif ($statusRoll <= 9) {
                $status = FlightBookingStatus::CANCELLED;
            } else {
                $status = FlightBookingStatus::REFUNDED;
            }

            $purchasePrice = rand(300000, 1500000) / 100;
            $sellingPrice = $purchasePrice + rand(30000, 200000) / 100;
            $profit = $sellingPrice - $purchasePrice;

            $bookingRef = 'FLT-' . strtoupper(substr(uniqid(), -8));
            $airline = $airlines[array_rand($airlines)];
            $origin = $origins[array_rand($origins)];
            $destination = $destinations[array_rand($destinations)];
            $departureDate = $createdAt->copy()->addDays(rand(7, 90));
            $tripType = $tripTypes[array_rand($tripTypes)];
            $passengerCount = rand(1, 4);

            $bookings[] = [
                'id' => $i,
                // Original table required fields
                'booking_reference' => $bookingRef,
                'booking_channel_type' => $channels[array_rand($channels)],
                'booking_channel_provider' => $providers[array_rand($providers)],
                'customer_id' => $customerId,
                'agent_name' => $agentNames[array_rand($agentNames)],
                'origin' => $origin,
                'destination' => $destination,
                'departure_date' => $departureDate->toDateString(),
                'departure_time' => sprintf('%02d:%02d', rand(0, 23), rand(0, 59)),
                'return_date' => $tripType === 'round_trip' ? $departureDate->copy()->addDays(rand(3, 30))->toDateString() : null,
                'return_time' => $tripType === 'round_trip' ? sprintf('%02d:%02d', rand(0, 23), rand(0, 59)) : null,
                'trip_type' => $tripType,
                'airline' => $airline,
                'passenger_count' => $passengerCount,
                'baggage_allowance_kg' => rand(1, 3) * 23,
                'status' => $status->value,
                'notes' => 'Seeded booking',
                // Fields added by update migration
                'employee_id' => $employeeId,
                'account_id' => $flightCashboxId,
                'created_by' => $createdBy,
                'booking_number' => $bookingRef,
                'airline_name' => $airline,
                'from_airport' => $origin,
                'to_airport' => $destination,
                'trip_details' => "{$origin} → {$destination}",
                'purchase_price' => $purchasePrice,
                'selling_price' => $sellingPrice,
                'profit' => $profit,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];

            // Passengers matching actual table schema
            $firstNames = ['Ahmed', 'Mohamed', 'Ali', 'Hassan', 'Omar', 'Khaled', 'Mahmoud', 'Youssef', 'Sara', 'Mona'];
            $lastNames = ['Ibrahim', 'Mohamed', 'Ali', 'Hassan', 'Omar', 'Khaled', 'Mahmoud'];
            $types = ['adult', 'child', 'infant'];

            for ($j = 1; $j <= $passengerCount; $j++) {
                $passengers[] = [
                    'flight_booking_id' => $i,
                    'first_name' => $firstNames[array_rand($firstNames)],
                    'last_name' => $lastNames[array_rand($lastNames)],
                    'type' => $j === 1 ? 'adult' : $types[array_rand($types)],
                    'date_of_birth' => now()->create(rand(1960, 2015), rand(1, 12), rand(1, 28)),
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }

            // Payments for confirmed bookings
            if ($status === FlightBookingStatus::CONFIRMED) {
                $transactionId = $maxTransactionId + count($transactions) + 1;

                $transactions[] = [
                    'id' => $transactionId,
                    'type' => TransactionType::Income->value,
                    'amount' => $sellingPrice,
                    'module' => TransactionModule::Flight->value,
                    'related_type' => 'App\Models\Flight\FlightBooking',
                    'related_id' => $i,
                    'from_account_id' => null,
                    'to_account_id' => $flightCashboxId,
                    'created_by' => $createdBy,
                    'notes' => "Flight payment - {$bookingRef}",
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                $accountEntries[] = [
                    'account_id' => $flightCashboxId,
                    'transaction_id' => $transactionId,
                    'debit' => 0.00,
                    'credit' => $sellingPrice,
                    'balance_after' => null,
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];

                $paymentMethods = [FlightPaymentMethod::Cash, FlightPaymentMethod::BankTransfer, FlightPaymentMethod::Mixed];
                $payments[] = [
                    'flight_booking_id' => $i,
                    'payment_method' => $paymentMethods[array_rand($paymentMethods)]->value,
                    'amount' => $sellingPrice,
                    'currency' => 'EGP',
                    'treasury_account' => 'main_cashbox',
                    'transaction_reference' => 'TRX-' . strtoupper(substr(uniqid(), -6)),
                    'payment_date' => $createdAt,
                    'paid_by' => $agentNames[array_rand($agentNames)],
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ];
            }
        }

        DB::transaction(function () use ($bookings, $passengers, $payments, $transactions, $accountEntries, $flightCashboxId) {
            foreach ($bookings as $booking) {
                DB::table('flight_bookings')->updateOrInsert(['id' => $booking['id']], $booking);
            }

            if (!empty($passengers)) {
                foreach ($passengers as $passenger) {
                    DB::table('passengers')->updateOrInsert([
                        'flight_booking_id' => $passenger['flight_booking_id'],
                        'first_name' => $passenger['first_name'],
                        'last_name' => $passenger['last_name'],
                    ], $passenger);
                }
            }

            if (!empty($transactions)) {
                foreach ($transactions as $transaction) {
                    DB::table('transactions')->updateOrInsert(['id' => $transaction['id']], $transaction);
                }
            }

            if (!empty($accountEntries)) {
                $currentBalance = DB::table('accounts')->where('id', $flightCashboxId)->value('balance');
                foreach ($accountEntries as &$entry) {
                    $currentBalance += ($entry['credit'] - $entry['debit']);
                    $entry['balance_after'] = $currentBalance;
                }
                unset($entry);
                foreach ($accountEntries as $entry) {
                    DB::table('account_entries')->updateOrInsert([
                        'account_id' => $entry['account_id'],
                        'transaction_id' => $entry['transaction_id'],
                    ], $entry);
                }
                DB::table('accounts')->where('id', $flightCashboxId)->update(['balance' => $currentBalance]);
            }

            if (!empty($payments)) {
                foreach ($payments as $payment) {
                    DB::table('flight_payments')->updateOrInsert([
                        'flight_booking_id' => $payment['flight_booking_id'],
                        'transaction_reference' => $payment['transaction_reference'],
                    ], $payment);
                }
            }
        });

        $this->command->info('✅ FlightSeeder: 200 bookings + ' . count($passengers) . ' passengers + ' . count($payments) . ' payments created');
    }
}
