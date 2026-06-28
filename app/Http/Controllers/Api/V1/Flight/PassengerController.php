<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\FlightPassenger as Passenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class PassengerController extends Controller
{
    /**
     * Display a listing of passengers (دليل المسافرين).
     * يعرض رحلات الذهاب + الإياب كصفوف منفصلة للرحلات ذهاب وعودة.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $today = now()->toDateString();

            $query = Passenger::query()
                ->select('passengers.*')
                ->join('flight_bookings', 'passengers.flight_booking_id', '=', 'flight_bookings.id')
                ->with(['booking.customer', 'booking.flightGroup', 'booking.employee', 'booking.createdBy', 'booking.segments.fromAirport', 'booking.segments.toAirport']);

            // Search by name, passport, national ID, or PNR
            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('passengers.first_name', 'like', "%{$search}%")
                      ->orWhere('passengers.last_name', 'like', "%{$search}%")
                      ->orWhere('passengers.passport_number', 'like', "%{$search}%")
                      ->orWhere('passengers.national_id', 'like', "%{$search}%")
                      ->orWhere('flight_bookings.pnr', 'like', "%{$search}%");
                });
            }

            // Filter by trip status: upcoming, past, all
            $tripStatus = $request->input('trip_status', 'all');
            if ($tripStatus === 'upcoming') {
                $query->whereDate('flight_bookings.departure_date', '>=', $today);
            } elseif ($tripStatus === 'past') {
                $query->whereDate('flight_bookings.departure_date', '<', $today);
            }

            // Filter by departure date range
            if ($from = $request->input('departure_date_from')) {
                $query->whereDate('flight_bookings.departure_date', '>=', $from);
            }
            if ($to = $request->input('departure_date_to')) {
                $query->whereDate('flight_bookings.departure_date', '<=', $to);
            }

            // Sorting: Upcoming first, then past
            $query->orderByRaw('CASE WHEN flight_bookings.departure_date >= ? THEN 0 ELSE 1 END ASC', [$today])
                  ->orderBy('flight_bookings.departure_date', 'asc')
                  ->orderBy('flight_bookings.departure_time', 'asc');

            $perPage  = $request->input('per_page', 15);
            $paginator = $query->paginate($perPage);

            // بناء الـ response باستخدام الـ segments إن وجدت لتقسيم الرحلة لخطوات منفصلة
            $allItems = collect();
            foreach ($paginator->getCollection() as $passenger) {
                $booking = $passenger->booking;
                if (!$booking) {
                    continue;
                }

                $segments = $booking->segments;
                if ($segments && $segments->count() > 0) {
                    foreach ($segments as $index => $segment) {
                        $allItems->push($this->formatPassengerSegmentRow($passenger, $segment, $today, $index + 1));
                    }
                } else {
                    // Fallback to old outbound/return logic
                    $allItems->push($this->formatPassengerRow($passenger, $today, 'outbound'));
                    if (strtolower($booking->trip_type ?? '') === 'round_trip' && !empty($booking->return_date)) {
                        $allItems->push($this->formatPassengerRow($passenger, $today, 'return'));
                    }
                }
            }

            // دمج الصفوف وترتيبها بالتاريخ
            $sortedItems = $allItems->sortBy('sort_date')->values();

            return ApiResponse::paginated(
                'Passengers retrieved successfully.',
                $sortedItems,
                $paginator
            );
        } catch (\Exception $e) {
            report($e);
            return ApiResponse::error($e->getMessage(), null, 500);
        }
    }

    /**
     * تنسيق صف المسافر بناءً على segment محدد.
     */
    private function formatPassengerSegmentRow(Passenger $passenger, $segment, string $today, int $legNumber): array
    {
        $booking = $passenger->booking;
        $fromAirport = $segment->fromAirport ?? $segment->from_airport;
        $toAirport = $segment->toAirport ?? $segment->to_airport;
        
        $departureDate = $segment->departure_date
            ? (is_string($segment->departure_date) ? $segment->departure_date : $segment->departure_date->format('Y-m-d'))
            : null;
        $departureTime = $segment->departure_time
            ? ($segment->departure_time instanceof \DateTimeInterface ? $segment->departure_time->format('H:i') : substr($segment->departure_time, 11, 5))
            : null;

        $hasNotTraveled = $departureDate && $departureDate > $today;
        $traveled = $departureDate && $departureDate <= $today;

        $daysUntil = $departureDate ? Carbon::today()->diffInDays(Carbon::parse($departureDate), false) : null;
        
        $affiliation = '—';
        if ($booking) {
            if ($booking->booking_source === 'group') {
                $affiliation = 'عميل مجموعات';
            } elseif ($booking->customer?->type === 'counter') {
                $affiliation = 'عميل كاونتر';
            } else {
                $affiliation = 'عميل فردي/قطاعي';
            }
        }

        return [
            'passenger_id'    => $passenger->id,
            'leg'             => 'segment', 
            'leg_number'      => $legNumber,
            'first_name'      => $passenger->first_name,
            'last_name'       => $passenger->last_name,
            'first_name_en'   => $passenger->first_name_en,
            'last_name_en'    => $passenger->last_name_en,
            'passport_number' => $passenger->passport_number,
            'national_id'     => $passenger->national_id,
            'type'            => $passenger->type instanceof \App\Enums\PassengerType
                ? $passenger->type->value
                : $passenger->type,
            'traveled'        => $traveled,
            'traveled_at'     => $passenger->traveled_at?->format('Y-m-d H:i:s'),
            'departure_date'  => $departureDate,
            'departure_time'  => $departureTime,
            'days_until'      => $daysUntil, 
            'date_label'      => $this->buildDateLabel($daysUntil),
            'sort_date'       => $departureDate ?? '9999-12-31',
            'affiliation'     => $affiliation,
            'group_name'      => $booking?->flightGroup?->name ?? '—',
            'booking_date'    => $booking?->created_at?->format('Y-m-d H:i:s'),
            'employee_name'   => $booking?->employee?->name ?? $booking?->createdBy?->name ?? '—',
            'booking_notes'   => $booking?->notes ?? '—',
            'booking'         => $booking ? [
                'id'             => $booking->id,
                'booking_number' => $booking->booking_number,
                'pnr'            => $booking->pnr,
                'status'         => $booking->status instanceof \App\Enums\FlightBookingStatus
                    ? $booking->status->value
                    : $booking->status,
                'trip_type'      => $booking->trip_type,
                'from_airport'   => $fromAirport,
                'to_airport'     => $toAirport,
                'airline_name'   => $segment->airline ?? $booking->airline_name,
                'currency'       => $booking->currency,
                'passenger_count'=> $booking->passenger_count ?? 1,
            ] : null,
            'customer' => $booking?->customer ? [
                'id'   => $booking->customer->id,
                'name' => $booking->customer->full_name,
            ] : null,
        ];
    }

    /**
     * تنسيق صف المسافر (ذهاب أو إياب) كاحتياطي.
     */
    private function formatPassengerRow(Passenger $passenger, string $today, string $legType): array
    {
        $booking = $passenger->booking;

        if ($legType === 'return') {
            $fromAirport   = $booking->to_airport;
            $toAirport     = $booking->from_airport;
            $departureDate = $booking->return_date
                ? (is_string($booking->return_date) ? $booking->return_date : $booking->return_date->format('Y-m-d'))
                : null;
            $departureTime = $booking->return_time ?? null;
            $hasNotTraveled = $departureDate && $departureDate > $today;
            $traveled      = false;
        } else {
            $fromAirport   = $booking?->from_airport;
            $toAirport     = $booking?->to_airport;
            $departureDate = $booking?->departure_date
                ? (is_string($booking->departure_date) ? $booking->departure_date : $booking->departure_date->format('Y-m-d'))
                : null;
            $departureTime = $booking?->departure_time;
            $hasNotTraveled = $departureDate && $departureDate > $today;
            $traveled      = !is_null($passenger->traveled_at);
        }

        $daysUntil = $departureDate ? Carbon::today()->diffInDays(Carbon::parse($departureDate), false) : null;

        $affiliation = '—';
        if ($booking) {
            if ($booking->booking_source === 'group') {
                $affiliation = 'عميل مجموعات';
            } elseif ($booking->customer?->type === 'counter') {
                $affiliation = 'عميل كاونتر';
            } else {
                $affiliation = 'عميل فردي/قطاعي';
            }
        }

        return [
            'passenger_id'    => $passenger->id,
            'leg'             => $legType, // 'outbound' | 'return'
            'first_name'      => $passenger->first_name,
            'last_name'       => $passenger->last_name,
            'first_name_en'   => $passenger->first_name_en,
            'last_name_en'    => $passenger->last_name_en,
            'passport_number' => $passenger->passport_number,
            'national_id'     => $passenger->national_id,
            'type'            => $passenger->type instanceof \App\Enums\PassengerType
                ? $passenger->type->value
                : $passenger->type,
            'traveled'        => $traveled,
            'traveled_at'     => $passenger->traveled_at?->format('Y-m-d H:i:s'),
            'return_not_traveled_yet' => ($legType === 'return' && $hasNotTraveled),
            'departure_date'  => $departureDate,
            'departure_time'  => $departureTime,
            'days_until'      => $daysUntil, 
            'date_label'      => $this->buildDateLabel($daysUntil),
            'sort_date'       => $departureDate ?? '9999-12-31',
            'affiliation'     => $affiliation,
            'group_name'      => $booking?->flightGroup?->name ?? '—',
            'booking_date'    => $booking?->created_at?->format('Y-m-d H:i:s'),
            'employee_name'   => $booking?->employee?->name ?? $booking?->createdBy?->name ?? '—',
            'booking_notes'   => $booking?->notes ?? '—',
            'booking'         => $booking ? [
                'id'             => $booking->id,
                'booking_number' => $booking->booking_number,
                'pnr'            => $booking->pnr,
                'status'         => $booking->status instanceof \App\Enums\FlightBookingStatus
                    ? $booking->status->value
                    : $booking->status,
                'trip_type'      => $booking->trip_type,
                'from_airport'   => $fromAirport,
                'to_airport'     => $toAirport,
                'airline_name'   => $booking->airline_name,
                'currency'       => $booking->currency,
                'passenger_count'=> $booking->passenger_count ?? 1,
            ] : null,
            'customer' => $booking?->customer ? [
                'id'   => $booking->customer->id,
                'name' => $booking->customer->full_name,
            ] : null,
        ];
    }

    /**
     * تسمية اليوم (اليوم / غداً / بعد X أيام / قبل X أيام).
     */
    private function buildDateLabel(?int $daysUntil): string
    {
        if ($daysUntil === null) {
            return '';
        }
        if ($daysUntil === 0) {
            return 'اليوم';
        }
        if ($daysUntil === 1) {
            return 'غداً';
        }
        if ($daysUntil > 1) {
            return "بعد {$daysUntil} أيام";
        }
        // ماض
        $abs = abs($daysUntil);
        if ($abs === 1) {
            return 'أمس';
        }
        return "منذ {$abs} أيام";
    }

    /**
     * تسجيل سفر راكب فعلي (تم السفر).
     */
    public function markTraveled(int $id): JsonResponse
    {
        $passenger = Passenger::findOrFail($id);

        if ($passenger->traveled_at) {
            return ApiResponse::error('الراكب سجّل سفره مسبقاً.', null, 422);
        }

        $passenger->update(['traveled_at' => now()]);

        return ApiResponse::success('تم تسجيل سفر الراكب بنجاح.', [
            'passenger_id' => $passenger->id,
            'traveled_at'  => $passenger->traveled_at->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * إلغاء تسجيل السفر (تراجع).
     */
    public function unmarkTraveled(int $id): JsonResponse
    {
        $passenger = Passenger::findOrFail($id);
        $passenger->update(['traveled_at' => null]);

        return ApiResponse::success('تم إلغاء تسجيل سفر الراكب.', [
            'passenger_id' => $passenger->id,
        ]);
    }

    /**
     * Get the authenticated user's travel alert settings.
     */
    public function getAlertSettings(): JsonResponse
    {
        $user = Auth::user();
        return ApiResponse::success('Alert settings retrieved.', [
            'travel_alert_days_before' => $user->travel_alert_days_before ?? 1,
            'travel_alert_time'        => $user->travel_alert_time ?? '09:00:00',
        ]);
    }

    /**
     * Update the authenticated user's travel alert settings.
     */
    public function updateAlertSettings(Request $request): JsonResponse
    {
        $request->validate([
            'travel_alert_days_before' => ['required', 'integer', 'min:0', 'max:30'],
            'travel_alert_time'        => ['required', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
        ]);

        $user = Auth::user();
        $user->update([
            'travel_alert_days_before' => $request->travel_alert_days_before,
            'travel_alert_time'        => $request->travel_alert_time,
        ]);

        return ApiResponse::success('تم تحديث إعدادات التنبيهات بنجاح.', [
            'travel_alert_days_before' => $user->travel_alert_days_before,
            'travel_alert_time'        => $user->travel_alert_time,
        ]);
    }

    /**
     * Get the authenticated user's notifications.
     */
    public function getNotifications(Request $request): JsonResponse
    {
        $user  = Auth::user();
        $type  = $request->input('type', 'unread');
        $query = $type === 'unread' ? $user->unreadNotifications() : $user->notifications();

        $notifications = $query->paginate($request->input('per_page', 20));

        // إضافة days_until محسوب ديناميكياً عند العرض
        $collection = $notifications->getCollection()->map(function ($n) {
            $data = $n->data;
            if (!empty($data['departure_date'])) {
                $daysUntil            = Carbon::today()->diffInDays(Carbon::parse($data['departure_date']), false);
                $data['days_until']   = $daysUntil;
                $data['date_label']   = $this->buildDateLabel($daysUntil);
            }
            $n->data = $data;
            return $n;
        });

        return ApiResponse::paginated('Notifications retrieved.', $collection, $notifications);
    }

    /**
     * Mark a specific notification as read.
     */
    public function markNotificationRead(string $id): JsonResponse
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $notification->markAsRead();
        return ApiResponse::success('تم تحديد التنبيه كمقروء.');
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllNotificationsRead(): JsonResponse
    {
        Auth::user()->unreadNotifications->markAsRead();
        return ApiResponse::success('تم تحديد جميع التنبيهات كمقروءة.');
    }
}
