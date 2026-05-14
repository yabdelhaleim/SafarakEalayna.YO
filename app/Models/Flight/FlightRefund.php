<?php

namespace App\Models\Flight;

use App\Models\Account;
use App\Models\Flight\FlightBooking;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'flight_booking_id',
    'airline_penalty',
    'office_penalty',
    'total_paid',
    'refund_amount',
    'account_id',
    'transaction_id',
    'status',
    'notes',
    'created_by',
])]
class FlightRefund extends Model
{
    protected function casts(): array
    {
        return [
            'airline_penalty' => 'decimal:2',
            'office_penalty' => 'decimal:2',
            'total_paid' => 'decimal:2',
            'refund_amount' => 'decimal:2',
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
