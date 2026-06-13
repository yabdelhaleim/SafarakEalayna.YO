<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Account;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Flight;
use App\Models\Flight\FlightBooking;
use App\Models\HajjUmraBooking;
use App\Models\HajjUmraPayment;
use App\Models\Online\OnlineTransaction;
use App\Models\TicketModification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "=== WIDGET QUERY VERIFICATION ===\n\n";

// FlightStatsWidget queries
$flightBalance = Account::query()->where('is_active', true)->where('module_type', 'flights')->sum('balance') ?? 0;
$flightBookings = FlightBooking::count();
$flightRevenue = FlightBooking::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('selling_price') ?? 0;
echo "FlightStatsWidget:\n";
echo "  balance={$flightBalance}, bookings={$flightBookings}, revenue={$flightRevenue}\n\n";

// FinancialStatsWidget queries
if (Schema::hasTable('transactions')) {
    $income = DB::table('transactions')->where('type', 'income')->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('amount') ?? 0;
    $expense = DB::table('transactions')->where('type', 'expense')->whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->sum('amount') ?? 0;
    echo "FinancialStatsWidget:\n";
    echo "  income={$income}, expense={$expense}, profit=" . ($income - $expense) . "\n\n";
} else {
    echo "FinancialStatsWidget: transactions table missing\n\n";
}

// Legacy StatsOverviewWidget (buggy month-only queries)
$monthBookingsBuggy = Booking::whereMonth('created_at', now()->month)->count();
$monthBookingsFixed = Booking::whereMonth('created_at', now()->month)->whereYear('created_at', now()->year)->count();
echo "StatsOverviewWidget (legacy Booking model):\n";
echo "  monthBookings (no year)={$monthBookingsBuggy}, (with year)={$monthBookingsFixed}\n";
echo "  monthRevenue=" . (Booking::whereMonth('created_at', now()->month)->where('payment_status', 'paid')->sum('total_price') ?? 0) . "\n";
echo "  activeCustomers=" . Customer::active()->count() . "\n";
echo "  activeFlights=" . Flight::whereIn('status', ['scheduled', 'boarding'])->count() . "\n\n";

// HajjUmraStats
echo "HajjUmraStats:\n";
echo "  bookings=" . HajjUmraBooking::count() . ", profit=" . (HajjUmraBooking::sum('profit') ?? 0) . ", payments=" . (HajjUmraPayment::sum('amount') ?? 0) . "\n\n";

// OnlineStats
echo "OnlineStats:\n";
echo "  tx=" . OnlineTransaction::count() . ", profit=" . (OnlineTransaction::sum('profit') ?? 0) . ", selling=" . (OnlineTransaction::sum('selling_price') ?? 0) . "\n\n";

// ModificationStatsWidget
echo "ModificationStatsWidget:\n";
echo "  pending=" . TicketModification::where('status', 'confirmed')->where('reconciliation_status', 'unreconciled')->count() . "\n";
echo "  amount=" . (TicketModification::where('status', 'confirmed')->where('reconciliation_status', 'unreconciled')->sum('amount') ?? 0) . "\n\n";

// BusInventory filter bug demo
if (Schema::hasTable('bus_inventories')) {
    $withAvailable = \App\Models\Bus\BusInventory::query()->hasAvailableTickets()->count();
    $total = \App\Models\Bus\BusInventory::count();
    echo "BusInventory filters:\n";
    echo "  total={$total}, hasAvailable={$withAvailable}\n";
    echo "  NOTE: has_available/with_debt filters ignore selected value (always apply positive scope)\n\n";
}

echo "=== DONE ===\n";
