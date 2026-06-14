<?php

namespace App\Models\Flight;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name',
    'code',
    'system_type',
    'currency',
    'balance',
    'credit_limit',
    'is_active',
    'notes'
])]
class AirlineAccount extends Model
{
    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AirlineTransaction::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(FlightBooking::class);
    }

    public function getAvailableBalanceAttribute(): float
    {
        return (float) $this->balance + (float) $this->credit_limit;
    }

    public function debit(float $amount, int $bookingId, int $userId): AirlineTransaction
    {
        // Check if sufficient balance is available
        if ($this->available_balance < $amount) {
            throw new \Exception(
                "رصيد شركة الطيران غير كافٍ. " .
                "المطلوب: {$amount} {$this->currency}، " .
                "المتاح: {$this->available_balance} {$this->currency}"
            );
        }

        $before = $this->balance;
        $this->decrement('balance', $amount);

        return $this->transactions()->create([
            'flight_booking_id' => $bookingId,
            'type' => 'debit',
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $this->balance,
            'description' => 'خصم حجز تذكرة',
            'created_by' => $userId,
        ]);
    }

    public function credit(float $amount, string $description, int $userId, ?int $bookingId = null): AirlineTransaction
    {
        $before = $this->balance;
        $this->increment('balance', $amount);

        return $this->transactions()->create([
            'flight_booking_id' => $bookingId,
            'type' => 'credit',
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $this->balance,
            'description' => $description,
            'created_by' => $userId,
        ]);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBySystemType($query, string $systemType)
    {
        return $query->where('system_type', $systemType);
    }
}
