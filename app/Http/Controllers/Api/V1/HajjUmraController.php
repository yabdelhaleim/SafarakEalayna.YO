<?php

namespace App\Http\Controllers\Api\V1;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\HajjUmra\StoreHajjUmraBookingRequest;
use App\Http\Requests\HajjUmra\StoreHajjUmraPaymentRequest;
use App\Http\Requests\HajjUmra\UpdateHajjUmraBookingRequest;
use App\Http\Resources\HajjUmra\HajjUmraBookingResource;
use App\Models\HajjUmraBooking;
use App\Services\HajjUmra\HajjUmraBookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HajjUmraController extends Controller
{
    public function __construct(protected HajjUmraBookingService $service) {}

    public function index(Request $request): JsonResponse
    {
        $bookings = $this->service->paginate($request->only([
            'status', 'program_id', 'customer_id', 'from_date', 'to_date', 'search', 'per_page', 'program_type',
        ]));

        return ApiResponse::paginated(
            'تم جلب حجوزات الحج/العمرة',
            HajjUmraBookingResource::collection($bookings),
            $bookings
        );
    }

    public function store(StoreHajjUmraBookingRequest $request): JsonResponse
    {
        try {
            $booking = $this->service->create($request->validated());
        } catch (\Throwable $e) {
            return ApiResponse::error('فشل إنشاء الحجز: '.$e->getMessage());
        }

        return ApiResponse::success(
            'تم إنشاء الحجز بنجاح',
            new HajjUmraBookingResource($booking),
            201
        );
    }

    public function show(HajjUmraBooking $hajjUmra): JsonResponse
    {
        return ApiResponse::success(
            'تم جلب تفاصيل الحجز',
            new HajjUmraBookingResource($this->service->find($hajjUmra->id))
        );
    }

    public function update(UpdateHajjUmraBookingRequest $request, HajjUmraBooking $hajjUmra): JsonResponse
    {
        $booking = $this->service->update($hajjUmra, $request->validated());

        return ApiResponse::success('تم تحديث الحجز', new HajjUmraBookingResource($booking));
    }

    public function destroy(Request $request, HajjUmraBooking $hajjUmra): JsonResponse
    {
        $booking = $this->service->cancel($hajjUmra, $request->input('reason'));

        return ApiResponse::success('تم إلغاء الحجز', new HajjUmraBookingResource($booking));
    }

    public function addPayment(StoreHajjUmraPaymentRequest $request, HajjUmraBooking $hajjUmra): JsonResponse
    {
        try {
            $payment = $this->service->addPayment($hajjUmra, $request->validated());
        } catch (\Throwable $e) {
            return ApiResponse::error('فشل تسجيل الدفعة: '.$e->getMessage());
        }

        return ApiResponse::success('تم تسجيل الدفعة', [
            'payment' => $payment->load('account', 'transaction'),
            'booking' => new HajjUmraBookingResource($this->service->find($hajjUmra->id)),
        ], 201);
    }
}
