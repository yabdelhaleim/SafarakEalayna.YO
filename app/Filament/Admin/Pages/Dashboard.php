<?php

namespace App\Filament\Admin\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

class Dashboard extends BaseDashboard
{
    protected static ?string $title = 'لوحة الإدارة';

    protected static ?string $navigationLabel = 'لوحة الإدارة';

    protected static string|\UnitEnum|null $navigationGroup = 'الرئيسية';

    protected static ?int $navigationSort = -3;

    protected ?string $subheading = 'ملخص سريع للعمليات — نفس ألوان واجهة النظام';

    /**
     * @return int | array<string, ?int>
     */
    public function getColumns(): int | array
    {
        return [
            'default' => 1,
            'sm' => 2,
            'lg' => 4,
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            \App\Filament\Admin\Widgets\DashboardHeroWidget::class,
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            \App\Filament\Admin\Widgets\QuickStatsWidget::class,
            \App\Filament\Admin\Widgets\VaultBalancesWidget::class,
        ];
    }
}
