<?php

namespace App\Filament\Admin\Widgets;

use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DashboardChartWidget extends ChartWidget
{
    protected ?string $heading = 'إحصائيات شهرية';

    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return auth()->user() && in_array(auth()->user()->role, ['admin', 'owner'], true);
    }

    protected string $color = 'info';

    protected int | string | array $columnSpan = 'full';

    protected function getData(): array
    {
        if (!Schema::hasTable('transactions')) {
            return [
                'datasets' => [],
                'labels' => [],
            ];
        }

        $months = [];
        $sixMonthsAgo = now()->subMonths(5)->startOfMonth();

        $monthlyStats = DB::table('transactions')
            ->selectRaw("
                DATE_FORMAT(created_at, '%Y-%m') as month_key,
                type,
                SUM(amount) as total
            ")
            ->where('created_at', '>=', $sixMonthsAgo)
            ->whereIn('type', ['income', 'expense'])
            ->groupByRaw('month_key, type')
            ->get()
            ->groupBy('month_key');

        $incomeData = [];
        $expenseData = [];

        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $key = $date->format('Y-m');
            $months[] = $date->format('M');

            $stats = $monthlyStats->get($key, collect());
            $incomeData[] = (float) $stats->firstWhere('type', 'income')?->total ?? 0;
            $expenseData[] = (float) $stats->firstWhere('type', 'expense')?->total ?? 0;
        }

        return [
            'datasets' => [
                [
                    'label' => 'الدخل',
                    'data' => $incomeData,
                    'borderColor' => 'rgb(34, 197, 94)',
                    'backgroundColor' => 'rgba(34, 197, 94, 0.1)',
                    'fill' => true,
                    'tension' => 0.4,
                ],
                [
                    'label' => 'المصروفات',
                    'data' => $expenseData,
                    'borderColor' => 'rgb(239, 68, 68)',
                    'backgroundColor' => 'rgba(239, 68, 68, 0.1)',
                    'fill' => true,
                    'tension' => 0.4,
                ],
            ],
            'labels' => $months,
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => [
                    'display' => true,
                    'position' => 'top',
                    'labels' => [
                        'color' => '#94a3b8',
                        'font' => [
                            'family' => 'IBM Plex Sans Arabic',
                            'size' => 12,
                        ],
                    ],
                ],
            ],
            'scales' => [
                'y' => [
                    'beginAtZero' => true,
                    'grid' => [
                        'color' => 'rgba(255, 255, 255, 0.05)',
                    ],
                    'ticks' => [
                        'color' => '#64748b',
                        'font' => [
                            'family' => 'IBM Plex Sans Arabic',
                        ],
                    ],
                ],
                'x' => [
                    'grid' => [
                        'display' => false,
                    ],
                    'ticks' => [
                        'color' => '#64748b',
                        'font' => [
                            'family' => 'IBM Plex Sans Arabic',
                        ],
                    ],
                ],
            ],
            'maintainAspectRatio' => false,
            'responsive' => true,
        ];
    }
}
