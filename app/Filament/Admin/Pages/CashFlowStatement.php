<?php

namespace App\Filament\Admin\Pages;

use App\Models\Account;
use App\Models\AccountEntry;
use App\Services\Finance\TreasuryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Notifications\Notification;

/**
 * Cash Flow Statement Page (FIX TO-GAP-CashFlow, added 2026-07-16)
 *
 * Independent page for visualizing cash flows. Unlike the realtime
 * cashFlowRealtime() endpoint which only shows a 24h snapshot, this
 * page provides:
 *   - Date-range filtering
 *   - Per-account breakdown
 *   - Daily/weekly/monthly grouping
 *   - Tourism vs office filtering
 *
 * Data source: the same `account_entries` table that backs the trial
 * balance, ensuring all reports agree to the cent.
 */
class CashFlowStatement extends Page implements HasForms
{
    use InteractsWithForms;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $title = 'كشف التدفقات النقدية';

    protected static ?string $navigationLabel = 'التدفقات النقدية';

    protected static string|\UnitEnum|null $navigationGroup = 'التقارير المالية';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.admin.pages.cash-flow-statement';

    public ?string $fromDate = null;
    public ?string $toDate = null;
    public string $groupBy = 'daily';
    public string $division = 'tourism';

    public array $summary = [
        'opening_balance' => 0,
        'total_inflow' => 0,
        'total_outflow' => 0,
        'net_change' => 0,
        'closing_balance' => 0,
    ];
    public array $movements = [];   // grouped by date
    public array $accounts = [];    // per-account net

    public function mount(): void
    {
        $this->fromDate = now()->startOfMonth()->toDateString();
        $this->toDate = now()->toDateString();
        $this->loadData();
    }

    public function loadData(): void
    {
        try {
            $movs = AccountEntry::query()
                ->join('accounts as a', 'a.id', '=', 'account_entries.account_id')
                ->join('transactions as t', 't.id', '=', 'account_entries.transaction_id')
                ->where('a.is_active', 1)
                ->whereIn('a.type', ['cashbox', 'bank', 'wallet'])
                ->whereNotNull('t.module')
                ->whereBetween('account_entries.created_at', [
                    $this->fromDate . ' 00:00:00',
                    $this->toDate . ' 23:59:59',
                ])
                ->select([
                    'account_entries.id',
                    'account_entries.created_at',
                    'account_entries.debit',
                    'account_entries.credit',
                    'a.id as account_id',
                    'a.name as account_name',
                    'a.type as account_type',
                    'a.module_type as account_module_type',
                    'a.balance as current_balance',
                    't.module as tx_module',
                ])
                ->get();

            // Filter by division
            if ($this->division === 'tourism') {
                $movs = $movs->filter(function ($m) {
                    $mt = strtolower((string) ($m->account_module_type ?? ''));
                    return in_array($mt, ['tourism', 'flight', 'hajj_umra', 'visa'], true);
                });
            } else {
                $movs = $movs->filter(function ($m) {
                    $mt = strtolower((string) ($m->account_module_type ?? ''));
                    return in_array($mt, ['office', 'bus', 'fawry', 'online', 'wallet_transfer'], true);
                });
            }

            // Group by date
            $byDate = [];
            $byAccount = [];
            $totalInflow = 0; $totalOutflow = 0;
            foreach ($movs as $m) {
                $date = substr((string) $m->created_at, 0, 10);
                if ($this->groupBy === 'weekly') {
                    $date = date('Y-W', strtotime($date));
                } elseif ($this->groupBy === 'monthly') {
                    $date = substr($date, 0, 7);
                }
                $key = $date;
                if (! isset($byDate[$key])) {
                    $byDate[$key] = ['period' => $key, 'inflow' => 0, 'outflow' => 0, 'net' => 0, 'count' => 0];
                }
                // cashbox/bank: debit=inflow, credit=outflow
                $inflow = (float) $m->debit;
                $outflow = (float) $m->credit;
                $byDate[$key]['inflow'] += $inflow;
                $byDate[$key]['outflow'] += $outflow;
                $byDate[$key]['net'] += ($inflow - $outflow);
                $byDate[$key]['count']++;
                $totalInflow += $inflow;
                $totalOutflow += $outflow;

                if (! isset($byAccount[$m->account_id])) {
                    $byAccount[$m->account_id] = [
                        'account_id' => $m->account_id,
                        'account_name' => $m->account_name,
                        'account_type' => $m->account_type,
                        'inflow' => 0, 'outflow' => 0, 'net' => 0,
                        'current_balance' => (float) $m->current_balance,
                        'transaction_count' => 0,
                    ];
                }
                $byAccount[$m->account_id]['inflow'] += $inflow;
                $byAccount[$m->account_id]['outflow'] += $outflow;
                $byAccount[$m->account_id]['net'] += ($inflow - $outflow);
                $byAccount[$m->account_id]['transaction_count']++;
            }
            ksort($byDate);
            krsort($byAccount); // highest net first
            $this->movements = array_values($byDate);
            $this->accounts = array_values($byAccount);

            // Compute opening balance (sum of current balances minus net change)
            $totalCurrent = 0.0;
            foreach ($this->accounts as $a) {
                $totalCurrent += (float) $a['current_balance'];
            }
            $netChange = $totalInflow - $totalOutflow;
            $closingBalance = $totalCurrent;
            $openingBalance = $closingBalance - $netChange;

            $this->summary = [
                'opening_balance' => round($openingBalance, 2),
                'total_inflow' => round($totalInflow, 2),
                'total_outflow' => round($totalOutflow, 2),
                'net_change' => round($netChange, 2),
                'closing_balance' => round($closingBalance, 2),
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