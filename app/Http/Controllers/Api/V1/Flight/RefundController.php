<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Flight\AirlineCredit;
use App\Models\Flight\RefundRequest;
use App\Models\Treasury;
use App\Services\Flight\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class RefundController extends Controller
{
    public function __construct(
        protected RefundService $refundService
    ) {}

    /**
     * إنشاء طلب استرجاع جديد للتذكرة.
     */
    public function store(Request $request): JsonResponse
    {
        // Bug #B8 fix: validate refund_currency against known currency list,
        // and refund_exchange_rate range to prevent silent 50x errors.
        $validated = $request->validate([
            'flight_booking_id' => ['required', 'integer', 'exists:flight_bookings,id'],
            'cancellation_fee' => ['nullable', 'numeric', 'min:0'],
            'refund_currency' => [
                'nullable',
                'string',
                'size:3',
                // يجب أن تكون عملة معروفة من جدول currencies النشطة فقط.
                // EGP هي العملة الأساسية ومُسجَّلة كقاعدة للنظام — نقبلها دائماً
                // حتى لو لم تكن موجودة كصف في جدول currencies.
                function ($attr, $value, $fail) {
                    if ($value === null || $value === '') return;
                    if (strtoupper($value) === 'EGP') return;
                    $exists = \App\Models\Setting\Currency::query()
                        ->whereRaw('upper(code) = ?', [strtoupper($value)])
                        ->where('is_active', true)
                        ->exists();
                    if (! $exists) {
                        $fail("العملة {$value} غير معروفة أو غير نشطة في جدول العملات.");
                    }
                },
            ],
            'refund_exchange_rate' => ['nullable', 'numeric', 'min:0.000001', 'max:10000'],
            'destination' => ['required', 'string', 'in:airline_credit,agency_treasury'],
            'treasury_id' => [
                'nullable',
                'required_if:destination,agency_treasury',
                'integer',
                'exists:treasuries,id',
                // تحقق أن الخزينة موجودة ونشطة
                function ($attr, $value, $fail) use ($request) {
                    if ($value === null) return;
                    $treasury = Treasury::find($value);
                    if (! $treasury) {
                        $fail('الخزينة المحددة غير موجودة.');
                        return;
                    }
                    if (! $treasury->is_active) {
                        $fail("الخزينة ({$treasury->name}) غير نشطة.");
                    }
                },
            ],
            'refund_type' => ['nullable', 'string'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $userId = Auth::id() ?: 1;
            $refundRequest = $this->refundService->createRefundRequest($validated, $userId);

            return ApiResponse::success(
                'تم إنشاء طلب الاسترجاع بنجاح.',
                $refundRequest->load(['booking', 'treasury']),
                201
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * عرض تفاصيل طلب استرجاع.
     */
    public function show(int $id): JsonResponse
    {
        try {
            $refundRequest = RefundRequest::with([
                'booking.customer',
                'treasury',
                'airlineCredit',
                'createdBy',
            ])->findOrFail($id);

            return ApiResponse::success('تم استرجاع تفاصيل الطلب بنجاح.', $refundRequest);
        } catch (\Exception $e) {
            return ApiResponse::error('طلب الاسترجاع غير موجود.', null, 404);
        }
    }

    /**
     * معالجة واعتماد طلب الاسترجاع نهائياً.
     */
    public function process(int $id): JsonResponse
    {
        try {
            $userId = Auth::id() ?: 1;
            $refundRequest = $this->refundService->processRefundRequest($id, $userId);

            return ApiResponse::success(
                'تمت معالجة طلب الاسترجاع وتحديث الأرصدة بنجاح.',
                $refundRequest->fresh(['booking', 'treasury', 'airlineCredit'])
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }

    /**
     * قائمة الخزائن المتاحة لدعم شاشات الاختيار.
     */
    public function treasuries(Request $request): JsonResponse
    {
        $currency = $request->query('currency');

        $query = Treasury::query()->active();

        if ($currency) {
            $query->byCurrency($currency);
        }

        $treasuries = $query->get();

        return ApiResponse::success('تم استرجاع الخزائن بنجاح.', $treasuries);
    }

    /**
     * قائمة أرصدة الطيران المستردة.
     */
    public function airlineCredits(Request $request): JsonResponse
    {
        $query = AirlineCredit::with(['carrier', 'customer', 'booking'])->active();

        if ($request->query('carrier_id')) {
            $query->where('flight_carrier_id', $request->query('carrier_id'));
        }

        $credits = $query->get();

        return ApiResponse::success('تم استرجاع أرصدة الطيران بنجاح.', $credits);
    }

    /**
     * Reverse (delete with full financial reversal) a refund request.
     *
     * Wraps RefundService::reverseRefundRequest which:
     *  - Branches by destination (airline_credit vs agency_treasury).
     *  - Reverses the GL transfer, the carrier/system debit, and the treasury receipt
     *    (for agency_treasury destination).
     *  - Cancels the AirlineCredit voucher (for airline_credit destination).
     *  - Soft-deletes the refund request itself.
     *
     * Idempotency: RefundService throws RuntimeException on already-deleted requests.
     */
    public function destroy(int $id): JsonResponse
    {
        try {
            $userId = Auth::id() ?: 1;
            $refundRequest = $this->refundService->reverseRefundRequest($id, $userId);

            return ApiResponse::success(
                'تم حذف طلب الاسترداد وعكس كل الآثار المحاسبية بنجاح.',
                $refundRequest->fresh(['booking', 'treasury', 'airlineCredit'])
            );
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage(), null, 422);
        }
    }
}
