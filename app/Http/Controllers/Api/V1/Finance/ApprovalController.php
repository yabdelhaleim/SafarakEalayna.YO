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
}
