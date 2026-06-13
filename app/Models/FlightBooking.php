<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class FlightBooking extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'booking_reference',
        'booking_number',
        'booking_channel_type',
        'booking_channel_provider',
        'status',
        'customer_id',
        'employee_id',
        'created_by',
        'agent_name',
        'notes',
        // Flight details
        'origin',
        'destination',
        'departure_date',
        'departure_time',
        'return_date',
        'return_time',
        'trip_type',
        'system_type',
        'airline',
        'airline_name',
        'passenger_count',
        'baggage_allowance_kg',
        'from_airport',
        'to_airport',
        'pnr',
        'account_id',
        'purchase_price',
        'selling_price',
        'profit',
    ];

    protected function casts(): array
    {
        return [
            'booking_channel_type' => \App\Casts\BookingChannelTypeCast::class,
            'status' => \App\Enums\FlightBookingStatus::class,
            'trip_type' => \App\Enums\TripType::class,
            'system_type' => \App\Enums\FlightSystemType::class,
            'departure_date' => 'date',
            'return_date' => 'date',
            'passenger_count' => 'integer',
            'baggage_allowance_kg' => 'integer',
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'profit' => 'decimal:2',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function passengers(): HasMany
    {
        return $this->hasMany(Passenger::class);
    }

    public function pricing(): HasOne
    {
        return $this->hasOne(FlightPricing::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FlightPayment::class);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('booking_reference', 'like', "%{$search}%")
            ->orWhereHas('customer', function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%");
            })
            ->orWhere('airline', 'like', "%{$search}%");
    }
}
