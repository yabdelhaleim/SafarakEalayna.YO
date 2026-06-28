<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Setting\UpdatePrintSettingRequest;
use App\Services\Setting\PrintSettingService;
use Illuminate\Http\JsonResponse;

class PrintSettingController extends Controller
{
    public function __construct(
        protected PrintSettingService $printSettingService
    ) {}

    public function show(): JsonResponse
    {
        return ApiResponse::success(
            'تم جلب إعدادات الطباعة بنجاح',
            $this->printSettingService->toArray()
        );
    }

    public function update(UpdatePrintSettingRequest $request): JsonResponse
    {
        $data = $request->validated();
        if ($request->hasFile('logo')) {
            $data['logo_path'] = $request->file('logo')->store('settings', 'public');
        }

        $setting = $this->printSettingService->update($data);

        return ApiResponse::success(
            'تم حفظ إعدادات الطباعة بنجاح',
            $this->printSettingService->toArray($setting)
        );
    }
}
