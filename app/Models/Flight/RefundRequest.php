<?php

namespace App\Models\Flight;

use App\Models\Treasury;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'flight_booking_id',
    'refund_case',
    'refund_type',
    'original_currency',
    'original_amount',
    'supplier_cost',
    'supplier_refund_amount',
    'actual_airline_refund_amount',
    'supplier_refund_loss',
    'refund_variance_amount',
    'airline_penalty',
    'agency_commission',
    'apply_commission',
    'non_refundable_taxes_on_customer',
    'non_refundable_taxes_amount',
    'cancellation_fee',
    'refund_amount',
    'customer_credit_amount',
    'customer_debt_settled_amount',
    'company_id',
    'credit_ledger_entry_id',
    'reversal_state',
    'disbursed_amount',
    'disbursement_status',
    'refund_currency',
    'refund_exchange_rate',
    'base_currency_refund',
    'currency_difference',
    'destination',
    'treasury_id',
    'airline_credit_balance',
    'status',
    'supplier_refund_confirmed',
    'supplier_refund_confirmed_at',
    'notes',
    'processed_at',
    'created_by',
    'idempotency_key',
])]
class RefundRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'flight_booking_id',
        'refund_case',
        'refund_type',
        'original_currency',
        'original_amount',
        'supplier_cost',
        'supplier_refund_amount',
        'actual_airline_refund_amount',
        'supplier_refund_loss',
        'refund_variance_amount',
        'airline_penalty',
        'agency_commission',
        'apply_commission',
        'non_refundable_taxes_on_customer',
        'non_refundable_taxes_amount',
        'cancellation_fee',
        'refund_amount',
        'customer_credit_amount',
        'customer_debt_settled_amount',
        'company_id',
        'credit_ledger_entry_id',
        'reversal_state',
        'disbursed_amount',
        'disbursement_status',
        'refund_currency',
        'refund_exchange_rate',
        'base_currency_refund',
        'currency_difference',
        'destination',
        'treasury_id',
        'airline_credit_balance',
        'status',
        'supplier_refund_confirmed',
        'supplier_refund_confirmed_at',
        'notes',
        'processed_at',
        'created_by',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'original_amount' => 'decimal:2',
            'supplier_cost' => 'decimal:2',
            'supplier_refund_amount' => 'decimal:2',
            'actual_airline_refund_amount' => 'decimal:2',
            'supplier_refund_loss' => 'decimal:2',
            'refund_variance_amount' => 'decimal:2',
            'airline_penalty' => 'decimal:2',
            'agency_commission' => 'decimal:2',
            'apply_commission' => 'boolean',
            'non_refundable_taxes_on_customer' => 'boolean',
            'non_refundable_taxes_amount' => 'decimal:2',
            'cancellation_fee' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'customer_credit_amount' => 'decimal:2',
            'customer_debt_settled_amount' => 'decimal:2',
            'disbursed_amount' => 'decimal:2',
            'refund_exchange_rate' => 'decimal:6',
            'base_currency_refund' => 'decimal:2',
            'currency_difference' => 'decimal:2',
            'airline_credit_balance' => 'decimal:2',
            'supplier_refund_confirmed' => 'boolean',
            'supplier_refund_confirmed_at' => 'datetime',
            'processed_at' => 'datetime',
            'reversal_state' => 'array',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }

    public function treasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class, 'treasury_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function airlineCredit(): HasOne
    {
        return $this->hasOne(AirlineCredit::class, 'refund_request_id');
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(CustomerCreditDisbursement::class, 'refund_request_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'company_id');
    }

    public function creditLedgerEntry(): BelongsTo
    {
        return $this->belongsTo(CustomerCreditLedger::class, 'credit_ledger_entry_id');
    }

    public function getAvailableCustomerCreditAttribute(): float
    {
        return max(0.0, round((float) $this->customer_credit_amount - (float) $this->disbursed_amount, 2));
    }

    public function isSupplierRefundConfirmed(): bool
    {
        return (bool) $this->supplier_refund_confirmed;
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopeProcessed(Builder $query): Builder
    {
        return $query->where('status', 'processed');
    }
}
