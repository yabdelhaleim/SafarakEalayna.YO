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
}
