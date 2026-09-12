<?php

namespace App\Services\Finance;

use App\Http\Resources\Finance\AccountEntryResource;
use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Server-side XLSX export of an account statement.
 *
 * Replaces the client-side CSV builder in AccountStatement.vue which (a) was
 * labelled "Excel" but produced a UTF-8 CSV, (b) only iterated `statement.value`
 * — i.e. the rows currently in the Vue page state, capped at 20 — and (c)
 * never reflected the active filters the user selected on the page.
 *
 * This service goes through AccountService::getAccountStatement() with
 * `per_page=all` so the full filtered set is exported, not just whatever
 * happened to be loaded in the UI. It reuses {@see AccountEntryResource}
 * for the per-row shape so the XLSX stays in lock-step with the JSON
 * payload that powers the on-screen table.
 */
class AccountStatementExportService
{
    public function __construct(
        private AccountService $accountService,
    ) {}

    /**
     * Build the .xlsx spreadsheet for one account.
     *
     * @param  array<string, mixed>  $filters  Same filters the Vue UI sends:
     *                                        search, from_date, to_date,
     *                                        type, module, user_id.
     */
    public function export(Account $account, array $filters): Spreadsheet
    {
        $filters['per_page'] = 'all';

        $data = $this->accountService->getAccountStatement($account, $filters);
        $items = $data['items'];
        $stats = $data['stats'];

        // Reuse the resource so the JSON shape and the XLSX shape stay in sync
        // (description, booking_details, entity_name, etc.). The resource is
        // request-aware but for export we only need toArray()'s side effects —
        // no auth-gated branches are taken here.
        $rows = [];
        foreach ($items as $entry) {
            $rows[] = (new AccountEntryResource($entry))->resolve(new Request());
        }

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle('كشف حساب');

        // ─────────── styles ───────────
        $titleStyle = [
            'font' => ['name' => 'Segoe UI', 'bold' => true, 'size' => 16, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1E293B']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
        ];
        $headerStyle = [
            'font' => ['name' => 'Segoe UI', 'bold' => true, 'size' => 11, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '475569']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
        ];
        $borderThin = [
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'CBD5E1']]],
        ];
        $totalsStyle = [
            'font' => ['name' => 'Segoe UI', 'bold' => true, 'size' => 12],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'FEF3C7']],
        ];

        // ─────────── 1. title + meta ───────────
        $sheet->mergeCells('A1:G2');
        $sheet->setCellValue('A1', sprintf('كشف حساب تفصيلي — %s (%s)', $account->name, $account->currency));
        $sheet->getStyle('A1:G2')->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(28);
        $sheet->getRowDimension(2)->setRowHeight(22);

        $sheet->setCellValue('A3', 'تاريخ التصدير:');
        $sheet->setCellValue('B3', now()->format('Y-m-d H:i:s'));
        $sheet->setCellValue('D3', 'الفترة:');
        $sheet->setCellValue(
            'E3',
            sprintf(
                '%s → %s',
                ! empty($filters['from_date']) ? Carbon::parse($filters['from_date'])->toDateString() : 'البداية',
                ! empty($filters['to_date']) ? Carbon::parse($filters['to_date'])->toDateString() : 'اليوم'
            )
        );
        $sheet->getStyle('A3:E3')->getFont()->setBold(true);

        // ─────────── 2. summary card ───────────
        $sheet->mergeCells('A5:G5');
        $sheet->setCellValue('A5', ' ملخص الفترة');
        $sheet->getStyle('A5:G5')->applyFromArray([
            'font' => ['name' => 'Segoe UI', 'bold' => true, 'size' => 12, 'color' => ['rgb' => 'D97706']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F8FAFC']],
        ]);
        $sheet->getRowDimension(5)->setRowHeight(24);

        $sheet->setCellValue('A6', 'رصيد أول المدة');
        $sheet->setCellValue('B6', (float) $stats['opening_balance']);
        $sheet->setCellValue('C6', 'إجمالي الإيداعات');
        $sheet->setCellValue('D6', (float) $stats['period_credit']);
        $sheet->setCellValue('E6', 'إجمالي المسحوبات');
        $sheet->setCellValue('F6', (float) $stats['period_debit']);
        $sheet->setCellValue('G6', 'رصيد آخر المدة');

        $sheet->getStyle('A7:G7')->getFont()->setBold(true);
        $sheet->setCellValue('A7', '');
        $sheet->setCellValue('B7', (float) $stats['opening_balance']);
        $sheet->setCellValue('C7', '');
        $sheet->setCellValue('D7', (float) $stats['period_credit']);
        $sheet->setCellValue('E7', '');
        $sheet->setCellValue('F7', (float) $stats['period_debit']);
        $sheet->setCellValue('G7', (float) $stats['closing_balance']);
        $sheet->getStyle('A6:G7')->applyFromArray($totalsStyle);
        $sheet->getStyle('B6:B7')->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('D6:D7')->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('F6:F7')->getNumberFormat()->setFormatCode('#,##0.00');
        $sheet->getStyle('G7')->getNumberFormat()->setFormatCode('#,##0.00');

        // ─────────── 3. table header ───────────
        $headerRow = 9;
        $headers = [
            'A' => 'التاريخ',
            'B' => 'القسم / الموظف',
            'C' => 'المرجع / PNR',
            'D' => 'البيان والوصف',
            'E' => 'مدين (-)',
            'F' => 'دائن (+)',
            'G' => 'الرصيد بعد الحركة',
        ];
        foreach ($headers as $col => $label) {
            $sheet->setCellValue($col.$headerRow, $label);
        }
        $sheet->getStyle('A'.$headerRow.':G'.$headerRow)->applyFromArray($headerStyle);
        $sheet->getRowDimension($headerRow)->setRowHeight(28);

        // ─────────── 4. body rows ───────────
        $rowIdx = $headerRow + 1;
        $firstDataRow = $rowIdx;
        foreach ($rows as $r) {
            $moduleLabel = $r['module_label'] ?? $r['module'] ?? 'نظامي';
            $userName = $r['user_name'] ?? 'تلقائي';
            $pnr = $this->resolvePnr($r);
            $description = $this->flatten((string) ($r['description'] ?? $r['notes'] ?? ''));

            $sheet->setCellValue('A'.$rowIdx, $r['created_at'] ?? '');
            $sheet->setCellValue('B'.$rowIdx, $moduleLabel.' — '.$userName);
            $sheet->setCellValue('C'.$rowIdx, $pnr);
            $sheet->setCellValue('D'.$rowIdx, $description);
            $sheet->setCellValue('E'.$rowIdx, (float) ($r['debit'] ?? 0) > 0 ? (float) $r['debit'] : 0);
            $sheet->setCellValue('F'.$rowIdx, (float) ($r['credit'] ?? 0) > 0 ? (float) $r['credit'] : 0);
            $sheet->setCellValue('G'.$rowIdx, (float) ($r['balance_after'] ?? 0));

            $rowIdx++;
        }

        $lastRow = $rowIdx - 1;
        if ($lastRow >= $firstDataRow) {
            $sheet->getStyle('A'.$headerRow.':G'.$lastRow)->applyFromArray($borderThin);
            $sheet->getStyle('A'.$firstDataRow.':A'.$lastRow)
                ->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm');
            $sheet->getStyle('E'.$firstDataRow.':G'.$lastRow)
                ->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('D'.$firstDataRow.':D'.$lastRow)
                ->getAlignment()->setWrapText(true);
        } else {
            $sheet->setCellValue('A'.$firstDataRow, 'لا توجد حركات مطابقة للفترة المحددة.');
            $sheet->mergeCells('A'.$firstDataRow.':G'.$firstDataRow);
            $sheet->getStyle('A'.$firstDataRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $lastRow = $firstDataRow;
        }

        // ─────────── 5. totals row ───────────
        $totalsRow = $lastRow + 2;
        $sheet->setCellValue('A'.$totalsRow, 'الإجماليات في الفترة');
        $sheet->mergeCells('A'.$totalsRow.':D'.$totalsRow);
        if ($lastRow >= $firstDataRow) {
            $sheet->setCellValue('E'.$totalsRow, '=SUM(E'.$firstDataRow.':E'.$lastRow.')');
            $sheet->setCellValue('F'.$totalsRow, '=SUM(F'.$firstDataRow.':F'.$lastRow.')');
        } else {
            $sheet->setCellValue('E'.$totalsRow, 0);
            $sheet->setCellValue('F'.$totalsRow, 0);
        }
        $sheet->setCellValue('G'.$totalsRow, (float) $stats['closing_balance']);
        $sheet->getStyle('A'.$totalsRow.':G'.$totalsRow)->applyFromArray($totalsStyle);
        $sheet->getStyle('A'.$totalsRow.':G'.$totalsRow)->getFont()->setBold(true);
        $sheet->getStyle('E'.$totalsRow.':G'.$totalsRow)
            ->getNumberFormat()->setFormatCode('#,##0.00');

        // ─────────── 6. column widths ───────────
        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(55);
        $sheet->getColumnDimension('E')->setWidth(15);
        $sheet->getColumnDimension('F')->setWidth(15);
        $sheet->getColumnDimension('G')->setWidth(18);

        Log::info('Account statement exported', [
            'account_id' => $account->id,
            'rows' => count($rows),
            'user_id' => auth()->id(),
            'filters' => array_intersect_key($filters, array_flip(['search', 'from_date', 'to_date', 'type', 'module', 'user_id'])),
        ]);

        return $spreadsheet;
    }

    /**
     * Mirrors the Vue precedence: booking_details.pnr → reference_id → '—'.
     */
    private function resolvePnr(array $row): string
    {
        $pnr = $row['booking_details']['pnr']
            ?? $row['reference_id']
            ?? '—';

        return is_string($pnr) ? $pnr : (string) $pnr;
    }

    private function flatten(string $value): string
    {
        // Strip newlines so each row fits a single cell — Excel wraps if
        // column D is wide enough (it is, by design).
        return preg_replace('/\s+/u', ' ', $value) ?? '';
    }
}
