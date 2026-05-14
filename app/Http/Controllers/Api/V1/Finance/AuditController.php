<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Finance\AuditService;
use Illuminate\Http\Request;

class AuditController extends Controller
{
    public function __construct(
        private AuditService $auditService
    ) {}

    /**
     * سجل مستخدم
     */
    public function getUserLog(Request $request, $userId)
    {
        $this->authorize('viewAuditLog', [\App\Models\AuditLog::class, $userId]);

        $logs = $this->auditService->getUserAuditLog(
            $userId,
            $request->input('per_page', 50)
        );

        return ApiResponse::success('سجل المستخدم', $logs);
    }

    /**
     * سجل نموذج
     */
    public function getModelLog(Request $request)
    {
        $validated = $request->validate([
            'model_type' => 'required|string',
            'model_id' => 'required|integer',
        ]);

        $logs = $this->auditService->getModelAuditLog(
            $validated['model_type'],
            $validated['model_id']
        );

        return ApiResponse::success('سجل النموذج', $logs);
    }

    /**
     * تقرير يومي
     */
    public function getDailyReport(Request $request)
    {
        $validated = $request->validate([
            'date' => 'nullable|date',
        ]);

        $date = $validated['date'] ? \Carbon\Carbon::parse($validated['date']) : now();

        $report = $this->auditService->getDailyReport($date);

        return ApiResponse::success('التقرير اليومي', $report);
    }
}
