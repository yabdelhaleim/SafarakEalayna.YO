<?php

namespace App\Models;

use App\Models\Flight\FlightBooking;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @deprecated Use {@see Transaction} + {@see AccountEntry} as source of truth for balances.
 *             Kept for operational “خزينة” traceability; new rows should set ledger_transaction_id when possible.
 */
class TreasuryTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_type',
        'account_id',
        'from_treasury',
        'to_treasury',
        'amount',
        'currency',
        'balance_before',
        'balance_after',
        'reason',
        'flight_booking_id',
        'hajj_umra_booking_id',
        'visa_booking_id',
        'bus_booking_id',
        'agent_name',
        'reference_number',
        'ledger_transaction_id',
        'treasury_id',
        'refund_request_id',
        'type',
        'exchange_rate',
        'base_amount',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'balance_before' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'exchange_rate' => 'decimal:6',
        'base_amount' => 'decimal:2',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }

    public function busBooking(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Bus\BusBooking::class, 'bus_booking_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function ledgerTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'ledger_transaction_id');
    }

    public function treasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class, 'treasury_id');
    }

    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Flight\RefundRequest::class, 'refund_request_id');
    }

    /**
     * اربط هذا الـ TreasuryTransaction بالـ GL العام عن طريق الـ Transaction الأساسي.
     *
     * يُستدعى هذا بعد ما الـ caller يكون عمل `Treasury::credit()` أو
     * `Treasury::debit()` (اللي بيعدّل الرصيد المحلي) وبعد ما يكون عمل
     * `TransactionService::recordJournalTransfer()` (اللي بيعدّل رصيد الـ Account
     * المقابل في الـ GL العام).
     *
     * الـ TreasuryTransaction كان legacy بدون ربط بالـ GL — ده كان يخلق
     * desync symptom (rows مع balance_before/after بس بدون ledger_transaction_id).
     * الـ helper ده يصلح الـ GAP بربط الـ local audit row بالـ GL row
     * المعادل عن طريق:
     *   - account_id       (الـ Account في الـ GL اللي اتأثر)
     *   - ledger_transaction_id (الـ Transaction في الـ GL الأساسي)
     *
     * ملاحظة على الـ orphans القديمة: الـ rows اللي ledger_transaction_id = null
     * هي بيانات قديمة من قبل هذا الربط. هي بيانات تجريبية بالكامل ولا تحتاج تصحيح.
     *
     * @param  Transaction  $glTransaction  الـ قيد الأساسي في الـ GL (must be saved)
     * @param  int|null     $glAccountId    الـ account_id في الـ GL (default: from->id or to->id of the GL tx)
     * @return $this
     */
    public function linkToGl(Transaction $glTransaction, ?int $glAccountId = null): self
    {
        $this->ledger_transaction_id = $glTransaction->id;

        if ($glAccountId !== null) {
            $this->account_id = $glAccountId;
        } else {
            // Default: use the GL account that was affected (to_account_id for receipts, from for payments).
            // For Treasury credit (receipt): the GL leg debits cashbox → to_account_id = cashbox.id
            // For Treasury debit (payment): the GL leg credits cashbox → from_account_id = cashbox.id
            // Caller should pass the explicit account_id for clarity.
            $this->account_id = (int) ($glTransaction->to_account_id ?? $glTransaction->from_account_id);
        }

        $this->save();

        return $this;
    }
}
