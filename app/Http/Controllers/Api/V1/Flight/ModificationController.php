<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\TicketModification;
use App\Services\Flight\ModificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ModificationController extends Controller
{
    public function __construct(
        protected ModificationService $modificationService
    ) {}

    /**
     * Enforce strict Permission Matrix for the operational actions.
     */
    protected function authorizeMatrix(Request $request, string $action): void
    {
        $user = $request->user();
        if (!$user) {
            return;
        }

        $role = $user->role ?? 'employee';

        // Universal override for system admins & owners
        if (in_array($role, ['admin', 'owner', 'head_of_finance'], true)) {
            return;
        }

        switch ($action) {
            case 'quote':
                if (!in_array($role, ['employee', 'agent', 'manager'], true) && !$user->can('modifications.quote')) {
                    throw new \Illuminate\Auth\Access\AuthorizationException("غير مصرح لك بتسعير أو إنشاء طلبات تعديل التذاكر.");
                }
                break;

            case 'approve':
                if (!in_array($role, ['finance', 'manager'], true) && !$user->can('modifications.approve')) {
                    throw new \Illuminate\Auth\Access\AuthorizationException("غير مصرح لك باعتماد (Approve) طلبات تعديل التذاكر مالياً.");
                }
                break;

            case 'confirm':
                if (!$user->can('modifications.confirm')) {
                    throw new \Illuminate\Auth\Access\AuthorizationException("تأكيد التعديل والترحيل المالي النهائي (GL Posting) مقتصر على مديري المالية والمسؤولين.");
                }
                break;
        }
    }

    /**
     * إنشاء طلب تعديل تذكرة (Quote/Create).
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $this->authorizeMatrix($request, 'quote');
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 403);
        }

        $validated = $request->validate([
            'booking_id' => ['required', 'integer', 'exists:flight_bookings,id'],
            'modification_type' => ['required', 'string', 'in:date_change,destination_change,both'],
            'new_departure_date' => ['nullable', 'date', 'after_or_equal:today'],
            'new_destination' => ['nullable', 'string', 'max:255'],
            'new_flight_number' => ['nullable', 'string', 'max:100'],
            'airline_change_fee' => ['nullable', 'numeric', 'min:0'],
            'agency_commission' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_method' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
            'reason_for_change' => ['nullable', 'string'],
        ]);

        try {
            $userId = Auth::id() ?: 1;
            $modification = $this->modificationService->createRequest($validated, $userId);

            return ApiResponse::success(
                'تم إنشاء طلب التعديل بنجاح.',
                $modification->load(['booking', 'modifiedBy']),
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * عرض تفاصيل طلب التعديل.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $modification = TicketModification::with([
                'booking.customer',
                'booking.airlineAccount',
                'modifiedBy',
            ])->findOrFail($id);

            return ApiResponse::success('تم استرجاع تفاصيل طلب التعديل بنجاح.', $modification);
        } catch (\Exception $e) {
            return ApiResponse::error('طلب التعديل غير موجود.', null, 404);
        }
    }

    /**
     * ترقية حالة طلب التعديل.
     */
    public function updateStatus(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:draft,pending,quoted,approved,confirmed'],
        ]);

        $status = $validated['status'];

        try {
            // Apply permission checks based on target state
            if ($status === 'quoted') {
                $this->authorizeMatrix($request, 'quote');
            } elseif ($status === 'approved') {
                $this->authorizeMatrix($request, 'approve');
            } elseif ($status === 'confirmed') {
                $this->authorizeMatrix($request, 'confirm');
            }

            $userId = Auth::id() ?: 1;
            $modification = $this->modificationService->updateStatus($id, $status, $userId);

            return ApiResponse::success(
                "تم تحديث حالة الطلب إلى {$status} بنجاح.",
                $modification->load(['booking', 'modifiedBy'])
            );
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return ApiResponse::error($e->getMessage(), null, 403);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * التأكيد المباشر لطلب التعديل وتطبيق الترحيل المالي وتحديث التذكرة.
     */
    public function confirm(Request $request, int $id): JsonResponse
    {
        try {
            $this->authorizeMatrix($request, 'confirm');

            $userId = Auth::id() ?: 1;
            $modification = $this->modificationService->confirmModification($id, $userId);

            return ApiResponse::success(
                'تم تأكيد التعديل وترحيل القيود المحاسبية بنجاح.',
                $modification->fresh(['booking', 'modifiedBy'])
            );
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return ApiResponse::error($e->getMessage(), null, 403);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * تسوية كشوفات الطيران ومطابقة الفاتورة.
     */
    public function reconcile(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'invoice_number' => ['required', 'string', 'max:255'],
        ]);

        try {
            // Requires finance approval or admin capability
            $this->authorizeMatrix($request, 'approve');

            $modification = $this->modificationService->reconcileModification($id, $validated['invoice_number']);

            return ApiResponse::success('تمت تسوية ومطابقة فاتورة الطيران بنجاح.', $modification);
        } catch (\Illuminate\Auth\Access\AuthorizationException $e) {
            return ApiResponse::error($e->getMessage(), null, 403);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * استرجاع كافة التعديلات الخاصة بحجز معين.
     */
    public function bookingModifications(int $bookingId): JsonResponse
    {
        $modifications = TicketModification::with(['modifiedBy'])
            ->where('booking_id', $bookingId)
            ->latest()
            ->get();

        return ApiResponse::success('تم استرجاع سجل التعديلات بنجاح.', $modifications);
    }
}
