<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Finance\AuditService;
use App\Services\Finance\TreasuryService;
use Illuminate\Http\Request;

class TreasuryController extends Controller
{
    public function __construct(
        private TreasuryService $treasuryService,
        private AuditService $auditService
    ) {}

    /**
     * نظرة عامة احترافية مقسمة موديولات
     */
    public function getOverview()
    {
        $overview = $this->treasuryService->getTreasuryOverview();
        return ApiResponse::success('نظرة عامة على الخزينة', $overview);
    }

    /**
     * جلب حسابات موديول معين (طرق الدفع)
     */
    public function getModuleAccounts(string $module)
    {
        $accounts = $this->treasuryService->getModuleAccounts($module);
        return ApiResponse::success("حسابات موديول {$module}", $accounts);
    }

    /**
     * عرض كل أرصدة المالك
     */
    public function getOwnerBalances()
    {
        $this->authorize('viewOwnerTreasury', \App\Models\Account::class);

        $balances = $this->treasuryService->getOwnerTreasuryBalances();

        return ApiResponse::success('أرصدة خزينة المالك', $balances);
    }

    /**
     * عرض كل أرصدة المكتب
     */
    public function getOfficeBalances()
    {
        $this->authorize('viewOfficeTreasury', \App\Models\Account::class);

        $balances = $this->treasuryService->getOfficeTreasuryBalances();

        return ApiResponse::success('أرصدة خزينة المكتب', $balances);
    }

    /**
     * إغلاق الدرج
     */
    public function closeDrawer(Request $request)
    {
        $validated = $request->validate([
            'drawer_account_id' => 'required|exists:accounts,id',
            'counted_cash' => 'required|numeric',
            'notes' => 'nullable|string',
        ]);

        $result = $this->treasuryService->closeDrawer(
            $validated['drawer_account_id'],
            $validated['counted_cash'],
            $validated['notes'] ?? ''
        );

        return ApiResponse::success('تم إغلاق الدرج بنجاح', $result);
    }
}
