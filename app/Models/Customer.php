<?php

namespace App\Models;

use App\Models\Flight\FlightBooking;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'account_id',
        'full_name',
        'phone',
        'email',
        'national_id',
        'passport_number',
        'passport_expiry',
        'date_of_birth',
        'city',
        'affiliation',
        'customer_tier',
        'notes',
        'type',
        'created_by',
    ];

    protected $casts = [
        'customer_tier' => \App\Enums\CustomerTier::class,
        'passport_expiry' => 'date',
        'date_of_birth' => 'date',
    ];

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function flightBookings(): HasMany
    {
        return $this->hasMany(FlightBooking::class);
    }

    public function hajjUmraBookings(): HasMany
    {
        return $this->hasMany(HajjUmraBooking::class);
    }

    public function visaBookings(): HasMany
    {
        return $this->hasMany(VisaBooking::class);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where('full_name', 'like', "%{$search}%")
            ->orWhere('phone', 'like', "%{$search}%")
            ->orWhere('national_id', 'like', "%{$search}%")
            ->orWhere('passport_number', 'like', "%{$search}%");
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
