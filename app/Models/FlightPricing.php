<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Flight\FlightBooking;

class FlightPricing extends Model
{
    use HasFactory;

    protected $fillable = [
        'flight_booking_id',
        'currency',
        'purchase_price',
        'selling_price',
        'profit',
        'booking_currency',
        'amount_in_foreign_currency',
        'exchange_rate_used',
        'purchase_price_egp',
        'selling_price_egp',
        'profit_egp',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'profit' => 'decimal:2',
            'amount_in_foreign_currency' => 'decimal:2',
            'exchange_rate_used' => 'decimal:4',
            'purchase_price_egp' => 'decimal:2',
            'selling_price_egp' => 'decimal:2',
            'profit_egp' => 'decimal:2',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(FlightBooking::class, 'flight_booking_id');
    }
}
