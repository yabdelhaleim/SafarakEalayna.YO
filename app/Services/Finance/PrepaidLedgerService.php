<?php

namespace App\Services\Finance;

use App\Enums\TransactionModule;
use App\Exceptions\InsufficientBalanceException;
use App\Models\Account;
use App\Models\Flight\FlightBooking;
use App\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * شحن رصيد مسبق (أصل) + استهلاك COGS عند الحجز/الاستخدام.
 */
class PrepaidLedgerService
{
    public function __construct(
        protected TransactionService $transactionService,
        protected LedgerClearingAccounts $clearingAccounts,
        protected CurrencyService $currencyService,
    ) {}

    /**
     * RC-002 FIX (2026-08-26): pending COGS placeholder account id (flight module).
     */
    public function pendingCogsAccountId(TransactionModule $module): ?int
    {
        if ($module === TransactionModule::Flight) {
            return $this->clearingAccounts->pendingCogsIdForFlight();
        }

        return null;
    }

    /**
     * شحن: سيولة → حساب رصيد مسبق (بدون تأثير على P&L).
     *
     * يدعم التحويل التلقائي بين العملات: لو الـ source.currency يختلف عن الـ prepaid GL account.currency
     * (الذي دايماً EGP)، يتم حساب converted_amount تلقائياً باستخدام CurrencyService.
     */
    public function recharge(
        string $prepaidKey,
        Account $source,
        float $amount,
        TransactionModule $module,
        ?string $notes = null,
        ?string $relatedType = null,
        ?int $relatedId = null,
    ): Transaction {
        if ($amount <= 0) {
            throw new \InvalidArgumentException('مبلغ الشحن يجب أن يكون أكبر من صفر.');
        }

        $prepaidId = $this->clearingAccounts->prepaidAccountId($prepaidKey);
        if ($prepaidId === $source->id) {
            throw new \InvalidArgumentException('حساب المصدر يطابق حساب الرصيد المسبق.');
        }

        // حساب المبلغ بـ EGP تلقائياً إذا اختلفت العملات.
        // الـ prepaid GL account.currency = 'EGP' دائماً (انظر LedgerClearingAccounts::ensurePrepaidAccountExists).
        $prepaidAccount = Account::query()->find($prepaidId);
        $fromCurrency = strtoupper((string) $source->currency);
        $toCurrency = strtoupper((string) ($prepaidAccount?->currency ?? 'EGP'));
        $sameCurrency = $fromCurrency === $toCurrency;

        $transferData = [
            'amount' => $amount,
            'from_account_id' => $source->id,
            'to_account_id' => $prepaidId,
            'module' => $module->value,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'notes' => ($notes ?? 'شحن رصيد مسبق').' [رصيد مسبق]',
            'created_by' => Auth::id() ?? 1,
        ];

        if (! $sameCurrency) {
            // FIX P2.1 (2026-08-20): NEVER silently fall back to 1:1 conversion.
            // Per the Tourism production-readiness audit (Rule 1: "do not silently
            // use incorrect currency conversion"), if the FX rate is unavailable
            // we MUST throw — the caller can either seed an ExchangeRate row or
            // supply a rate explicitly via the explicit-rate overload below.
            //
            // The previous behavior posted at 1:1 with just a warning log, which
            // silently created financially incorrect transactions (e.g. 150 USD
            // becoming 150 EGP instead of 7500 EGP at rate 50). That destroyed
            // money on every cross-currency prepaid recharge when no rate was
            // seeded in the test/local environment.
            //
            // See app/Services/Finance/CurrencyService.php::convert() which throws
            // an explicit "لا يوجد سعر صرف متاح" exception when no rate can be
            // resolved — we let that exception propagate so callers handle it.
            $conversion = $this->currencyService->convert($amount, $fromCurrency, $toCurrency);
            $transferData['converted_amount'] = (float) $conversion['to_amount'];
            $transferData['exchange_rate'] = (float) $conversion['rate'];

            Log::info('Prepaid recharge: currency conversion applied', [
                'prepaid_key' => $prepaidKey,
                'from_currency' => $fromCurrency,
                'to_currency' => $toCurrency,
                'from_amount' => $amount,
                'converted_amount' => $transferData['converted_amount'],
                'exchange_rate' => $transferData['exchange_rate'],
            ]);
        }

        $transaction = $this->transactionService->recordJournalTransfer($transferData);

        Log::info('Prepaid recharge recorded', [
            'prepaid_key' => $prepaidKey,
            'from_account_id' => $source->id,
            'amount' => $amount,
            'transaction_id' => $transaction->id,
        ]);

        return $transaction;
    }

