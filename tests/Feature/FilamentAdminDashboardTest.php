<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Widgets\AdminPortalWidget;
use App\Filament\Admin\Widgets\FinancialStatsWidget;
use App\Filament\Admin\Widgets\QuickStatsWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Http\Middleware\SetFilamentLocale;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
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

    public function test_admin_panel_uses_arabic_rtl_layout(): void
    {
        $this->assertContains(
            SetFilamentLocale::class,
            Filament::getPanel('admin')->getMiddleware(),
        );

        app()->setLocale('en');

        $response = app(SetFilamentLocale::class)->handle(
            Request::create('/admin'),
            function () {
                $this->assertSame('ar', app()->getLocale());
                $this->assertSame('rtl', __('filament-panels::layout.direction'));

                return response()->noContent();
            },
        );

        $this->assertSame(204, $response->getStatusCode());
    }
}
