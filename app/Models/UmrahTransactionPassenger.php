<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UmrahTransactionPassenger extends Model
{
    use HasFactory;

    protected $fillable = [
        'transaction_id',
        'category',
        'count',
        'unit_price',
        'subtotal',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'count' => 'integer',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(HajjUmraBooking::class, 'transaction_id');
    }
}
