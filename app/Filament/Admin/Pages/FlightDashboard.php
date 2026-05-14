<?php

namespace App\Filament\Admin\Pages;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\Flight\FlightBooking;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class FlightDashboard extends Page
{
    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-presentation-chart-line';

    protected string $view = 'filament.admin.pages.flight-dashboard';

    protected static ?string $title = 'لوحة تحكم الطيران';

    protected static \UnitEnum|string|null $navigationGroup = 'الطيران';

    protected static ?int $navigationSort = 1; // First item in the group

    public array $stats = [];
    public array $recentBookings = [];

    public function mount(): void
    {
        $this->loadStats();
        $this->loadRecentBookings();
    }

    public function loadStats(): void
    {
        $flightAccounts = Account::query()
            ->where('is_active', true)
            ->where('module_type', 'flights')
            ->get();

        $banks = $flightAccounts->where('type', AccountType::Bank);
        $wallets = $flightAccounts->where('type', AccountType::Wallet);
        $cashboxes = $flightAccounts->whereIn('type', [AccountType::Cashbox, AccountType::Treasury]);

        $this->stats = [
            'total_balance' => $flightAccounts->sum('balance'),
            'banks' => [
                'count' => $banks->count(),
                'balance' => $banks->sum('balance'),
            ],
            'wallets' => [
                'count' => $wallets->count(),
                'balance' => $wallets->sum('balance'),
            ],
            'cashboxes' => [
                'count' => $cashboxes->count(),
                'balance' => $cashboxes->sum('balance'),
            ],
            'total_bookings' => FlightBooking::count(),
            'revenue_this_month' => FlightBooking::whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->sum('selling_price'),
        ];
    }

    public function loadRecentBookings(): void
    {
        $this->recentBookings = FlightBooking::with(['customer'])
            ->latest()
            ->take(5)
            ->get()
            ->toArray();
    }
}
