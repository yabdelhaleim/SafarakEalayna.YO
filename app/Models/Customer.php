<?php

namespace App\Models;

use App\Enums\CustomerTier;
use App\Enums\CustomerType;
use App\Models\Bus\BusBooking;
use App\Models\Fawry\FawryTransaction;
use App\Models\Flight\FlightBooking;
use App\Models\Online\OnlineTransaction;
use App\Models\Wallet\WalletTransaction;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\ClearsCache;

class Customer extends Model
{
    use HasFactory, SoftDeletes, ClearsCache;

    protected $fillable = [
        'account_id',
        'full_name',
        'name',
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
        'module_type', // Phase 3.5 fix: declare which business module owns this customer (flights, hajj_umra, visas, bus, …).
        'created_by',
        'nationality',
        'gender',
        'address',
        'status',
        'total_spent',
        'bookings_count',
        'whatsapp_number',
        'travel_country',
    ];

    protected $casts = [
        'customer_tier' => CustomerTier::class,
        'type' => CustomerType::class,
        'passport_expiry' => 'date',
        'date_of_birth' => 'date',
        'total_spent' => 'decimal:2',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function flightBookings(): HasMany
    {
        return $this->hasMany(FlightBooking::class);
    }

    public function busBookings(): HasMany
    {
        return $this->hasMany(BusBooking::class);
    }

    public function hajjUmraBookings(): HasMany
    {
        return $this->hasMany(HajjUmraBooking::class);
    }

    public function visaBookings(): HasMany
    {
        return $this->hasMany(VisaBooking::class);
    }

    public function fawryTransactions(): HasMany
    {
        return $this->hasMany(FawryTransaction::class, 'client_id');
    }

    public function onlineTransactions(): HasMany
    {
        return $this->hasMany(OnlineTransaction::class, 'customer_id');
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class, 'customer_id');
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
