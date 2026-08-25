<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Exceptions\BusinessLogicException;
use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flight\StoreFlightBookingRequest;
use App\Http\Requests\Flight\UpdateFlightBookingRequest;
use App\Http\Requests\Flight\UpdateFlightPricesRequest;
use App\Http\Requests\Flight\StoreFlightPaymentRequest;
use App\Http\Requests\Flight\StoreFlightRefundRequest;
use App\Http\Resources\Flight\FlightBookingResource;
use App\Models\Flight\FlightBooking;
use App\Mail\FlightBookingTicketMailable;
use App\Services\Flight\FlightBookingService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class FlightController extends Controller
{
    public function __construct(
        protected FlightBookingService $bookingService
    ) {}

    /**
     * Get available system types.
     */
    public function systemTypes(): JsonResponse
    {
        $types = \App\Enums\FlightSystemType::forDropdown();
        return ApiResponse::success('System types retrieved successfully.', $types);
    }

    /**
     * قائمة موظفين مختصرة لنماذج الحجز (بدون الاعتماد على سياسة الموارد البشرية الكاملة).
     */
    public function employeesForBooking(Request $request): JsonResponse
    {
        try {
            // We allow any authenticated user to see the abbreviated list for booking purposes
            // This is separate from the full HR management viewAny policy.
            if (!$request->user()) {
                return ApiResponse::error('غير مصرح لك بالوصول.', null, 401);
            }

            $rows = \App\Models\Employee::query()
                ->active()
                ->with('user:id,name')
                ->orderBy('first_name')
                ->orderBy('last_name')
                ->limit(300)
                ->get(['id', 'first_name', 'last_name', 'user_id']);

            $data = $rows->map(static function ($e) {
                $fromName = trim((string) ($e->first_name ?? '').' '.(string) ($e->last_name ?? ''));

                return [
                    'id' => $e->id,
                    'personal_info' => [
                        'full_name' => $fromName !== '' ? $fromName : (string) ($e->user?->name ?? ''),
                    ],
                    'user' => $e->user ? ['name' => $e->user->name] : null,
                ];
            });

            return ApiResponse::success('Employees retrieved successfully.', $data);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return ApiResponse::error('ليس لديك صلاحية لعرض قائمة الموظفين.', null, 403);
        } catch (\Exception $e) {
            report($e);
            return ApiResponse::error($e->getMessage(), null, 500);
        }
    }

    /**
     * جدولة إرسال نسخة تذكرة بالبريد (معالجة في الطابور).
     */
    public function sendTicketEmail(Request $request, FlightBooking $flightBooking): JsonResponse
    {
        $validated = $request->validate([
            'to_email' => ['required', 'string', 'email'],
        ]);

        Mail::to($validated['to_email'])->queue(new FlightBookingTicketMailable($flightBooking));

        return ApiResponse::success('تم جدولة إرسال التذكرة إلى البريد المحدد.', null);
    }

    /**
     * Get all flight bookings with filters.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $filters = $request->only([
                'status',
                'customer_id',
                'employee_id',
                'from_date',
                'to_date',
                'search',
                'per_page',
                'trip_type',
                'currency',
                'flight_system_id',
                'flight_carrier_id',
                'departure_date_from',
                'departure_date_to',
                'payment_status',
            ]);
            $filters['page'] = $request->get('page', 1);

            $cacheKey = 'flight_bookings_list_' . md5(serialize($filters));

            $data = \App\Helpers\CacheHelper::tags(['flight_bookings'])->remember($cacheKey, 60, function () use ($filters) {
                $paginator = $this->bookingService->getAllBookings($filters);
                return [
                    'items' => FlightBookingResource::collection($paginator)->resolve(),
                    'pagination' => [
                        'total' => $paginator->total(),
                        'per_page' => $paginator->perPage(),
                        'current_page' => $paginator->currentPage(),
                        'last_page' => $paginator->lastPage(),
                        'has_more' => $paginator->hasMorePages(),
                    ],
                ];
            });

            return ApiResponse::success('Flight bookings retrieved successfully.', $data);
        } catch (QueryException $e) {
            report($e);

            return ApiResponse::error(
                config('app.debug') ? $e->getMessage() : 'تعذر تحميل الحجوزات (خطأ في قاعدة البيانات).',
                null,
                500
            );
        } catch (\Exception $e) {
            report($e);

            return ApiResponse::error($e->getMessage(), null, 500);
        }
    }

    /**
     * Create a new flight booking.
     */
    public function store(StoreFlightBookingRequest $request): JsonResponse
    {
        try {
            $booking = $this->bookingService->createBooking($request->validated());

            // ✅ Part B: surface any group threshold warning in the response
            // so the SPA can show an immediate Toast after a successful booking.
            $payload = new FlightBookingResource($booking);
            $resourceData = $payload->resolve($request);
            $thresholdWarning = $booking->getAttribute('_group_threshold_warning');

            if (is_array($thresholdWarning)) {
                $resourceData['group_threshold_warning'] = $thresholdWarning;
            }

            return ApiResponse::success(
                'Booking created successfully.',
                $resourceData,
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * Get a single flight booking by ID.
     */
    public function show(FlightBooking $flightBooking): JsonResponse
    {
        try {
            // Load relations on the route model bound instance
            $flightBooking->load([
                'customer.ledgerAccount',
                'employee.user',
                'account',
                'flightSystem',
                'flightCarrier.system',
                'flightGroup',
                'passengers',
                'tickets',
                'segments',
                'payments.transaction',
                'payments.account',
                'refund.transaction',
                'createdBy',
            ]);

            return ApiResponse::success(
                'Booking retrieved successfully.',
                new FlightBookingResource($flightBooking)
            );
        } catch (ModelNotFoundException $e) {
            return ApiResponse::error('Booking not found.', null, 404);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    // INCIDENT-2026-08-17: Tourism no-edit contract.
//   update() and updatePrices() methods removed — PUT/PATCH returns 405 by design.
//   Cancellation is the supported correction path.

/**
     * Confirm a flight booking.
     */
    public function confirm(FlightBooking $flightBooking): JsonResponse
    {
        try {
            $booking = $this->bookingService->confirmBooking($flightBooking);

            return ApiResponse::success(
                'Booking confirmed successfully.',
                new FlightBookingResource($booking)
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * Add a payment to a flight booking.
     *
     * Phase 2 (B-1) fix — IDOR on Flight payment endpoint:
     *   The customer_id comes from route-model binding (FlightBooking), NEVER
     *   from the request payload. The policy gates which users may pay which
     *   bookings (admin/owner OR the booking's owning employee).
     *
     * @see \App\Policies\FlightBookingPolicy::pay
     */
    public function addPayment(StoreFlightPaymentRequest $request, FlightBooking $flightBooking): JsonResponse
    {
        // B-1 fix: authorize via FlightBookingPolicy. The route-model-bound
        // FlightBooking instance carries the customer_id + employee_id used
        // by the policy — the request payload has no customer_id field.
        $this->authorize('pay', $flightBooking);

        try {
            $payment = $this->bookingService->addPayment($flightBooking, $request->validated());

            // D3 FIX (2026-08-15): when the service returns an existing
            // payment that was created earlier with the same idempotency_key,
            // surface HTTP 200 OK + the idempotent_replay flag instead of
            // HTTP 201 Created. The client can distinguish a fresh payment
            // from a replay via the `idempotent_replay` field in the payload.
            $isReplay = (bool) ($payment->idempotent_replay ?? false);
            $status = $isReplay ? 200 : 201;
            $message = $isReplay
                ? 'Payment replay detected — returning the original payment (no new financial effect).'
                : 'Payment recorded successfully.';

            $flightBooking->refresh();
            $flightBooking->load([
                'customer.ledgerAccount',
                'employee.user',
                'account',
                'flightSystem',
                'flightCarrier.system',
                'flightGroup',
                'passengers',
                'tickets',
                'segments',
                'payments.transaction',
                'payments.account',
                'refund.transaction',
                'createdBy',
            ]);

            return ApiResponse::success(
                $message,
                array_merge(
                    (new FlightBookingResource($flightBooking))->resolve($request),
                    ['idempotent_replay' => $isReplay],
                ),
                $status
            );
        } catch (\App\Exceptions\BusinessLogicException $e) {
            // DEFECT-001 fix (2026-08-24): let BusinessLogicException reach the
            // global handler so it maps to 409 Conflict (instead of being
            // swallowed as a generic 422). The bootstrap/app.php withExceptions
            // closure knows the correct mapping; we just rethrow.
            throw $e;
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * Cancel a flight booking and process refund.
     *
     * Phase 2 (B-1) fix — authorization gate aligned with payment policy:
     *   Only admin/owner OR the booking's owning employee can cancel.
     *   Mirrors FlightBookingPolicy::pay to keep the rule consistent across
     *   financial-mutation endpoints.
     */
    public function cancel(StoreFlightRefundRequest $request, FlightBooking $flightBooking): JsonResponse
    {
        // B-1 fix: same authorization gate as addPayment. Any authenticated
        // user with `manage_flights` is no longer sufficient.
        $this->authorize('cancel', $flightBooking);

        try {
            $refund = $this->bookingService->cancelBooking($flightBooking, $request->validated());

            // Return the updated booking with all relations
            $flightBooking->load([
                'customer.ledgerAccount',
                'employee.user',
                'account',
                'flightSystem',
                'flightCarrier.system',
                'flightGroup',
                'passengers',
                'tickets',
                'segments',
                'payments.transaction',
                'refund.transaction',
                'createdBy',
            ]);

            return ApiResponse::success(
                'تم إلغاء الحجز وتسجيل الاسترداد بنجاح.',
                new FlightBookingResource($flightBooking)
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * Soft delete a flight booking WITH full financial reversal.
     *
     * See FlightBookingService::deleteBookingWithReversal — that is the canonical
     * implementation. This controller method is a thin pass-through that:
     *   1. Honors the project's "Soft Delete + Financial Reversal" rule.
     *   2. Replaces the old `deleteBooking()` path (which used to require status=PENDING
     *      + no payments — too restrictive for real-world admin workflows).
     */
    public function destroy(FlightBooking $flightBooking): JsonResponse
    {
        try {
            $userId = \Illuminate\Support\Facades\Auth::id() ?: 1;
            $this->bookingService->deleteBookingWithReversal($flightBooking->id, $userId);

            return ApiResponse::success('تم حذف الحجز مع عكس كل الآثار المحاسبية بنجاح.');
        } catch (BusinessLogicException $e) {
            // DEFECT-005/006 FIX (2026-08-24): re-throw so the global
            // exception handler in bootstrap/app.php:90-95 maps this to
            // HTTP 409 Conflict. BusinessLogicException is the project's
            // canonical "state conflict" exception — used here for the
            // cross-currency refund walk-back known limitation.
            throw $e;
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
