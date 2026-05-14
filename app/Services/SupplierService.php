<?php

namespace App\Services;

use App\Models\Supplier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupplierService
{
    public function getAllSuppliers(array $filters = null)
    {
        $query = Supplier::query();

        if ($filters) {
            if (isset($filters['search'])) {
                $query->where('name', 'like', "%{$filters['search']}%")
                    ->orWhere('code', 'like', "%{$filters['search']}%");
            }

            if (isset($filters['type'])) {
                $query->where('type', $filters['type']);
            }

            if (isset($filters['is_active'])) {
                $query->where('is_active', $filters['is_active']);
            }
        }

        return $query->orderBy('name', 'asc');
    }

    public function createSupplier(array $data): Supplier
    {
        return DB::transaction(function () use ($data) {
            $data['code'] = $this->generateSupplierCode($data['type']);

            $supplier = Supplier::create($data);

            Log::info('Supplier created', [
                'supplier_id' => $supplier->id,
                'code' => $supplier->code,
            ]);

            return $supplier->fresh();
        });
    }

    public function updateSupplier(Supplier $supplier, array $data): Supplier
    {
        return DB::transaction(function () use ($supplier, $data) {
            $supplier->update($data);

            Log::info('Supplier updated', [
                'supplier_id' => $supplier->id,
            ]);

            return $supplier->fresh();
        });
    }

    public function deleteSupplier(Supplier $supplier): bool
    {
        return DB::transaction(function () use ($supplier) {
            $supplier->delete();

            Log::info('Supplier deleted', [
                'supplier_id' => $supplier->id,
            ]);

            return true;
        });
    }

    public function getSupplierById(int $id): Supplier
    {
        return Supplier::with(['account', 'createdBy'])->findOrFail($id);
    }

    public function updateDebt(int $supplierId, float $amount, string $operation): Supplier
    {
        return DB::transaction(function () use ($supplierId, $amount, $operation) {
            $supplier = Supplier::where('id', $supplierId)
                ->lockForUpdate()
                ->first();

            $oldDebt = (float) $supplier->current_debt;

            if ($operation === 'add') {
                $supplier->current_debt = $oldDebt + $amount;
            } elseif ($operation === 'subtract') {
                $supplier->current_debt = max(0, $oldDebt - $amount);
            }

            $supplier->save();

            Log::info('Supplier debt updated', [
                'supplier_id' => $supplierId,
                'operation' => $operation,
                'amount' => $amount,
                'old_debt' => $oldDebt,
                'new_debt' => $supplier->current_debt,
            ]);

            return $supplier->fresh();
        });
    }

    public function getSuppliersDebt(): array
    {
        $suppliers = Supplier::where('current_debt', '>', 0)->get();

        return [
            'total_debt' => $suppliers->sum('current_debt'),
            'suppliers_with_debt' => $suppliers->count(),
            'suppliers' => $suppliers->map(function ($supplier) {
                return [
                    'id' => $supplier->id,
                    'name' => $supplier->name,
                    'code' => $supplier->code,
                    'type' => $supplier->type,
                    'debt' => (float) $supplier->current_debt,
                    'credit_limit' => (float) $supplier->credit_limit,
                    'remaining_credit' => (float) ($supplier->credit_limit - $supplier->current_debt),
                ];
            }),
        ];
    }

    protected function generateSupplierCode(string $type): string
    {
        $prefix = match($type) {
            'airline' => 'AL',
            'bus_company' => 'BC',
            'hotel' => 'HT',
            'visa_provider' => 'VP',
            'service_provider' => 'SP',
            default => 'SU',
        };

        $lastSupplier = Supplier::where('code', 'like', "{$prefix}%")
            ->orderBy('code', 'desc')
            ->first();

        if ($lastSupplier) {
            $lastNumber = (int) substr($lastSupplier->code, -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return "{$prefix}-{$newNumber}";
    }
}
