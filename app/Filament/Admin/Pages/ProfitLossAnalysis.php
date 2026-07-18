<?php

namespace App\Filament\Admin\Pages;

use App\Services\Reports\ProfitLossReportService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * Tourism P&L Analysis Page (FIX TO-GAP-002, added 2026-07-16)
 *
 * Full-page analysis of profits and losses across the tourism division
 * (Flight + HajjUmra + Visa). Each row in the table corresponds to a
 * single transaction-level line of income/cogs/expenses, with the
 * related booking/payment ref for traceability.
 *
 * The data is computed by ProfitLossReportService::moduleBreakdown()
 * which classifies each transaction based on the GL clearing accounts
 * (إقفال إيرادات / إقفال تكاليف). This is the same engine that powers
 * the FinancialReportController::profit endpoints, ensuring all P&L views
 * in the system agree to the cent.
 */
class ProfitLossAnalysis extends Page implements HasForms
{
    use InteractsWithForms;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $title = 'تحليل الأرباح والخسائر (السياحة)';

    protected static ?string $navigationLabel = 'الأرباح والخسائر';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير المالية';

    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.admin.pages.profit-loss-analysis';

    public ?string $fromDate = null;
    public ?string $toDate = null;
    public ?string $module = 'all';

    public array $modules = [];
    public array $daily = [];
    public array $totals = [
        'total_income' => 0,
        'total_cogs' => 0,
        'total_operating_expenses' => 0,
        'total_profit' => 0,
        'gross_profit' => 0,
        'net_profit' => 0,
        'profit_margin' => 0,
    ];

    public function mount(): void
    {
        $this->fromDate = now()->startOfMonth()->toDateString();
        $this->toDate = now()->toDateString();
        $this->loadData();
    }

    public function loadData(): void
    {
        try {
            $plService = app(ProfitLossReportService::class);
            $breakdown = $plService->moduleBreakdown([
                'from_date' => $this->fromDate,
                'to_date' => $this->toDate,
            ]);

            $byModule = $breakdown['by_module'] ?? [];
            $this->modules = $byModule;
            $this->daily = $breakdown['daily'] ?? [];

            $totalIncome = 0.0; $totalCogs = 0.0; $totalOpEx = 0.0;
            foreach ($byModule as $row) {
                $totalIncome += (float)($row['income'] ?? 0);
                $totalCogs += (float)($row['cogs'] ?? 0);
                $totalOpEx += (float)($row['expenses'] ?? 0);
            }
            $grossProfit = $totalIncome - $totalCogs;
            $netProfit = $grossProfit - $totalOpEx;

            $this->totals = [
                'total_income' => round($totalIncome, 2),
                'total_cogs' => round($totalCogs, 2),
                'total_operating_expenses' => round($totalOpEx, 2),
                'total_profit' => round($netProfit, 2),
                'gross_profit' => round($grossProfit, 2),
                'net_profit' => round($netProfit, 2),
                'profit_margin' => $totalIncome > 0 ? round(($netProfit / $totalIncome) * 100, 2) : 0,
            ];
        } catch (\Throwable $e) {
            Notification::make()
                ->title('فشل تحميل البيانات')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label('تحديث')
                ->icon('heroicon-o-arrow-path')
                ->action('loadData'),
        ];
    }
}