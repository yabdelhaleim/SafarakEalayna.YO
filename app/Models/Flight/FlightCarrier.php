<?php

namespace App\Models\Flight;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'flight_system_id',
    'name',
    'code',
    'iata_code',
    'currency',
    'balance',
    'credit_limit',
    'is_active',
    'notes',
    'created_by',
])]
class FlightCarrier extends Model
{
    use SoftDeletes;

    /**
     * يُضمَّن في JSON (قائمة الناقلين، Vue، وغيرها) حتى تُحسب الواجهة «المتاح» بشكل صحيح.
     *
     * @var list<string>
     */
    protected $appends = [
        'available_balance',
    ];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(FlightSystem::class, 'flight_system_id')->withDefault([
            'name' => 'ساين (بدون نظام)',
        ]);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(FlightGroup::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(\App\Models\Flight\FlightBooking::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AirlineTransaction::class, 'flight_carrier_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * إجمالي المتاح للخصم = الرصيد الحالي + حد الائتمان (سقف إضافي)، وليس طرح الحد من الرصيد.
     */
    public function getAvailableBalanceAttribute(): float
    {
        return (float) $this->balance + (float) $this->credit_limit;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCurrency($query, string $currency)
    {
        return $query->where('currency', $currency);
    }

    public function scopeBySystem($query, ?int $systemId)
    {
        if ($systemId === null) {
            return $query->whereNull('flight_system_id');
        }
        return $query->where('flight_system_id', $systemId);
    }

    public function debit(float $amount, int $bookingId, int $userId): AirlineTransaction
    {
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
}
