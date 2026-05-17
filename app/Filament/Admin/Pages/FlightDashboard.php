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

    protected static ?int $navigationSort = 1;

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Admin\Widgets\FlightStatsWidget::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            \App\Filament\Admin\Widgets\RecentFlightBookingsWidget::class,
        ];
    }
}
