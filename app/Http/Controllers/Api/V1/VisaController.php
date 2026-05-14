<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Visa\StoreVisaBookingRequest;
use App\Http\Requests\Visa\StoreVisaPaymentRequest;
use App\Http\Requests\Visa\UpdateVisaBookingRequest;
use App\Http\Resources\Visa\VisaBookingResource;
use App\Models\VisaBooking;
use App\Services\Visa\VisaBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisaController extends Controller
{
    public function __construct(protected VisaBookingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $bookings = $this->service->paginate($request->only([
            'status', 'country', 'visa_type', 'from_date', 'to_date', 'search', 'per_page',
        ]));

        return ApiResponse::paginated(
            'تم جلب طلبات التأشيرات',
            VisaBookingResource::collection($bookings),
            $bookings
        );
    }

    public function store(StoreVisaBookingRequest $request): JsonResponse
    {
        try {
            $booking = $this->service->create($request->validated());
        } catch (\Throwable $e) {
            return ApiResponse::error('فشل إنشاء طلب التأشيرة: '.$e->getMessage());
        }

        return ApiResponse::success(
            'تم إنشاء طلب التأشيرة بنجاح',
            new VisaBookingResource($booking),
            201
        );
    }

    public function show(VisaBooking $visa): JsonResponse
    {
        return ApiResponse::success(
            'تم جلب تفاصيل التأشيرة',
            new VisaBookingResource($this->service->find($visa->id))
        );
    }

    public function update(UpdateVisaBookingRequest $request, VisaBooking $visa): JsonResponse
    {
        $booking = $this->service->update($visa, $request->validated());

        return ApiResponse::success('تم تحديث طلب التأشيرة', new VisaBookingResource($booking));
    }

    public function destroy(Request $request, VisaBooking $visa): JsonResponse
    {
        $booking = $this->service->cancel($visa, $request->input('reason'));

        return ApiResponse::success('تم إلغاء طلب التأشيرة', new VisaBookingResource($booking));
    }

    public function addPayment(StoreVisaPaymentRequest $request, VisaBooking $visa): JsonResponse
    {
        try {
            $payment = $this->service->addPayment($visa, $request->validated());
        } catch (\Throwable $e) {
            return ApiResponse::error('فشل تسجيل الدفعة: '.$e->getMessage());
        }

        return ApiResponse::success('تم تسجيل الدفعة', [
            'payment' => $payment->load('account', 'transaction'),
            'booking' => new VisaBookingResource($this->service->find($visa->id)),
        ], 201);
    }
}
