<?php

namespace App\Services\Flight;

use App\Events\TicketModified;
use App\Models\Flight\FlightBooking;
use App\Models\Flight\TicketModification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ModificationService
{
    /**
     * Create a new modification request.
     */
    public function createRequest(array $data, int $userId): TicketModification
    {
        $booking = FlightBooking::findOrFail($data['booking_id']);

        // Check eligibility: booking must be active/confirmed to process modifications
        if ($booking->status?->value === 'cancelled' || $booking->status === 'cancelled') {
            throw new \RuntimeException("لا يمكن إنشاء طلب تعديل لحجز ملغي.");
        }

        $modification = TicketModification::create([
            'booking_id' => $booking->id,
            'modification_type' => $data['modification_type'] ?? 'date_change',
            'original_departure_date' => $booking->departure_date,
            'new_departure_date' => $data['new_departure_date'] ?? null,
            'original_destination' => $booking->destination,
            'new_destination' => $data['new_destination'] ?? null,
            'original_flight_number' => $booking->booking_number ?? $booking->booking_reference,
            'new_flight_number' => $data['new_flight_number'] ?? null,
            'airline_change_fee' => $data['airline_change_fee'] ?? 0,
            'agency_commission' => $data['agency_commission'] ?? 0,
            'total_charged_to_customer' => ($data['airline_change_fee'] ?? 0) + ($data['agency_commission'] ?? 0),
            'currency' => $data['currency'] ?? $booking->currency ?? 'EGP',
            'payment_method' => $data['payment_method'] ?? null,
            'deducted_from_airline_balance' => true, // Default fixed financial rule
            'status' => 'draft',
            'notes' => $data['notes'] ?? null,
            'modified_by' => $userId,
            'ip_address' => request()->ip(),
            'reason_for_change' => $data['reason_for_change'] ?? null,
        ]);

        Log::info("Ticket Modification request created successfully: ID {$modification->id}");

        return $modification;
    }

    /**
     * Advance the approval workflow state safely.
     * Flow: draft → pending → quoted → approved → confirmed
     */
    public function updateStatus(int $id, string $newStatus, int $userId): TicketModification
    {
        $allowedFlow = ['draft', 'pending', 'quoted', 'approved', 'confirmed'];

        if (!in_array($newStatus, $allowedFlow, true)) {
            throw new \InvalidArgumentException("الحالة المحددة غير صالحة لسير العمل.");
        }

        return DB::transaction(function () use ($id, $newStatus, $userId) {
            $modification = TicketModification::lockForUpdate()->findOrFail($id);

            if ($modification->status === 'confirmed') {
                throw new \RuntimeException("طلب التعديل مؤكد بالفعل ولا يمكن تغيير حالته.");
            }

            // If advancing directly to confirmed, trigger full confirmation flow
            if ($newStatus === 'confirmed') {
                return $this->confirmModification($modification->id, $userId);
            }

            $modification->status = $newStatus;
            $modification->modified_by = $userId;
            $modification->save();

            Log::info("Ticket Modification status advanced to {$newStatus} for ID: {$modification->id}");

            return $modification;
        });
    }

    /**
     * Confirm modification, apply locks, take pricing snapshots, update top-level booking, and trigger events.
     */
    public function confirmModification(int $id, int $userId): TicketModification
    {
        return DB::transaction(function () use ($id, $userId) {
            // Apply strict row locking for concurrency protection
            $modification = TicketModification::lockForUpdate()->findOrFail($id);
            $booking = FlightBooking::lockForUpdate()->findOrFail($modification->booking_id);

            if ($modification->status === 'confirmed') {
                throw new \RuntimeException("هذا التعديل تم تأكيده وترحيله مسبقاً (Idempotency Guard).");
            }

            // Fixed Financial Rule Validation
            if (!$booking->airline_account_id) {
                throw new \RuntimeException(
                    "الحجز الأصلي غير مرتبط بحساب طيران (airline_account_id). " .
                    "قاعدة الترحيل المالي الثابتة تتطلب وجود حساب طيران لخصم غرامة التعديل."
                );
            }

            // Store immutable snapshots at confirmation time
            $modification->airline_change_fee_snapshot = $modification->airline_change_fee;
            $modification->commission_snapshot = $modification->agency_commission;
            $modification->exchange_rate_snapshot = $booking->exchange_rate ?? 1.0;
            
            $modification->status = 'confirmed';
            $modification->confirmed_at = now();
            $modification->modified_by = $userId;
            $modification->save();

            // Reporting Integrity Rule: Update top-level booking fields directly
            if ($modification->new_departure_date) {
                $booking->departure_date = $modification->new_departure_date;
            }
            if ($modification->new_destination) {
                $booking->destination = $modification->new_destination;
            }
            
            $booking->last_modified_at = now();
            $booking->modification_count = (int) $booking->modification_count + 1;
            $booking->save();

            // Trigger internal event to handle double entry journal postings & queueable accounting tasks
            event(new TicketModified($modification));

            Log::info("Ticket Modification confirmed and applied successfully for Booking ID: {$booking->id}");

            return $modification;
        });
    }

    /**
     * Reconcile modification against airline statement invoices.
     */
    public function reconcileModification(int $id, string $invoiceNumber): TicketModification
    {
        $modification = TicketModification::findOrFail($id);

        if ($modification->status !== 'confirmed') {
            throw new \RuntimeException("لا يمكن تسوية طلب تعديل غير مؤكد.");
        }

        $modification->reconciliation_status = 'matched';
        $modification->reconciled_invoice_number = $invoiceNumber;
        $modification->reconciled_at = now();
        $modification->save();

        Log::info("Ticket Modification ID {$modification->id} reconciled with invoice {$invoiceNumber}");

        return $modification;
    }
}
