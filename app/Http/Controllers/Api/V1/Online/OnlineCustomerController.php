<?php

namespace App\Http\Controllers\Api\V1\Online;

use App\Enums\CustomerType;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Online\CreateOnlineCustomerRequest;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class OnlineCustomerController extends Controller
{
    public function __construct(
        protected CustomerService $customerService,
    ) {}

    /**
     * Quick-create a customer scoped to the Online (الخدمات الإلكترونية) module.
     *
     * Used by the Online transaction page when the user wants to add a new
     * customer inline (no separate customer management page required).
     *
     * The created customer is tagged with `module_type='online'` so it only
     * appears in the Online module's customer lists, not in other modules
     * (Flights, Hajj, Visas, Bus, Fawry, etc.).
     */
    public function store(CreateOnlineCustomerRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();

            // Force module_type to 'online' so the customer is scoped to this
            // module only. The Online module is part of the Office division
            // (see AccountModuleContract::OFFICE_DIVISION_MODULES).
            $data['module_type'] = 'online';
            $data['type'] = CustomerType::Individual->value;

            $customer = $this->customerService->createCustomer($data);

            Log::info('Online customer quick-created', [
                'customer_id' => $customer->id,
                'created_by' => auth()->id(),
                'module_type' => 'online',
            ]);

            return ApiResponse::success(
                'تم إنشاء العميل بنجاح.',
                [
                    'id' => $customer->id,
                    'name' => $customer->full_name,
                    'phone' => $customer->phone,
                    'email' => $customer->email,
                    'module_type' => 'online',
                ],
            );
        } catch (\Throwable $e) {
            Log::error('OnlineCustomerController::store failed', [
                'error' => $e->getMessage(),
                'user_id' => auth()->id(),
                'input' => $request->validated(),
            ]);

            return ApiResponse::error('فشل إنشاء العميل: '.$e->getMessage(), 500);
        }
    }
}
