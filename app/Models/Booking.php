<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Booking extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'booking_ref',
        'customer_id',
        'flight_id',
        'passengers_count',
        'passengers_data',
        'total_price',
        'paid_amount',
        'payment_status',
        'booking_status',
        'payment_method',
        'confirmed_at',
        'cancellation_reason',
    ];

    protected $casts = [
        'passengers_data' => 'array',
        'total_price' => 'decimal:2',
        'paid_amount' => 'decimal:2',
        'confirmed_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }
}
