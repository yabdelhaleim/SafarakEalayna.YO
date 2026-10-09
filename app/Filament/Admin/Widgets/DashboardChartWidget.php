<?php

namespace App\Filament\Admin\Widgets;

use App\Services\Reports\ReportFinanceService;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Schema;

class DashboardChartWidget extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected ?string $heading = 'إحصائيات شهرية';

    protected static ?int $sort = 3;

    public static function canView(): bool
    {
        return auth()->user() && in_array(auth()->user()->role, ['admin', 'owner'], true);
    }

    protected string $color = 'info';

    protected int|string|array $columnSpan = 'full';

    protected function getData(): array
    {
        if (! Schema::hasTable('transactions')) {
            return [
                'datasets' => [],
                'labels' => [],
            ];
        }

        $labels = [];
        $incomeData = [];
        $expenseData = [];

        // Pull each of the last 6 calendar months through the corrected
        // ReportFinanceService::getDailyFinancialChart() so that
        // `type='transfer'` rows (cash-basis tourism revenue and
        // COGS / prepaid-consumption) are included. The previous
        // `whereIn('type', ['income','expense'])` query silently
        // dropped every flight / hajj / visa sale.
        $service = app(ReportFinanceService::class);

        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $monthKey = $date->format('Y-m');
            $labels[] = $date->format('M');

            $from = $date->copy()->startOfMonth()->toDateString();
            $to = $date->copy()->endOfMonth()->toDateString();

            $rows = $service->getDailyFinancialChart([
                'from_date' => $from,
                'to_date' => $to,
            ]);

            $incomeData[] = round((float) $rows->sum('total_income'), 2);
            $expenseData[] = round((float) $rows->sum('total_expense'), 2);
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
            'labels' => $labels,
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
