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
use App\Support\Finance\AccountModuleContract;
use App\Support\Finance\PaymentMethodAccountType;
use Illuminate\Http\JsonResponse;

class OnlineSettingsController extends Controller
{
    /**
     * Apply no-store headers so CDNs / reverse proxies / browsers don't
     * cache master-data responses. Without these, a fresh environment
     * with no `payment_methods` rows can keep serving `payment_methods: []`
     * for hours after the table is populated.
     */
    private function noCache(JsonResponse $response): JsonResponse
    {
        return $response
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

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

        return $this->noCache(ApiResponse::success('تم جلب أنواع الخدمات النشطة.', $types));
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

        return $this->noCache(ApiResponse::success('تم جلب مزودي الخدمات النشطين.', $providers));
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
            'account_type' => PaymentMethodAccountType::resolve($m->code)?->value,
        ]);

        return $this->noCache(ApiResponse::success('تم جلب طرق الدفع النشطة.', $methods));
    }

    public function accounts(): JsonResponse
    {
        // Online belongs to the Office division. The dropdown may use Online-
        // specific vaults plus the unified Office vaults, but never subject,
        // internal, inactive, or Tourism accounts.
        $accounts = Account::active()
            ->whereIn('type', AccountModuleContract::LIQUIDITY_TYPES)
            ->whereIn('module_type', ['online', AccountModuleContract::OFFICE_MODULE_TYPE])
            ->orderBy('name')
            ->get(['id', 'name', 'type', 'balance', 'currency', 'wallet_provider', 'wallet_number', 'is_active', 'module_type'])
            ->map(fn (Account $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'type' => $a->type instanceof \BackedEnum ? $a->type->value : $a->type,
                'balance' => (float) $a->balance,
                'currency' => $a->currency,
                'wallet_provider' => $a->wallet_provider,
                'wallet_number' => $a->wallet_number,
            'module_type' => $a->module_type instanceof \BackedEnum ? $a->module_type->value : $a->module_type,
        ]);

        return $this->noCache(ApiResponse::success('تم جلب الحسابات النشطة.', $accounts));
    }

    public function customers(): JsonResponse
    {
        // Scope customer list to those with at least one online transaction.
        // Lets users see only the module's customers (the saved-at-create
        // mapping first kicks in once they have a transaction). Newly created
        // online customers surface after the first transaction they save.
        $customers = Customer::query()
            ->where(function ($q) {
                $q->where('module_type', 'online')
                    ->orWhereHas('onlineTransactions');
            })
            ->orderBy('full_name')
            ->limit(500)
            ->get(['id', 'full_name', 'phone', 'email', 'module_type'])
            ->map(fn (Customer $c) => [
                'id' => $c->id,
                'name' => $c->full_name,
                'phone' => $c->phone,
                'email' => $c->email,
                'module_type' => $c->module_type instanceof \BackedEnum ? $c->module_type->value : $c->module_type,
            ]);

        return $this->noCache(ApiResponse::success('تم جلب العملاء.', $customers));
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

        return $this->noCache(ApiResponse::success('تم جلب الموظفين.', $employees));
    }

    public function statuses(): JsonResponse
    {
        $statuses = collect(OnlineTransactionStatus::cases())->map(fn (OnlineTransactionStatus $s) => [
            'value' => $s->value,
            'label' => $s->label(),
            'color' => $s->color(),
        ]);

        return $this->noCache(ApiResponse::success('تم جلب حالات المعاملة.', $statuses));
    }

    public function all(): JsonResponse
    {
        $serviceTypes = $this->serviceTypes()->getData(true)['data'];
        $providers = $this->providers()->getData(true)['data'];
        $paymentMethods = $this->paymentMethods()->getData(true)['data'];
        $accounts = $this->accounts()->getData(true)['data'];
        $statuses = $this->statuses()->getData(true)['data'];

        return $this->noCache(ApiResponse::success('تم جلب إعدادات وحدة الخدمات الأونلاين.', [
            'service_types' => $serviceTypes,
            'providers' => $providers,
            'payment_methods' => $paymentMethods,
            'accounts' => $accounts,
            'statuses' => $statuses,
        ]));
    }
}
