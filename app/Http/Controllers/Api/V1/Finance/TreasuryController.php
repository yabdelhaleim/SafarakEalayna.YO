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

    public function index(Request $request)
    {
        $accounts = \App\Models\Account::whereIn('type', ['treasury', 'cashbox'])
            ->active()
            ->get();

        return ApiResponse::success('قائمة الخزائن', $accounts);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|string',
            'balance' => 'numeric|min:0',
            'currency' => 'required|string|size:3',
            'notes' => 'nullable|string',
        ]);

        $validated['owner_type'] = 'office';
        $validated['is_active'] = true;

        $accountService = app(\App\Services\Finance\AccountService::class);
        $account = $accountService->createAccount($validated);

        return ApiResponse::success('تم إنشاء الخزينة بنجاح', $account, 201);
    }

    public function show(\App\Models\Account $treasury)
    {
        return ApiResponse::success('تفاصيل الخزينة', $treasury);
    }

    public function update(Request $request, \App\Models\Account $treasury)
    {
        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'currency' => 'sometimes|required|string|size:3',
            'is_active' => 'boolean',
            'notes' => 'nullable|string',
        ]);

        $accountService = app(\App\Services\Finance\AccountService::class);
        $treasury = $accountService->updateAccount($treasury, $validated);

        return ApiResponse::success('تم تحديث الخزينة بنجاح', $treasury);
    }

    public function destroy(\App\Models\Account $treasury)
    {
        $accountService = app(\App\Services\Finance\AccountService::class);
        $accountService->deactivateAccount($treasury);

        return ApiResponse::success('تم تعطيل الخزينة بنجاح');
    }
}
