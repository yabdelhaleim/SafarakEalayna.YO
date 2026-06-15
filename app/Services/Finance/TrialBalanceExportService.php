<?php

namespace App\Services\Finance;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use Illuminate\Support\Facades\DB;

class TrialBalanceExportService
{
    public function __construct(
        private TreasuryService $treasuryService
    ) {}

    /**
     * إنشاء ملف إكسيل ميزان الحسابات
     */
    public function export(): Spreadsheet
    {
        $data = $this->treasuryService->getTrialBalance();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setRightToLeft(true);
        $sheet->setTitle('ميزان الحسابات');

        // Styles
        $titleStyle = [
            'font' => [
                'name' => 'Segoe UI',
                'bold' => true,
                'size' => 18,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '1E293B'], // Slate-900
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];

        $sectionHeaderStyle = [
            'font' => [
                'name' => 'Segoe UI',
                'bold' => true,
                'size' => 12,
                'color' => ['rgb' => 'D97706'], // Amber-600
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F8FAFC'], // Slate-50
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_LEFT,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'bottom' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['rgb' => 'E2E8F0'],
                ],
            ],
        ];

        $tableHeaderStyle = [
            'font' => [
                'name' => 'Segoe UI',
                'bold' => true,
                'size' => 11,
                'color' => ['rgb' => 'FFFFFF'],
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '475569'], // Slate-600
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
        ];

