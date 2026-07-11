<?php

namespace App\Services\Flight;

use App\Events\TicketModified;
use App\Models\Flight\AirlineAccount;
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
     * Reverse (delete with reversal) a confirmed ticket modification.
     *
     * Project rule: deleting any financial entity is a combination of:
     *  1) a Soft Delete (preserves the row, hides it from views/reports), and
     *  2) a Full Reversal of the financial impact made at confirmation time.
     *
     * In `confirmModification()`, the underlying `airline_change_fee` is debited
     * from the booking's linked AirlineAccount (via the `ProcessTicketModificationAccounting`
     * listener → `AirlineAccountDebitService::debitForModification`). There is
     * **no paired GL entry** for that debit (this is the known GAP — see
     * `docs/ARCHITECTURE.md` § 8.5).
     *
     * Therefore this reversal mirrors that pattern by crediting AirlineAccount.balance
     * directly. A TODO marks the spot where the method should switch to using the
     * canonical posting path once `Phase 1v2` (AirlineAccount protection + GL-aware
     * modification flow) is delivered.
     *
     * Branches:
     *  - Non-confirmed status → just soft-delete (no balance was ever touched).
     *  - Confirmed → credit AirlineAccount + restore booking fields + soft-delete.
     *
     * Idempotency: throws RuntimeException on already-soft-deleted.
     *
     * @throws \RuntimeException on duplicates or when airline_account_id is missing
     *                    on the booking.
     */
    public function reverseConfirmation(int $id, int $userId): TicketModification
    {
        return DB::transaction(function () use ($id, $userId) {
            // Use withTrashed() so an already-soft-deleted modification can be located —
            // we want a clean idempotency error, not "No query results".
            $modification = TicketModification::withTrashed()
                ->lockForUpdate()
                ->findOrFail($id);

            if ($modification->trashed()) {
                throw new \RuntimeException(
                    'هذا التعديل محذوف بالفعل (soft delete) — لا يمكن عكسه مرة ثانية.'
                );
            }

            Log::info('ModificationService::reverseConfirmation — starting', [
                'modification_id' => $id,
                'status' => $modification->status,
                'booking_id' => $modification->booking_id,
                'user_id' => $userId,
            ]);

            // If the modification was never confirmed, there is no balance change to reverse.
            // The `TicketModified` event (which triggers the listener → debit) is only
            // dispatched from `confirmModification()`, so for non-confirmed statuses the
            // AirlineAccount.balance was untouched.
            if ($modification->status !== 'confirmed') {
                $modification->delete();
                Log::info('ModificationService::reverseConfirmation — was not confirmed, soft-deleted only', [
                    'modification_id' => $id,
                    'user_id' => $userId,
                ]);
                return $modification;
            }

            // From here on, the modification was confirmed → reverse its financial impact.
            $booking = FlightBooking::lockForUpdate()->findOrFail($modification->booking_id);

            // ─────────────────────────────────────────────────────────────────
            // REVERSAL FLOW (Phase 1v2):
            //   Delegates to AirlineAccountDebitService::creditBackForModification()
            //   which is the EXACT MIRROR of debitForModification():
            //     ① AirlineAccount->credit()           (sub-ledger)
            //     ② PrepaidLedgerService::refundCogs()  (GL reversal on prepaid flight_carrier)
            //   Both wrapped in LedgerBalanceMutationGuard + DB::transaction.
            //
            //   This closes the original GAP (docs/ARCHITECTURE.md § 8.5):
            //   before, the reverse path only mutated AirlineAccount.balance
            //   without posting a paired GL entry — leaving prepaid flight_carrier
            //   under-consumed (asymmetric accounting).
            // ─────────────────────────────────────────────────────────────────
            if ($booking->airline_account_id) {
                $airlineAccount = AirlineAccount::lockForUpdate()->find($booking->airline_account_id);
                if ($airlineAccount) {
                    try {
                        $result = app(\App\Services\Flight\AirlineAccountDebitService::class)
                            ->creditBackForModification(
                                airlineAccount: $airlineAccount,
                                booking: $booking,
                                modification: $modification,
                                userId: $userId,
                            );

                        Log::info('ModificationService::reverseConfirmation — AirlineAccount credited back + GL reversed', [
                            'modification_id' => $id,
                            'airline_account_id' => $airlineAccount->id,
                            'airline_tx_id' => $result['airline_tx_id'],
                            'prepaid_tx_id' => $result['prepaid_tx_id'],
                            'balance_after' => $result['balance_after'],
                        ]);
                    } catch (\App\Exceptions\InsufficientBalanceException $e) {
                        // Unlikely on reversal side, but log defensively.
                        Log::error('Phase 1v2: insufficient prepaid GL on reversal (should not happen)', [
                            'modification_id' => $id,
                            'airline_account_id' => $airlineAccount->id,
                            'error' => $e->getMessage(),
                        ]);
                        throw $e;
                    }
                } else {
                    Log::warning('ModificationService::reverseConfirmation — AirlineAccount record missing', [
                        'modification_id' => $id,
                        'airline_account_id' => $booking->airline_account_id,
                    ]);
                }
            } else {
                Log::warning('ModificationService::reverseConfirmation — booking has no airline_account_id (cannot reverse balance)', [
                    'modification_id' => $id,
                    'booking_id' => $booking->id,
                ]);
            }

            // Restore booking fields to their pre-modification values
            if ($modification->original_departure_date) {
                $booking->departure_date = $modification->original_departure_date;
            }
            if ($modification->original_destination) {
                $booking->destination = $modification->original_destination;
            }
            if (($booking->modification_count ?? 0) > 0) {
                $booking->modification_count = (int) $booking->modification_count - 1;
            }
            $booking->save();

            // Soft delete the modification itself (uses new SoftDeletes trait)
            $modification->delete();

            Log::info('ModificationService::reverseConfirmation — complete', [
                'modification_id' => $id,
                'booking_id' => $booking->id,
                'user_id' => $userId,
            ]);

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
