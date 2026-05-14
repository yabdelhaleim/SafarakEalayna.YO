<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlightPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'flight_booking_id',
        'payment_method',
        'amount',
        'currency',
        'treasury_account',
        'transaction_reference',
        'payment_date',
        'paid_by',
        'account_id',
        'transaction_id',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'payment_date' => 'datetime',
        'treasury_account' => 'string',
        'payment_method' => 'string',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }
}
