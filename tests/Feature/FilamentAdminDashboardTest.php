<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Widgets\AdminPortalWidget;
use App\Filament\Admin\Widgets\FinancialStatsWidget;
use App\Filament\Admin\Widgets\QuickStatsWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use Tests\TestCase;

class FilamentAdminDashboardTest extends TestCase
{
    public function test_main_filament_dashboard_only_loads_portal_widget(): void
    {
        $dashboard = new Dashboard;

        $this->assertSame(
            [AdminPortalWidget::class],
            $dashboard->getWidgets()
        );
    }

    public function test_legacy_dashboard_widgets_are_not_auto_discovered(): void
    {
        $this->assertFalse(QuickStatsWidget::isDiscovered());
        $this->assertFalse(FinancialStatsWidget::isDiscovered());
        $this->assertFalse(StatsOverviewWidget::isDiscovered());
    }
}
