<?php

namespace App\Models\Flight;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Transaction;
use App\Models\Treasury;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'refund_request_id',
    'customer_id',
    'disbursement_type',
    'amount',
    'treasury_id',
    'bank_account_id',
    'target_flight_booking_id',
    'transaction_id',
    'status',
    'notes',
    'created_by',
])]
class CustomerCreditDisbursement extends Model
{
    use SoftDeletes;

    public const TYPE_CASH = 'cash';
    public const TYPE_BANK = 'bank';
    public const TYPE_BOOKING_SETTLEMENT = 'booking_settlement';

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'refund_request_id',
        'customer_id',
        'disbursement_type',
        'amount',
        'treasury_id',
        'bank_account_id',
        'target_flight_booking_id',
        'transaction_id',
        'status',
        'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function refundRequest(): BelongsTo
    {
        return $this->belongsTo(RefundRequest::class, 'refund_request_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function treasury(): BelongsTo
    {
        return $this->belongsTo(Treasury::class, 'treasury_id');
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'bank_account_id');
    }

    public function targetFlightBooking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'target_flight_booking_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
