<?php

namespace App\Http\Controllers\Api\V1\Visa;

use App\Helpers\ApiResponse;
use App\Helpers\CacheHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Visa\StoreVisaBookingRequest;
use App\Http\Requests\Visa\StoreVisaPaymentRequest;
use App\Http\Requests\Visa\UpdateVisaBookingRequest;
use App\Http\Resources\Visa\VisaBookingResource;
use App\Models\VisaBooking;
use App\Services\Visa\VisaBookingService;
use App\Services\Visa\VisaModificationService;
use App\Services\Visa\VisaRefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Visa Booking controller — refactored 2026-07-20.
 *
 * Split from the monolithic Api\V1\VisaController so the read/write paths
 * for the booking resource (CRUD + payment + filtering) live in one place
 * while cancellation/refund logic now flows through VisaRefundService
 * and modification flows through VisaModificationService.
 *
 * The legacy Api\V1\VisaController still exists for back-compat routes
 * (customer statement, pay-customer-debt) that are not booking-CRUD; those
 * were intentionally NOT moved here.
 */
class VisaBookingController extends Controller
{
    public function __construct(protected VisaBookingService $service) {}

    /**
     * GET /api/v1/visa/bookings
     * Filterable index with cache-keyed paginated response.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only([
            'status', 'country', 'visa_type', 'from_date', 'to_date', 'search', 'per_page',
        ]);
        $filters['page'] = $request->get('page', 1);

        $cacheKey = 'visa_bookings_list_'.md5(serialize($filters));

        $data = CacheHelper::tags(['visa_bookings'])->remember($cacheKey, 60, function () use ($filters) {
            $bookings = $this->service->paginate($filters);

            return [
                'items' => VisaBookingResource::collection($bookings)->resolve(),
                'pagination' => [
                    'total' => $bookings->total(),
                    'per_page' => $bookings->perPage(),
                    'current_page' => $bookings->currentPage(),
                    'last_page' => $bookings->lastPage(),
                    'has_more' => $bookings->hasMorePages(),
                ],
            ];
        });

        return ApiResponse::success('تم جلب طلبات التأشيرات', $data);
    }

    /**
     * POST /api/v1/visa/bookings
     */
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

    /**
     * GET /api/v1/visa/bookings/{visa}
     */
    public function show(VisaBooking $visa): JsonResponse
    {
        return ApiResponse::success(
            'تم جلب تفاصيل التأشيرة',
            new VisaBookingResource($this->service->find($visa->id))
        );
    }

    /**
     * PUT/PATCH /api/v1/visa/bookings/{visa}
     *
     * The price-repost path inside `VisaBookingService::update()` is itself
     * additive (mirrors HajjUmra's repostExpenseTransaction pattern);
     * delegated here to keep this controller thin.
     */
    public function update(UpdateVisaBookingRequest $request, VisaBooking $visa): JsonResponse
    {
        $booking = $this->service->update($visa, $request->validated());

        return ApiResponse::success('تم تحديث طلب التأشيرة', new VisaBookingResource($booking));
    }

    /**
     * DELETE /api/v1/visa/bookings/{visa}
     *
     * Soft delete the booking with full financial reversal.
     * For an explicit cancellation (status only, no row removal), use
     * POST /api/v1/visa/bookings/{visa}/cancel.
     *
     * Uses `int $id` + withTrashed() lookup (not route-model binding) so
     * a second delete on an already-soft-deleted booking is detected and
     * returned as 422, not 404.
     *
     * See VisaRefundService::deleteWithReversal for the canonical
     * implementation (reversal + soft-delete under ModelDeletionGuard::run()).
     */
    public function destroy(Request $request, int $visa): JsonResponse
    {
        try {
            $booking = VisaBooking::withTrashed()->find($visa);
            if (! $booking) {
                return ApiResponse::error('طلب التأشيرة غير موجود', null, 404);
            }
            if ($booking->trashed()) {
                return ApiResponse::error('هذا الطلب محذوف بالفعل', null, 422);
            }

            $userId = Auth::id() ?: 1;
            app(VisaRefundService::class)
                ->deleteWithReversal($booking->id, $userId);

            return ApiResponse::success('تم حذف طلب التأشيرة وعكس كل القيود المالية بنجاح.');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * POST /api/v1/visa/bookings/{visa}/cancel
     *
     * Light cancel: flips status to 'Cancelled' and additively reverses all
     * related accounting entries, but keeps the booking row visible. Use this
     * for explicit cancellation; use DELETE for administrative soft-delete.
     */
    public function cancel(Request $request, VisaBooking $visa): JsonResponse
    {
        try {
            $booking = app(VisaRefundService::class)
                ->cancel($visa, $request->input('reason'));

            return ApiResponse::success('تم إلغاء طلب التأشيرة', new VisaBookingResource($booking));
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * POST /api/v1/visa/bookings/{visa}/payments
     */
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

    /**
     * POST /api/v1/visa/bookings/{visa}/refund
     *
     * Distinct endpoint from DELETE so the API can support partial /
     * non-destructive flows in the future.  Currently mirrors cancel but
     * sets status='refunded' through VisaRefundService::refund().
     */
    public function refund(Request $request, VisaBooking $visa): JsonResponse
    {
        $booking = app(VisaRefundService::class)
            ->refund($visa, $request->input('reason'));

        return ApiResponse::success('تم استرداد قيمة التأشيرة', new VisaBookingResource($booking));
    }

    /**
     * GET /api/v1/visa/bookings/{visa}/modifications
     *
     * Returns the modification history (price posts + status transitions)
     * for the booking — backed by Transaction entries with the
     * "عكس: " prefix that VisaModificationService uses when reposting.
     */
    public function modifications(VisaBooking $visa): JsonResponse
    {
        $modifications = app(VisaModificationService::class)
            ->history($visa);

        return ApiResponse::success('تم جلب سجل التعديلات', $modifications);
    }
}
