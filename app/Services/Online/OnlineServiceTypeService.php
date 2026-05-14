<?php

namespace App\Services\Online;

use App\Models\Online\OnlineServiceType;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OnlineServiceTypeService
{
    public function getAllTypes(array $filters): LengthAwarePaginator
    {
        $query = OnlineServiceType::query()
            ->with('createdBy')
            ->withCount('transactions');

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name_ar', 'like', "%{$search}%")
                    ->orWhere('name_en', 'like', "%{$search}%")
                    ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if (array_key_exists('is_active', $filters) && $filters['is_active'] !== null && $filters['is_active'] !== '') {
            $query->where('is_active', filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN));
        }

        $perPage = min((int) ($filters['per_page'] ?? 15), 100);

        return $query->orderBy('order')->orderBy('name_ar')->paginate($perPage);
    }

    public function getActiveTypes()
    {
        return OnlineServiceType::active()->get();
    }

    public function getById(int $id): OnlineServiceType
    {
        return OnlineServiceType::with('createdBy')->withCount('transactions')->findOrFail($id);
    }

    public function create(array $data): OnlineServiceType
    {
        try {
            return DB::transaction(function () use ($data) {
                $type = OnlineServiceType::create(array_merge($data, [
                    'created_by' => Auth::id(),
                    'is_active' => $data['is_active'] ?? true,
                    'order' => $data['order'] ?? 0,
                ]));

                Log::info('Online service type created', [
                    'service_type_id' => $type->id,
                    'created_by' => Auth::id(),
                ]);

                return $type->load('createdBy')->loadCount('transactions');
            });
        } catch (\Throwable $e) {
            Log::error('OnlineServiceTypeService::create failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'input' => $data,
            ]);
            throw $e;
        }
    }

    public function update(OnlineServiceType $type, array $data): OnlineServiceType
    {
        try {
            return DB::transaction(function () use ($type, $data) {
                $type->fill($data)->save();

                Log::info('Online service type updated', [
                    'service_type_id' => $type->id,
                    'updated_by' => Auth::id(),
                ]);

                return $type->fresh(['createdBy'])->loadCount('transactions');
            });
        } catch (\Throwable $e) {
            Log::error('OnlineServiceTypeService::update failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'service_type_id' => $type->id,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    public function delete(OnlineServiceType $type): bool
    {
        if ($type->transactions()->exists()) {
            throw new \RuntimeException('لا يمكن حذف نوع خدمة مرتبط بمعاملات. يرجى تعطيله بدلاً من حذفه.');
        }

        try {
            return DB::transaction(function () use ($type) {
                $type->delete();

                Log::info('Online service type deleted', [
                    'service_type_id' => $type->id,
                    'deleted_by' => Auth::id(),
                ]);

                return true;
            });
        } catch (\Throwable $e) {
            Log::error('OnlineServiceTypeService::delete failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'service_type_id' => $type->id,
            ]);
            throw $e;
        }
    }
}
