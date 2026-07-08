<?php

namespace App\Listeners;

use App\Events\TicketModified;
use App\Models\Account;
use App\Models\AccountEntry;
use App\Models\Flight\AirlineAccount;
use App\Models\Flight\TicketModification;
use App\Models\Transaction;
use App\Services\Flight\AirlineAccountDebitService;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessTicketModificationAccounting implements ShouldQueue
{
    /**
     * The number of times the queued listener may be attempted.
     *
     * @var int
     */
    public int $tries = 3;

    /**
     * Handle the event.
     */
    public function handle(TicketModified $event): void
    {
        $modification = $event->modification;
        $booking = $modification->booking;

        if (!$booking || $modification->status !== 'confirmed') {
            return;
        }

        // Prevent double processing (Idempotency check)
        $existingTx = Transaction::where('related_type', TicketModification::class)
            ->where('related_id', $modification->id)
            ->exists();

        if ($existingTx) {
            Log::warning("Ticket modification accounting already processed for ID: {$modification->id}");
            return;
        }

        DB::transaction(function () use ($modification, $booking) {
            // 1. Deduct from original airline account
            if ($modification->deducted_from_airline_balance && $booking->airline_account_id) {
                $airlineAccount = AirlineAccount::active()
                    ->lockForUpdate()
                    ->find($booking->airline_account_id);

                if ($airlineAccount) {
                    $userId = $modification->modified_by ?? 1;

                    // ✅ Phase 1v2 FIX: استخدام AirlineAccountDebitService
                    //    بدل $airlineAccount->debit() المباشر
                    //    الـ service ده بي:
                    //      - يعمل debit للـ AirlineAccount.balance (آمن)
                    //      - ينشئ GL entries متوازنة على prepaid flight_carrier GL
                    //      - يحمي من desync محاسبي
                    try {
                        app(AirlineAccountDebitService::class)->debitForModification(
                            $airlineAccount,
                            $booking,
                            $modification,
                            $userId,
                        );
                    } catch (\App\Exceptions\InsufficientBalanceException $e) {
                        // الـ prepaid GL غير كافٍ — لازم نشحن الناقل الأول
                        Log::error('Phase 1v2: insufficient prepaid GL for airline modification', [
                            'modification_id' => $modification->id,
                            'booking_id' => $booking->id,
                            'airline_account_id' => $airlineAccount->id,
                            'error' => $e->getMessage(),
                        ]);
                        throw $e;
                    }
                } else {
                    throw new \RuntimeException("حساب الطيران المرتبط بالحجز غير نشط أو غير موجود.");
                }
            }

            // 2. Double-Entry Accounting Layer (GL Accounts)
            // Resolve principal accounts safely
            $cashAccount = Account::where('currency', $modification->currency)
                ->whereIn('type', ['treasury', 'cashbox', 'bank'])
                ->active()
                ->first();

            if (!$cashAccount) {
                $cashAccount = Account::firstOrCreate([
                    'name' => "خزينة تعديلات التذاكر - {$modification->currency}",
                ], [
                    'type' => 'treasury',
                    'currency' => $modification->currency,
                    'balance' => 0,
                    'is_active' => true,
                    'owner_type' => 'owner',
                ]);
            }

            $payableAccount = Account::firstOrCreate([
                'name' => 'حساب دائنو الطيران',
            ], [
                'type' => 'liability',
                'currency' => $modification->currency,
                'balance' => 0,
                'is_active' => true,
                'owner_type' => 'owner',
            ]);

            $revenueAccount = Account::firstOrCreate([
                'name' => 'إيرادات عمولات تعديل التذاكر',
            ], [
                'type' => 'revenue',
                'currency' => $modification->currency,
                'balance' => 0,
                'is_active' => true,
                'owner_type' => 'owner',
            ]);

            // Execute balanced double-entry inside Mutation Guard
            LedgerBalanceMutationGuard::run(function () use ($modification, $cashAccount, $payableAccount, $revenueAccount) {
                $totalPaid = (float) $modification->total_charged_to_customer;
                $changeFee = (float) $modification->airline_change_fee;
                $commission = (float) $modification->agency_commission;

                $transaction = Transaction::create([
                    'type' => 'transfer',
                    'amount' => $totalPaid,
                    'module' => 'flight',
                    'related_type' => TicketModification::class,
                    'related_id' => $modification->id,
                    'from_account_id' => $cashAccount->id, // Asset holding received cash
                    'to_account_id' => $payableAccount->id,
                    'created_by' => $modification->modified_by ?? 1,
                    'notes' => "قيود تعديل تذكرة طيران للحجز #{$modification->booking->booking_reference}",
                ]);

                // Lock accounts for GL entry
                $accounts = Account::whereIn('id', [$cashAccount->id, $payableAccount->id, $revenueAccount->id])
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                // A. Debit: Cash/Bank increases by Total Paid
                $cash = $accounts->get($cashAccount->id);
                $cash->balance += $totalPaid;
                $cash->save();

                AccountEntry::create([
                    'account_id' => $cash->id,
                    'transaction_id' => $transaction->id,
                    'debit' => $totalPaid,
                    'credit' => 0,
                    'balance_after' => $cash->balance,
                ]);

                // B. Credit: Airline Payable increases by Change Fee
                if ($changeFee > 0) {
                    $payable = $accounts->get($payableAccount->id);
                    $payable->balance += $changeFee; // Liability increases
                    $payable->save();

                    AccountEntry::create([
                        'account_id' => $payable->id,
                        'transaction_id' => $transaction->id,
                        'debit' => 0,
                        'credit' => $changeFee,
                        'balance_after' => $payable->balance,
                    ]);
                }

                // C. Credit: Commission Revenue increases by Agency Commission
                if ($commission > 0) {
                    $revenue = $accounts->get($revenueAccount->id);
                    $revenue->balance += $commission; // Revenue increases
                    $revenue->save();

                    AccountEntry::create([
                        'account_id' => $revenue->id,
                        'transaction_id' => $transaction->id,
                        'debit' => 0,
                        'credit' => $commission,
                        'balance_after' => $revenue->balance,
                    ]);
                }
            });

            Log::info("Successfully processed GL accounting for Ticket Modification ID: {$modification->id}");
        });
    }

    /**
     * Handle a job failure (Dead-Letter Handling).
     */
    public function failed(TicketModified $event, \Throwable $exception): void
    {
        $modification = $event->modification;

        Log::critical("DEAD-LETTER: Failed to process Ticket Modification Accounting after 3 retries.", [
            'modification_id' => $modification->id,
            'booking_id' => $modification->booking_id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Annotate the modification record with the failure state safely
        try {
            $modification->notes = trim($modification->notes . "\n[فشل الترحيل المالي التلقائي: " . $exception->getMessage() . "]");
            $modification->saveQuietly();
        } catch (\Throwable $e) {
            Log::error("Failed to append dead-letter notes to modification record: " . $e->getMessage());
        }
    }
}
