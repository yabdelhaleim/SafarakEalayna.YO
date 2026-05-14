<?php

namespace App\Http\Controllers\Api\V1\Finance;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\ApprovalWorkflow;
use App\Services\Finance\ApprovalService;
use App\Services\Finance\AuditService;
use Illuminate\Http\Request;

class ApprovalController extends Controller
{
    public function __construct(
        private ApprovalService $approvalService,
        private AuditService $auditService
    ) {}

    /**
     * عرض كل طلبات الموافقة المعلقة
     */
    public function getPendingRequests()
    {
        $this->authorize('approve', ApprovalWorkflow::class);

        $requests = ApprovalWorkflow::pending()
            ->with(['requestedBy', 'approvable'])
            ->latest()
            ->get();

        return ApiResponse::success('طلبات الموافقة المعلقة', $requests);
    }

    /**
     * الموافقة على طلب
     */
    public function approve(Request $request, $id)
    {
        $this->authorize('approve', ApprovalWorkflow::class);

        $workflow = $this->approvalService->approve($id);

        return ApiResponse::success('تمت الموافقة بنجاح', $workflow);
    }

    /**
     * رفض طلب
     */
    public function reject(Request $request, $id)
    {
        $this->authorize('approve', ApprovalWorkflow::class);

        $validated = $request->validate([
            'reason' => 'required|string',
        ]);

        $workflow = $this->approvalService->reject($id, $validated['reason']);

        return ApiResponse::success('تم رفض الطلب', $workflow);
    }

    public function index(Request $request)
    {
        $workflows = ApprovalWorkflow::with(['requestedBy', 'approvable'])
            ->latest()
            ->get();

        return ApiResponse::success('قائمة طلبات الموافقة', $workflows);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'approvable_type' => 'required|string',
            'approvable_id' => 'required|integer',
            'status' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $workflow = ApprovalWorkflow::create(array_merge($validated, [
            'requested_by' => $request->user()?->id ?? 1,
            'status' => $validated['status'] ?? 'pending',
        ]));

        return ApiResponse::success('تم إنشاء طلب الموافقة بنجاح', $workflow, 201);
    }

    public function show(ApprovalWorkflow $approval)
    {
        return ApiResponse::success('تفاصيل طلب الموافقة', $approval->load(['requestedBy', 'approvable']));
    }

    public function update(Request $request, ApprovalWorkflow $approval)
    {
        $validated = $request->validate([
            'status' => 'sometimes|required|string',
            'notes' => 'nullable|string',
        ]);

        $approval->update($validated);

        return ApiResponse::success('تم تحديث طلب الموافقة بنجاح', $approval);
    }

    public function destroy(ApprovalWorkflow $approval)
    {
        $approval->delete();

        return ApiResponse::success('تم حذف طلب الموافقة بنجاح');
    }
}
