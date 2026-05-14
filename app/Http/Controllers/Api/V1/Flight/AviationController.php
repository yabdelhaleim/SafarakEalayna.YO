<?php

namespace App\Http\Controllers\Api\V1\Flight;

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

    private function respond($status, $operation, $data = null, $errors = [], $warnings = [], $agent = null): JsonResponse
    {
        return response()->json([
            'status' => $status,
            'operation' => $operation,
            'data' => $data,
            'errors' => $errors,
            'warnings' => $warnings,
            'meta' => [
                'timestamp' => now()->toIso8601String(),
                'processed_by_agent' => $agent
            ]
        ], $status === 'ERROR' ? 422 : 200);
    }

    public function store(StoreAviationBookingRequest $request): JsonResponse
    {
        $operation = 'CREATE_BOOKING';
        
        try {
            $result = $this->aviationService->createBooking($request->validated());
            
            return $this->respond(
                'SUCCESS', 
                $operation, 
                [
                    'booking_id' => "BK-" . Carbon::parse($result['booking']->departure_date)->format('Ymd') . "-" . str_pad($result['booking']->id, 3, '0', STR_PAD_LEFT),
                    'booking_reference' => $result['booking']->booking_reference,
                    'status' => $result['booking']->status->value,
                    'profit_egp' => $result['booking']->pricing->profit_egp ?? $result['booking']->pricing->profit,
                    'treasury_credited' => $result['booking']->payments->first()->treasury_account ?? null,
                    'passenger_summary' => $result['passenger_summary']
                ], 
                [], 
                $result['warnings'], 
                $request->agent_name
            );
        } catch (\Exception $e) {
            $decoded = json_decode($e->getMessage(), true);
            $errors = $decoded['errors'] ?? [['field' => 'general', 'code' => 'SYSTEM_ERROR', 'message' => $e->getMessage()]];
            return $this->respond('ERROR', $operation, null, $errors, [], $request->agent_name);
        }
    }

    public function show($idOrRef): JsonResponse
    {
        $operation = 'QUERY_BOOKING';
        $booking = $this->aviationService->getBooking($idOrRef);
        
        if (!$booking) {
            return $this->respond('ERROR', $operation, null, [['field' => 'id', 'code' => 'NOT_FOUND', 'message' => 'الحجز غير موجود']]);
        }

        return $this->respond('SUCCESS', $operation, $booking);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $operation = 'UPDATE_BOOKING';
        try {
            $booking = $this->aviationService->updateBooking($id, $request->all());
            return $this->respond('SUCCESS', $operation, $booking, [], [], $request->agent_name);
        } catch (\Exception $e) {
            return $this->respond('ERROR', $operation, null, [['field' => 'general', 'code' => 'UPDATE_FAILED', 'message' => $e->getMessage()]]);
        }
    }

    public function cancel(Request $request, $id): JsonResponse
    {
        $operation = 'CANCEL_BOOKING';
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string',
            'agent_name' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->respond('ERROR', $operation, null, $validator->errors()->all());
        }

        try {
            $booking = $this->aviationService->cancelBooking($id, $request->reason, $request->agent_name);
            return $this->respond('SUCCESS', $operation, $booking, [], [], $request->agent_name);
        } catch (\Exception $e) {
            return $this->respond('ERROR', $operation, null, [['field' => 'general', 'code' => 'CANCEL_FAILED', 'message' => $e->getMessage()]]);
        }
    }

    public function report(Request $request): JsonResponse
    {
        $operation = 'BOOKING_REPORT';
        $report = $this->aviationService->getReport($request->only(['date_from', 'date_to', 'airline']));
        return $this->respond('SUCCESS', $operation, $report);
    }

    public function treasuryTransaction(Request $request): JsonResponse
    {
        $operation = 'TREASURY_TRANSACTION';
        
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
            return $this->respond('ERROR', $operation, null, $validator->errors()->all(), [], $request->agent_name);
        }

        try {
            $transaction = $this->aviationService->transferFunds($request->all());
            return $this->respond('SUCCESS', $operation, $transaction, [], [], $request->agent_name);
        } catch (\Exception $e) {
            return $this->respond('ERROR', $operation, null, [['field' => 'general', 'code' => 'SYSTEM_ERROR', 'message' => $e->getMessage()]], [], $request->agent_name);
        }
    }
}