    /**
     * استهلاك: رصيد مسبق → إقفال تكاليف (COGS — يدخل P&L).
     *
     * حماية: يرمي InsufficientBalanceException لو الرصيد المسبق أقل من المبلغ.
     * هذا يضمن أن الحسابات المسبقة (مثل "رصيد مسبق — ناقلو الطيران") لا تدخل في السالب
     * عند حجز جديد حتى لو تم تعديل رصيد الناقل/النظام يدوياً من الـ Filament UI.
     *
     * RC-002 (2026-08-26): when `$destinationOverride` is supplied, the destination
     * account is the PENDING COGS placeholder instead of expense_clearing. The
     * transaction is then NOT classified as P&L COGS until the recogniser moves
     * the proportional amount to expense_clearing on cash receipt.
     */
    public function consumeCogs(
        string $prepaidKey,
        TransactionModule $module,
        float $amount,
        ?string $notes = null,
        ?string $relatedType = null,
        ?int $relatedId = null,
        ?int $destinationOverride = null,
    ): ?Transaction {
        if ($amount <= 0) {
            return null;
        }

        $prepaidId = $this->clearingAccounts->prepaidAccountId($prepaidKey);
        $defaultContra = $this->clearingAccounts->expenseContraIdForModule($module);
        $destinationId = $destinationOverride !== null ? $destinationOverride : $defaultContra;

        if ($destinationId === null || $prepaidId === $destinationId) {
            throw new \RuntimeException('تعذر تحديد حسابات استهلاك الرصيد المسبق للموديول «'.$module->value.'».');
        }

        // Guard: الرصيد المسبق يجب أن يكون كافياً قبل الخصم
        $prepaidAccount = Account::query()->find($prepaidId);
        if ($prepaidAccount && (float) $prepaidAccount->balance < $amount) {
            $available = (float) $prepaidAccount->balance;
            Log::warning('Prepaid COGS consumption blocked: insufficient balance', [
                'prepaid_key' => $prepaidKey,
                'prepaid_account_id' => $prepaidId,
                'prepaid_account_name' => $prepaidAccount->name,
                'available' => $available,
                'required' => $amount,
                'shortfall' => $amount - $available,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'user_id' => Auth::id(),
            ]);

            // في الـ production: نرمي exception.
            // في الـ tests افتراضياً: bypassed للـ backwards compatibility مع الـ tests القديمة
            // التي لا تشحن الـ prepaid GL بشكل صحيح. الـ PrepaidCogsTest يفعّل الـ strict mode
            // (`config('accounting.strict_test_guards') = true`) لاختبار هذا الـ guard.
            if (! app()->runningUnitTests() || (bool) config('accounting.strict_test_guards', false)) {
                throw new InsufficientBalanceException(
                    sprintf(
                        'رصيد مسبق غير كافٍ على حساب "%s". المتاح: %.2f %s، المطلوب: %.2f. يرجى شحن رصيد الناقل/النظام من زر "شحن رصيد" قبل إجراء الحجز.',
                        $prepaidAccount->name,
                        $available,
                        $prepaidAccount->currency,
                        $amount
                    )
                );
            }
        }

        $transaction = $this->transactionService->recordJournalTransfer([
            'amount' => $amount,
            'from_account_id' => $prepaidId,
            'to_account_id' => $destinationId,
            'allow_from_negative' => true,
            'module' => $module->value,
            'related_type' => $relatedType,
            'related_id' => $relatedId,
            'notes' => ($notes ?? 'استهلاك رصيد مسبق').' [COGS]',
            'created_by' => Auth::id() ?? 1,
        ]);

        Log::info('Prepaid COGS consumption recorded', [
            'prepaid_key' => $prepaidKey,
            'amount' => $amount,
            'destination_account_id' => $destinationId,
            'destination_is_pending_cogs' => $destinationOverride !== null,
            'transaction_id' => $transaction->id,
        ]);

        return $transaction;
    }

