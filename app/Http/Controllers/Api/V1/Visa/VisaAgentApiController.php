<?php

namespace App\Http\Controllers\Api\V1\Visa;

use App\Enums\AccountType;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\HajjUmra\VisaAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisaAgentApiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $agents = VisaAgent::with('account')
            ->orderBy('company_name')
            ->get()
            ->map(fn ($v) => [
                'id' => $v->id,
                'name' => $v->company_name ?: $v->contact_person,
                'company_name' => $v->company_name,
                'contact_person' => $v->contact_person,
                'phone' => $v->phone,
                'email' => $v->email,
                'country' => $v->country,
                'visa_type' => $v->visa_type,
                'default_cost_price' => (float) ($v->default_cost_price ?? 0),
                'account_id' => $v->account_id,
                'account_name' => $v->account?->name,
                'is_active' => (bool) $v->is_active,
            ]);

        return ApiResponse::success('قائمة وكلاء التأشيرات', $agents);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:20'],
            'visa_type' => ['nullable', 'string', 'max:50'],
            'default_cost_price' => ['nullable', 'numeric', 'min:0'],
            'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
        ]);

        // If no account_id is provided, automatically create a Supplier account for the agent
        $accountId = $data['account_id'] ?? null;
        if (! $accountId) {
            $account = Account::create([
                'name' => 'حساب وكيل التأشيرة: '.$data['name'],
                'type' => AccountType::Supplier,
                'balance' => 0.00,
                'currency' => 'EGP',
                'is_active' => true,
                'module_type' => 'visas',
                'owner_type' => Account::OWNER_TYPE_OWNER,
                'notes' => 'حساب وكيل تلقائي مضاف من إدارة الوكلاء',
                'created_by' => auth()->id() ?? 1,
            ]);
            $accountId = $account->id;
        }

        $agent = VisaAgent::create([
            'company_name' => $data['name'],
            'contact_person' => $data['name'],
            'phone' => $data['phone'] ?? null,
            'visa_type' => $data['visa_type'] ?? null,
            'default_cost_price' => $data['default_cost_price'] ?? 0.00,
            'account_id' => $accountId,
            'is_active' => true,
        ]);

        return ApiResponse::success('تم إضافة الوكيل بنجاح', [
            'id' => $agent->id,
            'name' => $agent->company_name,
            'phone' => $agent->phone,
            'visa_type' => $agent->visa_type,
            'default_cost_price' => (float) $agent->default_cost_price,
            'account_id' => $agent->account_id,
        ], 201);
    }

    public function costPrice(Request $request, $id): JsonResponse
    {
        $agent = VisaAgent::findOrFail($id);

        return ApiResponse::success('سعر تكلفة الوكيل', [
            'agent_id' => (int) $agent->id,
            'cost_price' => (float) ($agent->default_cost_price ?? 0),
            'visa_type' => $agent->visa_type,
        ]);
    }
}
