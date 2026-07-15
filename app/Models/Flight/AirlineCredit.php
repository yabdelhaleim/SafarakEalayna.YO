<?php

namespace App\Models\Flight;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'flight_carrier_id',
    'customer_id',
    'currency',
    'amount',
    'expiry_date',
    'flight_booking_id',
    'refund_request_id',
    'status',
])]
class AirlineCredit extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'flight_carrier_id',
        'customer_id',
        'currency',
        'amount',
        'expiry_date',
        'flight_booking_id',
        'refund_request_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'expiry_date' => 'date',
        ];
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(FlightCarrier::class, 'flight_carrier_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }

    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(RefundRequest::class, 'refund_request_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeUsed(Builder $query): Builder
    {
        return $query->where('status', 'used');
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', 'expired');
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', 'cancelled');
    }

    /**
     * Cancel this voucher: change status to 'cancelled' + soft-delete the row.
     *
     * Per the project rule: AirlineCredit is a *future-use voucher* — it NEVER had
     * a corresponding GL entry at creation time (see RefundService::processRefundRequest
     * branch A at L95-117), so there is no GL to reverse here. The only thing we
     * do is make sure the customer/carrier can no longer use or list this credit.
     *
     * Safe to call multiple times (idempotent) — if already cancelled the second call
     * is a no-op.
     */
    public function cancelCredit(): bool
    {
        if ($this->status === 'cancelled' && $this->trashed()) {
            return false; // Already cancelled
        }

        $this->status = 'cancelled';
        $this->save();

        $this->delete(); // Soft delete (SoftDeletes trait)

        return true;
    }
}
