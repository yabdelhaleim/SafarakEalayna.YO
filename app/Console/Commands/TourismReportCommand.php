<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\Online\OnlineTransaction;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature(
    signature: 'tourism:report
        {--from= : من تاريخ (Y-m-d) - افتراضي أول يوم في الشهر الحالي}
        {--to= : إلى تاريخ (Y-m-d) - افتراضي اليوم}
        {--limit=10 : عدد أعلى المديونين/الموظفين/أنواع الخدمة المعروضة}
        {--json : طباعة JSON بدل الجدول}',
)]
class TourismReportCommand extends Command
{
    protected $description = 'تقرير شامل لموديول الخدمات الإلكترونية (السياحة): الأرباح، الديون، توزيع الموظفين وأنواع الخدمة، ومصدر الربح من دفتر الأستاذ';

    public function handle(): int
    {
        $from = $this->option('from') ?: now()->startOfMonth()->format('Y-m-d');
        $to   = $this->option('to')   ?: now()->format('Y-m-d');
        $limit = (int) ($this->option('limit') ?: 10);
        $asJson = (bool) $this->option('json');

        $totals = $this->getColumnTotals($from, $to);
        $gl     = $this->getGlProfit($from, $to);

        $columnProfit = (float) $totals->total_profit;
        $glIncome     = (float) ($gl->income ?? 0);
        $glExpense    = (float) ($gl->expense ?? 0);
        $glProfit     = $glIncome - $glExpense;
        $drift        = round($columnProfit - $glProfit, 2);

        $payload = [
            'period' => ['from' => $from, 'to' => $to],
            'column_source' => [
                'total_transactions' => (int) $totals->total_tx,
                'total_cost'         => (float) $totals->total_cost,
                'total_revenue'      => (float) $totals->total_revenue,
                'total_profit'       => $columnProfit,
                'total_outstanding_debt' => (float) $totals->total_debt,
            ],
            'gl_source' => [
                'income'        => $glIncome,
                'expense'       => $glExpense,
                'profit'        => $glProfit,
                'drift_vs_column' => $drift,
                'note'          => 'الرقم في الداشبورد = ربح GL (دفتر الأستاذ). أي فرق = عمليات soft-deleted أو قيود عكسية.',
            ],
            'top_debtors' => $this->getTopDebtors($limit),
            'by_employee' => $this->getByEmployee($from, $to, $limit),
            'by_service_type' => $this->getByServiceType($limit),
            'by_status_breakdown' => $this->getStatusBreakdown($from, $to),
            'last_operations' => $this->getLastOperations(10),
        ];

        if ($asJson) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->renderHumanReadable($payload);

        return self::SUCCESS;
    }

