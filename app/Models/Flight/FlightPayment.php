<?php

namespace App\Models\Flight;

use App\Enums\FlightPaymentMethod;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'flight_booking_id',
    'amount',
    'original_amount',
    'payment_method',
    'currency',
    'treasury_account',
    'transaction_reference',
    'payment_date',
    'paid_by',
    'account_id',
    'transaction_id',
    'notes',
    'created_by',
])]
class FlightPayment extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'original_amount' => 'decimal:4',
            'payment_date' => 'datetime',
            'payment_method' => FlightPaymentMethod::class,
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
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
