<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Flight extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'flight_number',
        'airline',
        'origin',
        'destination',
        'departure_at',
        'arrival_at',
        'class',
        'total_seats',
        'available_seats',
        'base_price',
        'tax_percent',
        'status',
        'notes',
    ];

    protected $casts = [
        'departure_at' => 'datetime',
        'arrival_at' => 'datetime',
        'base_price' => 'decimal:2',
        'tax_percent' => 'decimal:2',
    ];

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
