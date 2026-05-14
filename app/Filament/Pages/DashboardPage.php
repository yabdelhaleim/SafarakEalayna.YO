<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\FinancialStatsWidget;
use App\Filament\Widgets\QuickStatsWidget;
use App\Filament\Widgets\RecentActivitiesWidget;
use App\Filament\Widgets\EmployeeStatsWidget;
use Filament\Pages\Dashboard as BaseDashboard;

class DashboardPage extends BaseDashboard
{
    protected static ?string $title = 'لوحة التحكم';

    protected static ?string $navigationLabel = 'لوحة التحكم';

    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?int $navigationSort = -1;

    protected static bool $isLazy = false;

    protected static string $view = 'filament.pages.dashboard';

    protected ?string $heading = 'نظرة عامة على النظام';

    protected ?string $subheading = 'آخر الإحصائيات والنشاطات';

    public function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\QuickStatsWidget::class,
            \App\Filament\Widgets\FinancialStatsWidget::class,
            \App\Filament\Widgets\EmployeeStatsWidget::class,
            \App\Filament\Widgets\DashboardChartWidget::class,
            \App\Filament\Widgets\RecentActivitiesWidget::class,
        ];
    }

    protected function getHeaderWidgetsColumns(): int | array
    {
        return [
            'md' => 2,
            'lg' => 4,
        ];
    }

    protected function getFooterWidgetsColumns(): int | array
    {
        return [
            'md' => 2,
            'lg' => 3,
        ];
    }
}
