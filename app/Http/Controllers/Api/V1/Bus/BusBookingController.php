<?php

namespace App\Http\Controllers\Api\V1\Bus;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bus\CancelBusBookingRequest;
use App\Http\Requests\Bus\PayBusBookingRequest;
use App\Http\Requests\Bus\StoreBusBookingRequest;
use App\Http\Resources\Bus\BusBookingResource;
use App\Models\Bus\BusBooking;
use App\Services\Bus\BusBookingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BusBookingController extends Controller
{
    public function __construct(
        protected BusBookingService $bookingService
    ) {}

    public function stats(): JsonResponse
    {
        try {
            $data = \App\Helpers\CacheHelper::tags(['bus_bookings'])->remember('bus_bookings_stats', 60, function () {
                return $this->bookingService->getBookingStats();
            });

            return ApiResponse::success('Bus booking statistics retrieved successfully.', $data);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'status',
                'customer_id',
                'employee_id',
                'inventory_id',
                'company_id',
                'search',
                'date_from',
                'date_to',
                'per_page',
                'page',
            ]);
            $filters['page'] = $request->get('page', 1);

            $cacheKey = 'bus_bookings_list_' . md5(serialize($filters));

            $data = \App\Helpers\CacheHelper::tags(['bus_bookings'])->remember($cacheKey, 60, function () use ($filters) {
                $paginator = $this->bookingService->getAllBookings($filters);
                return [
                    'items' => BusBookingResource::collection($paginator)->resolve(),
                    'pagination' => [
                        'total' => $paginator->total(),
                        'per_page' => $paginator->perPage(),
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'has_more' => $paginator->hasMorePages(),
                    ],
                ];
            });

            return ApiResponse::success('Bus bookings retrieved successfully.', $data);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function store(StoreBusBookingRequest $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->createBooking($request->validated());

            return ApiResponse::success(
                'Booking created successfully.',
                new BusBookingResource($booking),
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function show(BusBooking $busBooking): JsonResponse
    {
        try {
            $busBooking->load([
                'inventory.company',
                'customer',
                'employee.user',
                'account',
                'payments',
                'transaction',
                'createdBy',
            ]);

            return ApiResponse::success(
                'Booking retrieved successfully.',
                new BusBookingResource($busBooking)
            );
        } catch (ModelNotFoundException $e) {
            return ApiResponse::error('Booking not found.', null, 404);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * Admin soft-delete with full financial reversal.
     *
     * Routes through `BusBookingService::deleteBookingWithReversal()` which
     * handles payments + ledger reversal + soft-delete in one call — works
     * regardless of booking status or whether payments exist (the old
     * `'Only pending'` constraint has been removed, the old `'no payments'`
     * constraint is now handled internally by `deleteBookingWithReversal`
     * via per-payment reversals).
     *
     * Idempotent: throws a clean error if the booking is already soft-deleted.
     */
    public function destroy(BusBooking $busBooking): JsonResponse
    {
        try {
            $this->bookingService->deleteBookingWithReversal($busBooking->id, Auth::id());

            return ApiResponse::success(
                'تم حذف الحجز وعكس جميع القيود المالية (مدفوعات + مديونيات) بنجاح.'
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function pay(PayBusBookingRequest $request, BusBooking $busBooking): JsonResponse
    {
        try {
            $booking = $this->bookingService->payBooking($busBooking, $request->validated());

            return ApiResponse::success(
                'Payment recorded successfully.',
                new BusBookingResource($booking)
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    public function cancel(CancelBusBookingRequest $request, BusBooking $busBooking): JsonResponse
    {
        try {
            $this->bookingService->cancelBooking($busBooking, $request->validated());

            $busBooking->refresh();
            $busBooking->load([
                'inventory.company',
                'customer',
                'employee.user',
                'account',
                'payments',
                'refund.account',
                'refund.transaction',
                'createdBy',
            ]);

            return ApiResponse::success(
                'تم إلغاء الحجز وتسجيل الاسترداد بنجاح.',
                new BusBookingResource($busBooking)
            );
        } catch (\InvalidArgumentException $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
