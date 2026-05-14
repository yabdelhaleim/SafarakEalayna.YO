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

    public function index(Request $request)
    {
        $logs = \App\Models\AuditLog::with('user')->latest()->paginate($request->input('per_page', 50));
        return ApiResponse::success('سجل التدقيق', [
            'items' => $logs->items(),
            'pagination' => [
                'total' => $logs->total(),
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'action' => 'required|string',
            'model_type' => 'nullable|string',
            'model_id' => 'nullable|integer',
            'notes' => 'nullable|string',
        ]);

        $log = \App\Models\AuditLog::create(array_merge($validated, [
            'user_id' => $request->user()?->id ?? 1,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]));

        return ApiResponse::success('تم إنشاء سجل التدقيق بنجاح', $log, 201);
    }

    public function show(\App\Models\AuditLog $audit)
    {
        return ApiResponse::success('تفاصيل سجل التدقيق', $audit->load('user'));
    }

    public function update(Request $request, \App\Models\AuditLog $audit)
    {
        $validated = $request->validate([
            'notes' => 'nullable|string',
        ]);

        $audit->update($validated);

        return ApiResponse::success('تم تحديث السجل بنجاح', $audit);
    }

    public function destroy(\App\Models\AuditLog $audit)
    {
        $audit->delete();

        return ApiResponse::success('تم حذف السجل بنجاح');
    }
}
