<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Services\Flight\AviationService;
use App\Http\Requests\Flight\StoreAviationBookingRequest;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class AviationController extends Controller
{
    public function __construct(
        protected AviationService $aviationService
    ) {}

    /**
     * Generate next booking number for the frontend fallback integration.
     */
    public function nextNumber(): JsonResponse
    {
        $timestamp = now()->format('Ymd');
        $random = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
        $number = "FLT-{$timestamp}-{$random}";
        
        return response()->json([
            'success' => true,
            'message' => 'Booking number generated',
            'data' => ['number' => $number],
            'number' => $number,
            'errors' => null,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['date_from', 'date_to', 'airline']);
        $report = $this->aviationService->getReport($filters);
        
        return ApiResponse::success(
            'Aviation bookings retrieved successfully',
            $report['bookings'] ?? []
        );
    }

    public function store(StoreAviationBookingRequest $request): JsonResponse
    {
        try {
            $result = $this->aviationService->createBooking($request->validated());
            $bookingNumber = "BK-" . Carbon::parse($result['booking']->departure_date)->format('Ymd') . "-" . str_pad($result['booking']->id, 3, '0', STR_PAD_LEFT);
            
            return ApiResponse::success(
                'Booking created successfully',
                [
                    'booking_id' => $bookingNumber,
                    'booking_reference' => $result['booking']->booking_reference,
                    'status' => $result['booking']->status->value,
                    'profit_egp' => $result['booking']->pricing->profit_egp ?? $result['booking']->pricing->profit,
                    'treasury_credited' => $result['booking']->payments->first()->treasury_account ?? null,
                    'passenger_summary' => $result['passenger_summary'],
                    'warnings' => $result['warnings'],
                ],
                201
            );
        } catch (\Exception $e) {
            $decoded = json_decode($e->getMessage(), true);
            $errors = $decoded['errors'] ?? [['field' => 'general', 'code' => 'SYSTEM_ERROR', 'message' => $e->getMessage()]];
            return ApiResponse::error('Failing to create booking', $errors, 422);
        }
    }

    public function show($idOrRef): JsonResponse
    {
        $booking = $this->aviationService->getBooking($idOrRef);
        
        if (!$booking) {
            return ApiResponse::error('الحجز غير موجود', [['field' => 'id', 'code' => 'NOT_FOUND', 'message' => 'الحجز غير موجود']], 404);
        }

        return ApiResponse::success('Booking retrieved successfully', $booking);
    }

    public function update(Request $request, $id): JsonResponse
    {
        try {
            $booking = $this->aviationService->updateBooking($id, $request->all());
            return ApiResponse::success('Booking updated successfully', $booking);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), [['field' => 'general', 'code' => 'UPDATE_FAILED', 'message' => $e->getMessage()]], 422);
        }
    }

    public function cancel(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
            'agent_name' => 'required|string',
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('Validation failed', $validator->errors()->all(), 422);
        }

        try {
            $booking = $this->aviationService->cancelBooking($id, $request->reason, $request->agent_name);
            return ApiResponse::success('Booking cancelled successfully', $booking);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), [['field' => 'general', 'code' => 'CANCEL_FAILED', 'message' => $e->getMessage()]], 422);
        }
    }

    public function report(Request $request): JsonResponse
    {
        $report = $this->aviationService->getReport($request->only(['date_from', 'date_to', 'airline']));
        return ApiResponse::success('Aviation report retrieved successfully', $report);
    }

    public function treasuryTransaction(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'from_treasury' => 'nullable|string',
            'from_account_id' => 'nullable|integer|exists:accounts,id',
            'to_treasury' => 'nullable|string',
            'to_account_id' => 'nullable|integer|exists:accounts,id',
            'amount' => 'required|numeric|min:0',
            'reason' => 'required|string',
            'agent_name' => 'required|string',
        ]);

        $validator->after(function ($v) use ($request) {
            $toTreasury = $request->input('to_treasury');
            $toAcc = $request->input('to_account_id');
            if (($toTreasury === null || $toTreasury === '') && ($toAcc === null || $toAcc === '')) {
                $v->errors()->add('to_treasury', 'يجب تحديد to_treasury أو to_account_id.');
            }
        });

        if ($validator->fails()) {
            return ApiResponse::error('Validation failed', $validator->errors()->all(), 422);
        }

        try {
            $transaction = $this->aviationService->transferFunds($request->all());
            return ApiResponse::success('Funds transferred successfully', $transaction);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), [['field' => 'general', 'code' => 'SYSTEM_ERROR', 'message' => $e->getMessage()]], 422);
        }
    }

    public function destroy($id): JsonResponse
    {
        try {
            $booking = \App\Models\FlightBooking::findOrFail($id);
            $booking->delete();
            return ApiResponse::success('Booking deleted successfully');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), [['field' => 'general', 'code' => 'DELETE_FAILED', 'message' => $e->getMessage()]], 422);
        }
    }
}
