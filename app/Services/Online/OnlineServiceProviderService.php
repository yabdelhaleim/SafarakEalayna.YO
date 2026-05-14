<?php

namespace App\Services\Online;

use App\Models\Online\OnlineServiceProvider;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OnlineServiceProviderService
{
    public function getAll(array $filters): LengthAwarePaginator
    {
        $query = OnlineServiceProvider::query()->with(['defaultPurchaseAccount', 'createdBy']);

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

    public function getActive()
    {
        return OnlineServiceProvider::active()->with('defaultPurchaseAccount')->get();
    }

    public function getById(int $id): OnlineServiceProvider
    {
        return OnlineServiceProvider::with(['defaultPurchaseAccount', 'createdBy'])->findOrFail($id);
    }

    public function create(array $data): OnlineServiceProvider
    {
        try {
            return DB::transaction(function () use ($data) {
                $provider = OnlineServiceProvider::create(array_merge($data, [
                    'created_by' => Auth::id(),
                    'is_active' => $data['is_active'] ?? true,
                    'order' => $data['order'] ?? 0,
                ]));

                Log::info('Online service provider created', [
                    'provider_id' => $provider->id,
                    'created_by' => Auth::id(),
                ]);

                return $provider->load(['defaultPurchaseAccount', 'createdBy']);
            });
        } catch (\Throwable $e) {
            Log::error('OnlineServiceProviderService::create failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'input' => $data,
            ]);
            throw $e;
        }
    }

    public function update(OnlineServiceProvider $provider, array $data): OnlineServiceProvider
    {
        try {
            return DB::transaction(function () use ($provider, $data) {
                $provider->fill($data)->save();

                Log::info('Online service provider updated', [
                    'provider_id' => $provider->id,
                    'updated_by' => Auth::id(),
                ]);

                return $provider->fresh(['defaultPurchaseAccount', 'createdBy']);
            });
        } catch (\Throwable $e) {
            Log::error('OnlineServiceProviderService::update failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'provider_id' => $provider->id,
                'input' => $data,
            ]);
            throw $e;
        }
    }

    public function delete(OnlineServiceProvider $provider): bool
    {
        if ($provider->transactions()->exists()) {
            throw new \RuntimeException('لا يمكن حذف مزود خدمة مرتبط بمعاملات. يرجى تعطيله بدلاً من حذفه.');
        }

        try {
            return DB::transaction(function () use ($provider) {
                $provider->delete();

                Log::info('Online service provider deleted', [
                    'provider_id' => $provider->id,
                    'deleted_by' => Auth::id(),
                ]);

                return true;
            });
        } catch (\Throwable $e) {
            Log::error('OnlineServiceProviderService::delete failed', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id(),
                'provider_id' => $provider->id,
            ]);
            throw $e;
        }
    }
}
