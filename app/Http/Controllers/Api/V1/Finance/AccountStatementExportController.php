<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Services\Finance\AccountStatementExportService;
use Illuminate\Http\Request;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Streams an account statement as a real .xlsx file.
 *
 * Mirrors the URL pattern used by the on-screen statement endpoint:
 *   GET /api/v1/finance/accounts/{account}/statement/export
 *
 * The Vue "تصدير Excel" button hits this endpoint with the active filters
 * (search, from_date, to_date, type, module) so the file always reflects
 * what the user is currently looking at, not just the first 20 rows on the
 * page.
 *
 * Modeled after TreasuryController::exportTrialBalance.
 */
class AccountStatementExportController extends Controller
{
    public function __construct(
        private AccountStatementExportService $exportService,
    ) {}

    public function export(Request $request, Account $account)
    {
        $filters = $request->all();

        $spreadsheet = $this->exportService->export($account, $filters);

        // Skip formula recalculation — the SUM() cells reference the same
        // workbook so they'll evaluate on open in Excel without pre-calc.
        $writer = new Xlsx($spreadsheet);
        $writer->setPreCalculateFormulas(false);

        $safeName = preg_replace('/[^\p{L}\p{N}_\-]+/u', '_', $account->name) ?? 'account';
        $fileName = sprintf('كشف_حساب_%s_%s.xlsx', $safeName, now()->format('Y-m-d'));

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
