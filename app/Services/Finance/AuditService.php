<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class AuditService
{
    /**
     * تسجيل عملية جديدة
     */
    public function log(array $data): AuditLog
    {
        return AuditLog::create([
            'user_id' => $data['user_id'] ?? \Illuminate\Support\Facades\Auth::id(),
            'action' => $data['action'],
            'model_type' => $data['model_type'] ?? null,
            'model_id' => $data['model_id'] ?? null,
            'ip_address' => $data['ip_address'] ?? request()->ip(),
            'user_agent' => $data['user_agent'] ?? request()->userAgent(),
            'old_values' => $data['old_values'] ?? null,
            'new_values' => $data['new_values'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * الحصول على سجل مستخدم
     */
    public function getUserAuditLog(int $userId, ?int $limit = 100): LengthAwarePaginator
    {
        return AuditLog::where('user_id', $userId)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->paginate($limit);
    }

    /**
     * الحصول على سجل نموذج معين
     */
    public function getModelAuditLog(string $modelType, int $modelId): Collection
    {
        return AuditLog::where('model_type', $modelType)
            ->where('model_id', $modelId)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * تقرير يومي
     */
    public function getDailyReport(Carbon $date): array
    {
        $logs = AuditLog::whereDate('created_at', $date)
            ->with('user')
            ->get()
            ->groupBy(['action', 'user_id']);

        $summary = [];
        foreach ($logs as $action => $users) {
            foreach ($users as $userId => $userLogs) {
                $summary[] = [
                    'action' => $action,
                    'user' => $userLogs->first()->user->name,
                    'count' => $userLogs->count(),
                ];
            }
        }

        return [
            'date' => $date->toDateString(),
            'total_actions' => AuditLog::whereDate('created_at', $date)->count(),
            'summary' => $summary,
        ];
    }
}