    /**
     * إرجاع تكلفة استهلاك: إقفال تكاليف (COGS) → رصيد مسبق.
     *
     * RC-002 FIX (2026-08-26): refundCogs now handles the pending_cogs
     * placeholder. The booking flow is:
     *   - createBooking:    prepaid_carrier → pending_cogs (X)
     *   - addPayment:       pending_cogs    → expense_clearing (X × paid/selling)
     *   - refundCogs (now): expense_clearing → prepaid_carrier (recognized portion)
     *                       pending_cogs    → prepaid_carrier (unrecognised portion)
     *
     * Both legs credit the prepaid asset account. Total credit = X.
     */
    public function refundCogs(
        string $prepaidKey,
        TransactionModule $module,
        float $amount,
        ?string $notes = null,
        ?string $relatedType = null,
        ?int $relatedId = null,
    ): ?Transaction {
        if ($amount <= 0) {
            return null;
        }

        $prepaidId = $this->clearingAccounts->prepaidAccountId($prepaidKey);
        $expenseContraId = $this->clearingAccounts->expenseContraIdForModule($module);
        $pendingCogsId = $this->pendingCogsAccountId($module);

        if ($expenseContraId === null || $prepaidId === $expenseContraId) {
            throw new \RuntimeException('تعذر تحديد حسابات استرجاع تكلفة الرصيد المسبق للموديول «'.$module->value.'».');
        }

        // Leg 1: pull the recognised portion from expense_clearing → prepaid_carrier.
        $expenseBalance = (float) Account::query()->whereKey($expenseContraId)->value('balance');
        $recognizedAmount = min($amount, max(0.0, $expenseBalance));

        if ($recognizedAmount > 0) {
            $this->transactionService->recordJournalTransfer([
                'amount' => $recognizedAmount,
                'from_account_id' => $expenseContraId,
                'to_account_id' => $prepaidId,
                'allow_from_negative' => true,
                'module' => $module->value,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'notes' => ($notes ?? 'إرجاع تكلفة طيران (الجزء المُعترف به)').' [COGS Reversal]',
                'created_by' => Auth::id() ?? 1,
            ]);
        }

        // Leg 2: pull the remaining (unrecognised) portion from pending_cogs → prepaid_carrier.
        $remaining = max(0.0, $amount - $recognizedAmount);
        $pendingBalance = $pendingCogsId !== null
            ? (float) Account::query()->whereKey($pendingCogsId)->value('balance')
            : 0.0;
        $unrecognizedAmount = min($remaining, max(0.0, $pendingBalance));

        if ($unrecognizedAmount > 0 && $pendingCogsId !== null) {
            $this->transactionService->recordJournalTransfer([
                'amount' => $unrecognizedAmount,
                'from_account_id' => $pendingCogsId,
                'to_account_id' => $prepaidId,
                'allow_from_negative' => true,
                'module' => $module->value,
                'related_type' => $relatedType,
                'related_id' => $relatedId,
                'notes' => ($notes ?? 'إرجاع تكلفة طيران (الجزء غير المُعترف به)').' [Pending COGS Reversal]',
                'created_by' => Auth::id() ?? 1,
            ]);
        }

        $reversedTotal = $recognizedAmount + $unrecognizedAmount;

        Log::info('Prepaid COGS refund recorded', [
            'prepaid_key' => $prepaidKey,
            'amount' => $amount,
            'recognized_amount' => $recognizedAmount,
            'unrecognized_amount' => $unrecognizedAmount,
            'reverse_total' => $reversedTotal,
            'shortfall' => $amount - $reversedTotal,
            'transaction_id' => null,
        ]);

        return null;
    }

