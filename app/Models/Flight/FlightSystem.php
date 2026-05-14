<?php

namespace App\Models\Flight;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name',
    'code',
    'type',
    'is_active',
    'currency',
    'balance',
    'credit_limit',
    'description',
    'created_by',
])]
class FlightSystem extends Model
{
    use SoftDeletes;

    protected $appends = [
        'available_balance',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
        ];
    }

    /**
     * إجمالي القدرة على الخصم قبل الحجز = الرصيد المدفوع + حد الائتمان الإضافي
     * (يتوافق مع AirlineAccount ومع سيناريو «شحن رصيد + سقف ائتمان»).
     */
    public function getAvailableBalanceAttribute(): float
    {
        return (float) $this->balance + (float) $this->credit_limit;
    }

    public function carriers(): HasMany
    {
        return $this->hasMany(FlightCarrier::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(\App\Models\FlightBooking::class);
    }

    public function systemTransactions(): HasMany
    {
        return $this->hasMany(FlightSystemTransaction::class, 'flight_system_id');
    }

    public function debit(float $amount, int $bookingId, int $userId): FlightSystemTransaction
    {
        if ($this->available_balance < $amount) {
            throw new \Exception(
                'رصيد نظام الحجز غير كافٍ. '.
                "المطلوب: {$amount} {$this->currency}، ".
                "المتاح: {$this->available_balance} {$this->currency}"
            );
        }

        $before = (float) $this->balance;
        $this->decrement('balance', $amount);

        return $this->systemTransactions()->create([
            'flight_booking_id' => $bookingId,
            'type' => 'debit',
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => (float) $this->fresh()->balance,
            'description' => 'خصم تكلفة حجز تذكرة',
            'created_by' => $userId,
        ]);
    }

    public function credit(float $amount, string $description, int $userId, ?int $bookingId = null): FlightSystemTransaction
    {
        $before = (float) $this->balance;
        $this->increment('balance', $amount);

        return $this->systemTransactions()->create([
            'flight_booking_id' => $bookingId,
            'type' => 'credit',
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => (float) $this->fresh()->balance,
            'description' => $description,
            'created_by' => $userId,
        ]);
    }

    public function createdBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