        $borderThin = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => 'CBD5E1'],
                ],
            ],
        ];

        // 1. Title Block
        $sheet->mergeCells('A1:D2');
        $sheet->setCellValue('A1', 'ميزان الحسابات الموحد (جرد لحظي لرأس المال)');
        $sheet->getStyle('A1:D2')->applyFromArray($titleStyle);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getRowDimension(2)->setRowHeight(20);

        // Date Info
        $sheet->setCellValue('A3', 'تاريخ الجرد:');
        $sheet->setCellValue('B3', now()->format('Y-m-d H:i:s'));
        $sheet->getStyle('A3')->getFont()->setBold(true);

        // 2. Equation Summary Card (Section)
        $sheet->mergeCells('A5:D5');
        $sheet->setCellValue('A5', ' أولاً: المعادلة المحاسبية لرأس المال الحالي');
        $sheet->getStyle('A5:D5')->applyFromArray($sectionHeaderStyle);
        $sheet->getRowDimension(5)->setRowHeight(28);

        // Table headers for equation
        $sheet->setCellValue('A6', 'البند المحاسبي');
        $sheet->setCellValue('B6', 'القيمة بالجنيه المصري (EGP)');
        $sheet->setCellValue('C6', 'المعادلة الفرعية');
        $sheet->setCellValue('D6', 'ملاحظات');
        $sheet->getStyle('A6:D6')->applyFromArray($tableHeaderStyle);
        $sheet->getRowDimension(6)->setRowHeight(25);

        // Equation rows
        $sheet->setCellValue('A7', 'إجمالي أرصدة الموديولات (طيران + حج/عمرة + تأشيرات)');
        $sheet->setCellValue('B7', $data['total_balances']);
        $sheet->setCellValue('C7', '=SUM(B19:B21)');
        $sheet->setCellValue('D7', 'تجمع أرصدة الموديولات التشغيلية (سيستم + عهد) بالمتوسط');

        $sheet->setCellValue('A8', 'إجمالي السيولة المتاحة (خزن + بنوك + محافظ)');
        $sheet->setCellValue('B8', $data['total_liquidity']);
        $sheet->setCellValue('C8', '=SUM(B24:B28)');
        $sheet->setCellValue('D8', 'تجمع كافة أرصدة النقدية والسيولة بقسم السياحة');

        $sheet->setCellValue('A9', 'المستحق لنا (Receivables)');
        $sheet->setCellValue('B9', $data['due_to_us']);
        $sheet->setCellValue('C9', '=B31+B32');
        $sheet->setCellValue('D9', 'إجمالي مديونيات العملاء والعهد المدينة');

        $sheet->setCellValue('A10', 'المستحق علينا (Payables) - يُطرح');
        $sheet->setCellValue('B10', $data['due_from_us']);
        $sheet->setCellValue('C10', '=B35+B36');
        $sheet->setCellValue('D10', 'إجمالي ديون الموردين والعهد الدائنة');

        // Current Capital Row (Formula)
        $sheet->setCellValue('A11', 'رأس المال الحالي (الفعلي)');
        $sheet->setCellValue('B11', '=(B7+B8+B9)-B10');
        $sheet->setCellValue('C11', '=(أرصدة + سيولة + مستحق لنا) - مستحق علينا');
        $sheet->getStyle('A11:D11')->getFont()->setBold(true)->setSize(11);
        $sheet->getStyle('A11:D11')->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'FEF3C7'], // Amber-100
            ],
        ]);
        $sheet->getStyle('B11')->getNumberFormat()->setFormatCode('#,##0.00');

        $sheet->getStyle('A7:D11')->applyFromArray($borderThin);

        // 3. Balance verification card (Section)
        $sheet->mergeCells('A13:D13');
        $sheet->setCellValue('A13', ' ثانياً: كشف العجز والزيادة (مطابقة التوازن)');
        $sheet->getStyle('A13:D13')->applyFromArray($sectionHeaderStyle);
        $sheet->getRowDimension(13)->setRowHeight(28);

        $sheet->setCellValue('A14', 'رأس المال الأساسي (الافتتاحي)');
        $sheet->setCellValue('B14', $data['base_capital']);
        $sheet->setCellValue('C14', 'مُدخل من الإعدادات العامة');

        $sheet->setCellValue('A15', 'إجمالي الأرباح التشغيلية المحققة');
        $sheet->setCellValue('B15', $data['profits']);
        $sheet->setCellValue('C15', 'تجمع أرباح الحجوزات والبرامج المكتملة');

        $sheet->setCellValue('A16', 'رأس المال المستهدف (المفترض)');
        $sheet->setCellValue('B16', '=B14+B15');
        $sheet->setCellValue('C16', '=الأساسي + الأرباح');
        $sheet->getStyle('A16:C16')->getFont()->setBold(true);
        $sheet->getStyle('B16')->getNumberFormat()->setFormatCode('#,##0.00');

        // Required IF logical statement to compare Current Capital with Expected Capital
        $sheet->setCellValue('A17', 'حالة توازن الحسابات');
        $sheet->setCellValue('B17', '=IF(ROUND(B11-B16,2)=0,"متساوية",IF(B11-B16>0,"يوجد زيادة","يوجد عجز"))');
        $sheet->setCellValue('C17', '=IF(الفعلي - المفترض = 0, "متساوية", ...)');
        $sheet->setCellValue('D17', '=B11-B16'); // الفرق بالأرقام
        $sheet->getStyle('A17:D17')->getFont()->setBold(true)->setSize(12);
        
        $sheet->getStyle('A17:D17')->applyFromArray([
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'ECFDF5'], // Emerald-50
            ],
            'borders' => [
                'outline' => [
                    'borderStyle' => Border::BORDER_MEDIUM,
                    'color' => ['rgb' => '059669'],
                ],
            ],
        ]);
        $sheet->getStyle('A14:D17')->applyFromArray($borderThin);

        // 4. Detailed Component Breakdowns
        $sheet->mergeCells('A18:D18');
        $sheet->setCellValue('A18', ' ثالثاً: تفاصيل بنود ميزان الحسابات');
        $sheet->getStyle('A18:D18')->applyFromArray($sectionHeaderStyle);
        $sheet->getRowDimension(18)->setRowHeight(28);

        // A. Module Balances details
        $sheet->setCellValue('A19', 'أرصدة موديول الطيران (سيستم + ائتمان + حسابات)');
        $sheet->setCellValue('B19', $data['details']['flight_balances']);
        $sheet->setCellValue('A20', 'أرصدة موديول الحج والعمرة (تأمين ودائع موردين)');
        $sheet->setCellValue('B20', $data['details']['hajj_umra_balances']);
        $sheet->setCellValue('A21', 'أرصدة موديول التأشيرات (عهد وكلاء)');
        $sheet->setCellValue('B21', $data['details']['visa_balances']);
        $sheet->getStyle('A19:B21')->applyFromArray($borderThin);

        // Spacer
        $sheet->mergeCells('A22:D22');

        // B. Liquidity details
        $sheet->setCellValue('A23', 'حسابات السيولة بقسم السياحة:');
        $sheet->getStyle('A23')->getFont()->setBold(true);

        // Let's query dynamic liquidity accounts for display
        $accounts = \App\Models\Account::tourism()
            ->tap(fn ($q) => \App\Support\Finance\AccountModuleDivision::applyLiquidityTreasuryScope($q))
            ->where('is_active', true)
            ->get();

        $rowIdx = 24;
        foreach ($accounts as $acc) {
            $sheet->setCellValue('A'.$rowIdx, 'سيولة · '.$acc->name.' ('.$acc->currency.')');
            $sheet->setCellValue('B'.$rowIdx, (float)$acc->balance);
            $sheet->setCellValue('C'.$rowIdx, $acc->currency);
            
            $sheet->getStyle('B'.$rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
            $rowIdx++;
            if ($rowIdx >= 29) break;
        }

        // C. Receivables details
        $sheet->setCellValue('A30', 'المستحقات لنا (المدينون):');
        $sheet->getStyle('A30')->getFont()->setBold(true);

        $custRec = DB::table('accounts')
            ->where('type', 'customer')
            ->where('is_active', true)
            ->where('balance', '>', 0)
            ->get()
            ->sum(fn($acc) => (float)$acc->balance * $this->treasuryService->getAveragePurchaseRate($acc->currency));

        $supRec = DB::table('accounts')
            ->where('type', 'supplier')
            ->where('is_active', true)
            ->where('balance', '>', 0)
            ->whereNotIn('module_type', ['hajj_umra', 'visas'])
            ->get()
            ->sum(fn($acc) => (float)$acc->balance * $this->treasuryService->getAveragePurchaseRate($acc->currency));

        $sheet->setCellValue('A31', 'مديونيات العملاء (أرصدة مدينة)');
        $sheet->setCellValue('B31', $custRec);
        $sheet->setCellValue('A32', 'أرصدة الموردين والشركات المدينة (سداد مقدم)');
        $sheet->setCellValue('B32', $supRec);

        // D. Payables details
        $sheet->setCellValue('A34', 'الالتزامات علينا (الدائنون):');
        $sheet->getStyle('A34')->getFont()->setBold(true);

        $custPay = DB::table('accounts')
            ->where('type', 'customer')
            ->where('is_active', true)
            ->where('balance', '<', 0)
            ->get()
            ->sum(fn($acc) => abs((float)$acc->balance) * $this->treasuryService->getAveragePurchaseRate($acc->currency));

        $supPay = DB::table('accounts')
            ->where('type', 'supplier')
            ->where('is_active', true)
            ->where('balance', '<', 0)
            ->get()
            ->sum(fn($acc) => abs((float)$acc->balance) * $this->treasuryService->getAveragePurchaseRate($acc->currency));

        $sheet->setCellValue('A35', 'أرصدة الموردين والشركات الدائنة (مستحقات معلقة)');
        $sheet->setCellValue('B35', $supPay);
        $sheet->setCellValue('A36', 'التزامات العملاء الدائنة (دفعات مقدمة لم تبدأ)');
        $sheet->setCellValue('B36', $custPay);

        // Apply number formatting to all balance columns
        foreach (range(7, 36) as $row) {
            $sheet->getStyle('B'.$row)->getNumberFormat()->setFormatCode('#,##0.00');
        }

        // 5. Exchange Rates Section
        $sheet->mergeCells('A38:D38');
        $sheet->setCellValue('A38', ' رابعاً: متوسط سعر شراء العملات الأجنبية المحتسب من الحجوزات');
        $sheet->getStyle('A38:D38')->applyFromArray($sectionHeaderStyle);
        $sheet->getRowDimension(38)->setRowHeight(28);

        $sheet->setCellValue('A39', 'زوج العملات');
        $sheet->setCellValue('B39', 'متوسط سعر الشراء الفعلي');
        $sheet->setCellValue('C39', 'طريقة الاحتساب');
        $sheet->getStyle('A39:C39')->applyFromArray($tableHeaderStyle);

        $sheet->setCellValue('A40', 'USD / EGP');
        $sheet->setCellValue('B40', $data['rates']['USD']);
        $sheet->setCellValue('C40', 'متوسط تكلفة حجوزات الطيران بالدولار مقابل الجنيه المصري');

        $sheet->setCellValue('A41', 'SAR / EGP');
        $sheet->setCellValue('B41', $data['rates']['SAR']);
        $sheet->setCellValue('C41', 'متوسط تكلفة حجوزات الطيران بالريال مقابل الجنيه المصري');

        $sheet->setCellValue('A42', 'KWD / EGP');
        $sheet->setCellValue('B42', $data['rates']['KWD']);
        $sheet->setCellValue('C42', 'متوسط تكلفة حجوزات الطيران بالدينار مقابل الجنيه المصري');

        $sheet->getStyle('A40:C42')->applyFromArray($borderThin);
        $sheet->getStyle('B40:B42')->getNumberFormat()->setFormatCode('#,##0.0000');

        // Widths
        $sheet->getColumnDimension('A')->setWidth(45);
        $sheet->getColumnDimension('B')->setWidth(25);
        $sheet->getColumnDimension('C')->setWidth(30);
        $sheet->getColumnDimension('D')->setWidth(45);

        return $spreadsheet;
    }
}
