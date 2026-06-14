<?php

namespace App\Http\Controllers\Api\V1\Flight;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Models\Flight\FlightPassenger as Passenger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PassengerController extends Controller
{
    /**
     * Display a listing of passengers with filters and search.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Passenger::query()
                ->select('passengers.*')
                ->join('flight_bookings', 'passengers.flight_booking_id', '=', 'flight_bookings.id')
                ->with(['booking.customer']);

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
                $query->whereDate('flight_bookings.departure_date', '>=', now()->toDateString());
            } elseif ($tripStatus === 'past') {
                $query->whereDate('flight_bookings.departure_date', '<', now()->toDateString());
            }

            // Filter by departure date range
            if ($from = $request->input('departure_date_from')) {
                $query->whereDate('flight_bookings.departure_date', '>=', $from);
            }
            if ($to = $request->input('departure_date_to')) {
                $query->whereDate('flight_bookings.departure_date', '<=', $to);
            }

            // Sorting: Upcoming first (ordered by departure_date asc), then past (ordered by departure_date asc or desc)
            $query->orderByRaw('CASE WHEN flight_bookings.departure_date >= ? THEN 0 ELSE 1 END ASC', [now()->toDateString()])
                  ->orderBy('flight_bookings.departure_date', 'asc')
                  ->orderBy('flight_bookings.departure_time', 'asc');

            $perPage = $request->input('per_page', 15);
            $paginator = $query->paginate($perPage);

            return ApiResponse::paginated('Passengers retrieved successfully.', $paginator->getCollection(), $paginator);
        } catch (\Exception $e) {
            report($e);
            file_put_contents('C:\laragon\tmp\passenger_error.log', 
                "Timestamp: " . date('Y-m-d H:i:s') . "\n" .
                "Message: " . $e->getMessage() . "\n" .
                "File: " . $e->getFile() . ":" . $e->getLine() . "\n" .
                "Trace:\n" . $e->getTraceAsString() . "\n" .
                "Request params: " . json_encode($request->all()) . "\n"
            );
            return ApiResponse::error($e->getMessage(), null, 500);
        }
    }

    /**
     * Get the authenticated user's travel alert settings.
     */
    public function getAlertSettings(): JsonResponse
    {
        $user = Auth::user();
        return ApiResponse::success('Alert settings retrieved.', [
            'travel_alert_days_before' => $user->travel_alert_days_before ?? 1,
            'travel_alert_time' => $user->travel_alert_time ?? '09:00:00',
        ]);
    }

    /**
     * Update the authenticated user's travel alert settings.
     */
    public function updateAlertSettings(Request $request): JsonResponse
    {
        $request->validate([
            'travel_alert_days_before' => ['required', 'integer', 'min:0', 'max:30'],
            'travel_alert_time' => ['required', 'string', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
        ]);

        $user = Auth::user();
        $user->update([
            'travel_alert_days_before' => $request->travel_alert_days_before,
            'travel_alert_time' => $request->travel_alert_time,
        ]);

        return ApiResponse::success('تم تحديث إعدادات التنبيهات بنجاح.', [
            'travel_alert_days_before' => $user->travel_alert_days_before,
            'travel_alert_time' => $user->travel_alert_time,
        ]);
    }

    /**
     * Get the authenticated user's notifications.
     */
    public function getNotifications(Request $request): JsonResponse
    {
        $user = Auth::user();
        $type = $request->input('type', 'unread'); // 'all', 'unread'
        
        $query = $type === 'unread' ? $user->unreadNotifications() : $user->notifications();
        
        $notifications = $query->paginate($request->input('per_page', 20));
        
        return ApiResponse::paginated('Notifications retrieved.', $notifications->getCollection(), $notifications);
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
