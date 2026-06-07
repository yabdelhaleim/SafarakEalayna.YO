<?php

namespace App\Filament\Widgets;

use App\Models\Booking;
use Filament\Widgets\ChartWidget;

class BookingsChartWidget extends ChartWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 2;

    protected static bool $isLazy = true;

    protected ?string $heading = 'مخطط الحجوزات الشهرية';

    protected function getData(): array
    {
        // Add a slight artificial delay if needed, but not required
        $data = collect(range(1, 12))->map(function ($month) {
            return Booking::whereMonth('created_at', $month)
                ->whereYear('created_at', now()->year)
                ->count();
        })->toArray();

        return [
            'datasets' => [
                [
                    'label' => 'عدد الحجوزات',
                    'data' => $data,
                    'backgroundColor' => '#378ADD',
                    'borderColor' => '#185FA5',
                ],
            ],
            'labels' => ['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
