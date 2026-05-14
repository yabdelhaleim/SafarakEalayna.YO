<?php

namespace App\Http\Controllers\Api\V1\Bus;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bus\PayBusBookingRequest;
use App\Http\Requests\Bus\StoreBusBookingRequest;
use App\Http\Resources\Bus\BusBookingResource;
use App\Models\Bus\BusBooking;
use App\Services\Bus\BusBookingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusBookingController extends Controller
{
    public function __construct(
        protected BusBookingService $bookingService
    ) {}

    public function stats(): JsonResponse
    {
        try {
            $data = $this->bookingService->getBookingStats();

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
            $paginator = $this->bookingService->getAllBookings($filters);

            return ApiResponse::paginated(
                'Bus bookings retrieved successfully.',
                BusBookingResource::collection($paginator),
                $paginator
            );
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

    public function destroy(BusBooking $busBooking): JsonResponse
    {
        try {
            $this->bookingService->deleteBooking($busBooking);

            return ApiResponse::success('Booking deleted successfully.');
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

    public function cancel(BusBooking $busBooking): JsonResponse
    {
        try {
            $booking = $this->bookingService->cancelBooking($busBooking);

            return ApiResponse::success(
                'Booking cancelled successfully.',
                new BusBookingResource($booking)
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
