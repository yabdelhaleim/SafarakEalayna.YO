<?php

namespace App\Http\Controllers\Api\V1\Online;

use App\Enums\OnlineTransactionStatus;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Online\OnlineServiceProvider;
use App\Models\Online\OnlineServiceType;
use App\Models\Setting\PaymentMethod;
use Illuminate\Http\JsonResponse;

class OnlineSettingsController extends Controller
{
    public function serviceTypes(): JsonResponse
    {
        $types = OnlineServiceType::active()->get()->map(fn (OnlineServiceType $t) => [
            'id' => $t->id,
            'value' => $t->code,
            'code' => $t->code,
            'label' => $t->name_ar,
            'labelEn' => $t->name_en,
            'description' => $t->description_ar,
            'color' => $t->color,
            'icon' => $t->icon,
            'order' => $t->order,
        ]);

        return ApiResponse::success('تم جلب أنواع الخدمات النشطة.', $types);
    }

    public function providers(): JsonResponse
    {
        $providers = OnlineServiceProvider::active()->with('defaultPurchaseAccount')->get()->map(fn (OnlineServiceProvider $p) => [
            'id' => $p->id,
            'value' => $p->code,
            'code' => $p->code,
            'label' => $p->name_ar,
            'labelEn' => $p->name_en,
            'description' => $p->description_ar,
            'color' => $p->color,
            'icon' => $p->icon,
            'contact_phone' => $p->contact_phone,
            'contact_account' => $p->contact_account,
            'default_purchase_account_id' => $p->default_purchase_account_id,
            'default_purchase_account' => $p->defaultPurchaseAccount?->only(['id', 'name', 'type']),
            'order' => $p->order,
        ]);

        return ApiResponse::success('تم جلب مزودي الخدمات النشطين.', $providers);
    }

    public function paymentMethods(): JsonResponse
    {
        $methods = PaymentMethod::active()->get()->map(fn (PaymentMethod $m) => [
            'id' => $m->id,
            'value' => $m->code,
            'code' => $m->code,
            'label' => $m->name_ar,
            'labelEn' => $m->name_en,
            'color' => $m->color,
            'order' => $m->order,
        ]);

        return ApiResponse::success('تم جلب طرق الدفع النشطة.', $methods);
    }

    public function accounts(): JsonResponse
    {
        $accounts = Account::active()
            ->where('module_type', 'online')
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'balance', 'currency', 'wallet_provider', 'wallet_number', 'is_active'])
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type,
                'balance' => (float) $a->balance,
                'currency' => $a->currency,
                'wallet_provider' => $a->wallet_provider,
                'wallet_number' => $a->wallet_number,
            ]);

        return ApiResponse::success('تم جلب الحسابات النشطة.', $accounts);
    }

    public function customers(): JsonResponse
    {
        $customers = Customer::query()
            ->orderBy('full_name')
            ->limit(500)
            ->get(['id', 'full_name', 'phone', 'email'])
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->full_name,
                'phone' => $c->phone,
                'email' => $c->email,
            ]);

        return ApiResponse::success('تم جلب العملاء.', $customers);
    }

    public function employees(): JsonResponse
    {
        $employees = Employee::query()
            ->with('user:id,name')
            ->where('status', 'active')
            ->orderBy('full_name')
            ->limit(500)
            ->get(['id', 'full_name', 'first_name', 'last_name', 'phone', 'user_id', 'position'])
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'name' => $e->full_name ?? trim(($e->first_name ?? '').' '.($e->last_name ?? '')) ?: $e->user?->name,
                'phone' => $e->phone,
                'position' => $e->position,
            ]);

        return ApiResponse::success('تم جلب الموظفين.', $employees);
    }

    public function statuses(): JsonResponse
    {
        $statuses = collect(OnlineTransactionStatus::cases())->map(fn (OnlineTransactionStatus $s) => [
            'value' => $s->value,
            'label' => $s->label(),
            'color' => $s->color(),
        ]);

        return ApiResponse::success('تم جلب حالات المعاملة.', $statuses);
    }

    public function all(): JsonResponse
    {
        $serviceTypes = $this->serviceTypes()->getData(true)['data'];
        $providers = $this->providers()->getData(true)['data'];
        $paymentMethods = $this->paymentMethods()->getData(true)['data'];
        $accounts = $this->accounts()->getData(true)['data'];
        $statuses = $this->statuses()->getData(true)['data'];

        return ApiResponse::success('تم جلب إعدادات وحدة الخدمات الأونلاين.', [
            'service_types' => $serviceTypes,
            'providers' => $providers,
            'payment_methods' => $paymentMethods,
            'accounts' => $accounts,
            'statuses' => $statuses,
        ]);
    }
}