    private function getColumnTotals(string $from, string $to): object
    {
        return DB::selectOne("
            SELECT
                COUNT(*) AS total_tx,
                COALESCE(SUM(purchase_price), 0) AS total_cost,
                COALESCE(SUM(selling_price), 0) AS total_revenue,
                COALESCE(SUM(profit), 0) AS total_profit,
                COALESCE(SUM(selling_price - COALESCE(amount_paid, selling_price)), 0) AS total_debt
            FROM online_transactions
            WHERE status = 'completed' AND deleted_at IS NULL
              AND DATE(created_at) BETWEEN ? AND ?
        ", [$from, $to]);
    }

    private function getGlProfit(string $from, string $to): object
    {
        return DB::selectOne("
            SELECT
                SUM(CASE WHEN ae.entry_type = 'credit' THEN ae.amount ELSE 0 END) AS income,
                SUM(CASE WHEN ae.entry_type = 'debit'  THEN ae.amount ELSE 0 END) AS expense
            FROM account_entries ae
            JOIN transactions t ON t.id = ae.transaction_id
            WHERE t.module = 'online'
              AND DATE(ae.created_at) BETWEEN ? AND ?
        ", [$from, $to]) ?? (object) ['income' => 0, 'expense' => 0];
    }

    private function getTopDebtors(int $limit): array
    {
        return DB::select("
            SELECT
                customer_id, customer_name,
                COUNT(*) AS tx_count,
                SUM(selling_price) AS sales,
                SUM(COALESCE(amount_paid, selling_price)) AS paid,
                SUM(selling_price - COALESCE(amount_paid, selling_price)) AS debt
            FROM online_transactions
            WHERE status = 'completed' AND deleted_at IS NULL
            GROUP BY customer_id, customer_name
            HAVING debt > 0
            ORDER BY debt DESC
            LIMIT {$limit}
        ");
    }

    private function getByEmployee(string $from, string $to, int $limit): array
    {
        $rows = DB::select("
            SELECT employee_id, COUNT(*) cnt, SUM(selling_price) rev, SUM(profit) profit
            FROM online_transactions
            WHERE status = 'completed' AND deleted_at IS NULL
              AND DATE(created_at) BETWEEN ? AND ?
            GROUP BY employee_id ORDER BY profit DESC LIMIT {$limit}
        ", [$from, $to]);

        foreach ($rows as $r) {
            $r->employee_name = Employee::find($r->employee_id)?->name ?? '(بدون)';
        }

        return $rows;
    }

    private function getByServiceType(int $limit): array
    {
        return DB::select("
            SELECT service_type_code, COUNT(*) cnt, SUM(selling_price) rev, SUM(profit) profit
            FROM online_transactions
            WHERE status = 'completed' AND deleted_at IS NULL
            GROUP BY service_type_code ORDER BY profit DESC LIMIT {$limit}
        ");
    }

    private function getStatusBreakdown(string $from, string $to): array
    {
        return DB::select("
            SELECT status,
                   COUNT(*) cnt,
                   COALESCE(SUM(selling_price), 0) AS revenue,
                   COALESCE(SUM(profit), 0) AS profit
            FROM online_transactions
            WHERE deleted_at IS NULL
              AND DATE(created_at) BETWEEN ? AND ?
            GROUP BY status
        ", [$from, $to]);
    }

    private function getLastOperations(int $limit): array
    {
        return DB::select("
            SELECT id, customer_name, service_type_code, purchase_price, selling_price,
                   amount_paid, profit, status, created_at
            FROM online_transactions
            WHERE deleted_at IS NULL
            ORDER BY id DESC LIMIT {$limit}
        ");
    }

    private function renderHumanReadable(array $p): void
    {
        $c = $p['column_source'];
        $g = $p['gl_source'];

        $this->line(str_repeat('═', 70));
        $this->info('📊 تقرير الخدمات الإلكترونية (السياحة)');
        $this->line('الفترة: '.($p['period']['from'] ?? '?').' → '.($p['period']['to'] ?? '?'));
        $this->line(str_repeat('═', 70));
        $this->newLine();

        $this->info('🔹 مصدر العمود (online_transactions):');
        $this->line("   عدد العمليات       : {$c['total_transactions']}");
        $this->line("   تكلفة (شراء)       : {$c['total_cost']}");
        $this->line("   مبيعات (بيع)       : {$c['total_revenue']}");
        $this->line("   💰 صافي الربح      : {$c['total_profit']}");
        $this->line("   💸 ديون متبقية     : {$c['total_outstanding_debt']}");
        $this->newLine();

        $this->info('🔹 مصدر دفتر الأستاذ (GL) - الرقم الموثوق في الداشبورد:');
        $this->line("   دخل                : {$g['income']}");
        $this->line("   مصروف              : {$g['expense']}");
        $this->line("   💰 ربح GL          : {$g['profit']}");
        $driftSign = $g['drift_vs_column'] > 0 ? '+' : '';
        $this->line("   🔎 فرق (عمود - GL) : {$driftSign}{$g['drift_vs_column']}");
        $this->comment("   {$g['note']}");
        $this->newLine();

        $this->info('🔹 توزيع حسب الحالة (في الفترة):');
        $rows = [];
        foreach ($p['by_status_breakdown'] as $s) {
            $rows[] = [$s->status, $s->cnt, $s->revenue, $s->profit];
        }
        $this->table(['الحالة', 'العدد', 'الإيراد', 'الربح'], $rows);
        $this->newLine();

        $this->info('🔹 أعلى المديونين:');
        $rows = [];
        foreach ($p['top_debtors'] as $d) {
            $rows[] = [
                $d->customer_name ?: ('#'.$d->customer_id),
                $d->tx_count,
                $d->sales,
                $d->paid,
                $d->debt,
            ];
        }
        $this->table(['العميل', 'عمليات', 'مبيعات', 'مدفوع', 'الدين'], $rows);
        $this->newLine();

        $this->info('🔹 ربح حسب الموظف:');
        $rows = [];
        foreach ($p['by_employee'] as $e) {
            $rows[] = [$e->employee_name, $e->cnt, $e->rev, $e->profit];
        }
        $this->table(['الموظف', 'عمليات', 'مبيعات', 'ربح'], $rows);
        $this->newLine();

        $this->info('🔹 ربح حسب نوع الخدمة:');
        $rows = [];
        foreach ($p['by_service_type'] as $r) {
            $rows[] = [$r->service_type_code, $r->cnt, $r->rev, $r->profit];
        }
        $this->table(['النوع', 'عمليات', 'مبيعات', 'ربح'], $rows);
        $this->newLine();

        $this->info('🔹 آخر 10 عمليات:');
        $rows = [];
        foreach ($p['last_operations'] as $o) {
            $rows[] = [
                '#'.$o->id,
                $o->created_at,
                $o->customer_name ?? '-',
                $o->service_type_code,
                $o->selling_price,
                $o->amount_paid ?? '-',
                $o->profit,
                $o->status,
            ];
        }
        $this->table(['#', 'التاريخ', 'العميل', 'النوع', 'بيع', 'مدفوع', 'ربح', 'حالة'], $rows);
    }
}