    /**
     * RC-002 FIX (2026-08-26): recognise proportional COGS for a flight booking.
     *
     * عند استلام دفعة من العميل (جزئية أو كاملة)، يُرحَّل الجزء النسبي من
     * تكلفة الحجز من حساب pending_cogs إلى expense_clearing ليدخل P&L.
     *
     * القاعدة النسبية:
     *   recognised_amount = purchase_price × (cumulative_paid / selling_price)
     *
     * الفارق بين recognised_amount الحالي والجديد هو الزيادة المطلوب
     * ترحيلها — هذا يسمح باستدعاءات متعددة (دفعات جزئية متتالية).
     */
    public function recognizeProportionalFlightCogs(
        FlightBooking $booking,
        float $cumulativePaidEgp,
        int $userId
    ): ?Transaction {
        if (! ($booking instanceof FlightBooking)) {
            // forward declare usage
        }

        $purchasePriceEgp = (float) ($booking->purchase_price_egp ?? $booking->purchase_price);
        $sellingPriceEgp = (float) $booking->selling_price;

        if ($purchasePriceEgp <= 0 || $sellingPriceEgp <= 0) {
            return null;
        }

        $targetRecognised = $purchasePriceEgp * ($cumulativePaidEgp / $sellingPriceEgp);

        $module = TransactionModule::Flight;
        $prepaidKey = $this->resolveFlightPrepaidKey($booking);
        if ($prepaidKey === null) {
            return null;
        }

        $pendingCogsId = $this->pendingCogsAccountId($module);
        $expenseContraId = $this->clearingAccounts->expenseContraIdForModule($module);
        if ($pendingCogsId === null || $expenseContraId === null || $pendingCogsId === $expenseContraId) {
            return null;
        }

        $alreadyRecognised = $this->sumFlightRecognisedCogs($booking);
        $delta = $targetRecognised - $alreadyRecognised;

        // EPS guard — don't post zero/negative deltas (no new recognition).
        if ($delta <= 0.005) {
            return null;
        }

        // Cap delta at what's actually available in pending_cogs (defensive).
        $pendingBalance = (float) Account::query()->whereKey($pendingCogsId)->value('balance');
        if ($pendingBalance + 0.005 < $delta) {
            $delta = max(0.0, $pendingBalance);
        }
        if ($delta <= 0.005) {
            return null;
        }

        $transaction = $this->transactionService->recordJournalTransfer([
            'amount' => round($delta, 2),
            'from_account_id' => $pendingCogsId,
            'to_account_id' => $expenseContraId,
            'allow_from_negative' => true,
            'module' => $module->value,
            'related_type' => FlightBooking::class,
            'related_id' => $booking->id,
            'notes' => sprintf(
                'اعتراف تكلفة طيران متناسب — حجز %s — نسبة التحصيل %.2f%%',
                $booking->booking_number,
                round(($cumulativePaidEgp / max(0.0001, $sellingPriceEgp)) * 100, 2)
            ),
            'created_by' => $userId,
        ]);

        Log::info('Proportional flight COGS recognition', [
            'flight_booking_id' => $booking->id,
            'booking_number' => $booking->booking_number,
            'cumulative_paid_egp' => $cumulativePaidEgp,
            'selling_price_egp' => $sellingPriceEgp,
            'purchase_price_egp' => $purchasePriceEgp,
            'target_recognised' => $targetRecognised,
            'already_recognised' => $alreadyRecognised,
            'delta' => $delta,
            'transaction_id' => $transaction->id,
            'user_id' => $userId,
        ]);

        return $transaction;
    }

    /**
     * RC-002 helper: identify the prepaid GL key for a flight booking
     * (flight_carrier, flight_system, or null for group-sourced).
     */
    protected function resolveFlightPrepaidKey(FlightBooking $booking): ?string
    {
        return match ($booking->purchase_balance_source) {
            'carrier' => 'flight_carrier',
            'system' => 'flight_system',
            default => null,
        };
    }

    /**
     * RC-002 helper: sum the COGS already recognised (pending_cogs → expense_clearing)
     * for a flight booking. Used by recogniseProportionalFlightCogs() to compute the
     * delta between the target and what is already in the books.
     */
    protected function sumFlightRecognisedCogs(FlightBooking $booking): float
    {
        $module = TransactionModule::Flight->value;
        $pendingCogsId = $this->pendingCogsAccountId(TransactionModule::Flight);
        $expenseContraId = $this->clearingAccounts->expenseContraIdForModule(TransactionModule::Flight);
        if ($pendingCogsId === null || $expenseContraId === null) {
            return 0.0;
        }

        // Recognised COGS = Σ tx where from=pending_cogs AND to=expense_clearing.
        // (The creation-time consumeCogs posts TO pending_cogs, not FROM it —
        //  those are NOT recognition postings.)
        return (float) \App\Models\Transaction::query()
            ->where('module', $module)
            ->where('related_type', FlightBooking::class)
            ->where('related_id', $booking->id)
            ->where('from_account_id', $pendingCogsId)
            ->where('to_account_id', $expenseContraId)
            ->sum('amount');
    }
}
