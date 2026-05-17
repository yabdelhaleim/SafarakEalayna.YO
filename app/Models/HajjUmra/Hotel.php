<?php

namespace App\Models\HajjUmra;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Hotel extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'city',
        'phone',
        'email',
        'account_id',
        'notes',
        'is_active',
        'country',
        'stars',
        'price_per_night',
        'total_rooms',
        'available_rooms',
        'contact_phone',
        'contact_email',
        'description',
        'amenities',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'stars' => 'integer',
        'price_per_night' => 'decimal:2',
        'total_rooms' => 'integer',
        'available_rooms' => 'integer',
        'amenities' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Account::class);
    }
}
