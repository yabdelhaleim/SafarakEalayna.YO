<?php

namespace App\Services\Finance;

use App\Enums\ApprovalActionType;
use App\Enums\ApprovalStatus;
use App\Enums\TransactionModule;
use App\Enums\TransactionType;
use App\Models\ApprovalWorkflow;
use App\Models\AuditLog;
use App\Models\Transfer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ApprovalService
{
    public function __construct(
        protected AccountingService $accounting,
    ) {}

    /**
     * إنشاء طلب موافقة جديد
     */
    public function createApprovalRequest(Model $approvable, ApprovalActionType $actionType): ApprovalWorkflow
    {
        return DB::transaction(function () use ($approvable, $actionType) {
            $workflow = ApprovalWorkflow::create([
                'approvable_type' => $approvable->getMorphClass(),
                'approvable_id' => $approvable->id,
                'status' => ApprovalStatus::PENDING,
                'action_type' => $actionType->value,
                'requested_by' => Auth::id(),
            ]);

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'create_approval_request',
                'model_type' => ApprovalWorkflow::class,
                'model_id' => $workflow->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'new_values' => [
                    'approvable_type' => $workflow->approvable_type,
                    'approvable_id' => $workflow->approvable_id,
                    'action_type' => $actionType->value,
                ],
            ]);

            return $workflow;
        });
    }

    /**
     * الموافقة على طلب
     */
    public function approve(int $workflowId): ApprovalWorkflow
    {
        return DB::transaction(function () use ($workflowId) {
            $workflow = ApprovalWorkflow::with('approvable')->findOrFail($workflowId);

            if ($workflow->status !== ApprovalStatus::PENDING) {
                throw new \Exception('هذا الطلب ليس بانتظار الموافقة');
            }

            $workflow->update([
                'status' => ApprovalStatus::APPROVED,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
            ]);

            $this->executeApprovedAction($workflow);

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'approve_request',
                'model_type' => ApprovalWorkflow::class,
                'model_id' => $workflow->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'old_values' => ['status' => 'pending'],
                'new_values' => ['status' => 'approved'],
            ]);

            return $workflow->fresh();
        });
    }

    /**
     * رفض طلب
     */
    public function reject(int $workflowId, string $reason): ApprovalWorkflow
    {
        return DB::transaction(function () use ($workflowId, $reason) {
            $workflow = ApprovalWorkflow::findOrFail($workflowId);

            if ($workflow->status !== ApprovalStatus::PENDING) {
                throw new \Exception('هذا الطلب ليس بانتظار الموافقة');
            }

            $workflow->update([
                'status' => ApprovalStatus::REJECTED,
                'approved_by' => Auth::id(),
                'approved_at' => now(),
                'rejection_reason' => $reason,
            ]);

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'reject_request',
                'model_type' => ApprovalWorkflow::class,
                'model_id' => $workflow->id,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'new_values' => ['rejection_reason' => $reason],
            ]);

            return $workflow->fresh();
        });
    }

    /**
     * تنفيذ العملية بعد الموافقة
     */
    private function executeApprovedAction(ApprovalWorkflow $workflow): void
    {
        match ($workflow->action_type) {
            ApprovalActionType::TRANSFER->value => $this->handleApprovedTransferWorkflow($workflow),
            ApprovalActionType::CURRENCY_CONVERSION->value => $this->executeCurrencyConversion($workflow->approvable),
            ApprovalActionType::BOOKING->value => $this->executeBooking($workflow->approvable),
            default => null,
        };
    }

    private function handleApprovedTransferWorkflow(ApprovalWorkflow $workflow): void
    {
        $transfer = $workflow->approvable;
        if (! $transfer instanceof Transfer) {
            throw new \RuntimeException('نوع موافقة TRANSFER لا يطابق Transfer صالحة.');
        }

        $this->executeApprovedLedgerTransfer($transfer, $workflow);
    }

    /**
     * تسجيل التحويل في الدفتر عبر نقطة تعاقد محاسبية واحدة؛ إعادة استخدام صف transfers المعلق.
     */
    private function executeApprovedLedgerTransfer(Transfer $transfer, ApprovalWorkflow $workflow): void
    {
        if ($transfer->transaction_id !== null && (int) $transfer->transaction_id > 0) {
            Log::notice('approval.transfer.skipped_already_ledger_linked', [
                'transfer_id' => $transfer->id,
                'transaction_id' => $transfer->transaction_id,
            ]);

            return;
        }

        $createdBy = $transfer->created_by ?? Auth::id();
        if ($createdBy === null) {
            throw new \RuntimeException('يجب تعريف مستخدم (created_by) لتنفيذ التحويل بعد الموافقة.');
        }

        $this->accounting->recordTransfer([
            'from_account_id' => $transfer->from_account_id,
            'to_account_id' => $transfer->to_account_id,
            'amount' => (float) $transfer->amount,
            'converted_amount' => $transfer->converted_amount !== null ? (float) $transfer->converted_amount : null,
            'exchange_rate' => $transfer->exchange_rate !== null ? (float) $transfer->exchange_rate : null,
            'module' => TransactionModule::General->value,
            'type' => TransactionType::Transfer->value,
            'notes' => $transfer->notes,
            'created_by' => (int) $createdBy,
            'approval_workflow_id' => $workflow->id,
            'reuse_transfer_id' => $transfer->id,
        ]);
    }

    /**
     * تنفيذ تحويل العملة بعد الموافقة
     */
    private function executeCurrencyConversion($conversion): void
    {
        // TODO: تنفيذ تحويل العملة
    }

    /**
     * تنفيذ الحجز بعد الموافقة
     */
    private function executeBooking($booking): void
    {
        // TODO: تأكيد الحجز
    }
}
