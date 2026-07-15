<?php

namespace App\Models\Flight;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'booking_id',
    'modification_type',
    'original_departure_date',
    'new_departure_date',
    'original_destination',
    'new_destination',
    'original_flight_number',
    'new_flight_number',
    'airline_change_fee',
    'agency_commission',
    'total_charged_to_customer',
    'currency',
    'payment_method',
    'deducted_from_airline_balance',
    'status',
    'notes',
    'modified_by',
    'confirmed_at',
    'airline_change_fee_snapshot',
    'commission_snapshot',
    'exchange_rate_snapshot',
    'ip_address',
    'reason_for_change',
    'reconciliation_status',
    'reconciled_invoice_number',
    'reconciled_at',
])]
class TicketModification extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'booking_id',
        'modification_type',
        'original_departure_date',
        'new_departure_date',
        'original_destination',
        'new_destination',
        'original_flight_number',
        'new_flight_number',
        'airline_change_fee',
        'agency_commission',
        'total_charged_to_customer',
        'currency',
        'payment_method',
        'deducted_from_airline_balance',
        'status',
        'notes',
        'modified_by',
        'confirmed_at',
        'airline_change_fee_snapshot',
        'commission_snapshot',
        'exchange_rate_snapshot',
        'ip_address',
        'reason_for_change',
        'reconciliation_status',
        'reconciled_invoice_number',
        'reconciled_at',
    ];

    protected function casts(): array
    {
        return [
            'original_departure_date' => 'date',
            'new_departure_date' => 'date',
            'airline_change_fee' => 'decimal:2',
            'agency_commission' => 'decimal:2',
            'total_charged_to_customer' => 'decimal:2',
            'deducted_from_airline_balance' => 'boolean',
            'confirmed_at' => 'datetime',
            'airline_change_fee_snapshot' => 'decimal:2',
            'commission_snapshot' => 'decimal:2',
            'exchange_rate_snapshot' => 'decimal:6',
            'reconciled_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'booking_id');
    }

    public function modifiedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'modified_by');
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', 'confirmed');
    }

    public function scopePendingReconciliation(Builder $query): Builder
    {
        return $query->where('status', 'confirmed')->where('reconciliation_status', 'unreconciled');
    }
}
