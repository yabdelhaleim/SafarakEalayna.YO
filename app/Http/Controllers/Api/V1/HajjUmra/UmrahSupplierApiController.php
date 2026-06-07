<?php

namespace App\Http\Controllers\Api\V1\HajjUmra;

use App\Enums\AccountType;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\HajjUmra\UmrahSupplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UmrahSupplierApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $suppliers = UmrahSupplier::with('account')
            ->orderBy('name')
            ->get()
            ->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'phone' => $s->phone,
                'account_id' => $s->account_id,
                'account_name' => $s->account?->name,
                'supplier_cost_price' => (float) ($s->default_cost_price ?? 0),
                'is_active' => (bool) $s->is_active,
            ]);

        return ApiResponse::success('قائمة موردي العمرة', $suppliers);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'default_cost_price' => ['nullable', 'numeric', 'min:0'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
        ]);

        $accountId = $data['account_id'] ?? null;
        if (! $accountId) {
            $account = Account::create([
                'name' => 'حساب مورد العمرة: '.$data['name'],
                'type' => AccountType::Supplier,
                'balance' => 0.00,
                'currency' => 'EGP',
                'is_active' => true,
                'module_type' => 'hajj_umra',
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'notes' => 'حساب مورد تلقائي مضاف من قسم العمرة',
                'created_by' => auth()->id() ?? 1,
            ]);
            $accountId = $account->id;
        }

        $supplier = UmrahSupplier::create([
            'name' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'default_cost_price' => $data['default_cost_price'] ?? 0.00,
            'account_id' => $accountId,
            'is_active' => true,
        ]);

        return ApiResponse::success('تم إضافة مورد العمرة بنجاح', [
            'id' => $supplier->id,
            'name' => $supplier->name,
            'phone' => $supplier->phone,
            'supplier_cost_price' => (float) $supplier->default_cost_price,
            'account_id' => $supplier->account_id,
        ], 201);
    }
}
