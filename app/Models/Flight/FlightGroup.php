<?php

namespace App\Models\Flight;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'flight_carrier_id',
    'account_id',
    'name',
    'code',
    'contact_person',
    'contact_phone',
    'contact_email',
    'commission_rate',
    'credit_limit',
    'notification_threshold_info',
    'notification_threshold_warning',
    'notification_threshold_danger',
    'notify_via_toast',
    'notify_via_widget',
    'notify_via_bell',
    'last_threshold_level',
    'last_threshold_notified_at',
    'is_active',
    'notes',
    'created_by',
])]
class FlightGroup extends Model
{
    use SoftDeletes;

    public const THRESHOLD_LEVEL_INFO = 'info';
    public const THRESHOLD_LEVEL_WARNING = 'warning';
    public const THRESHOLD_LEVEL_DANGER = 'danger';

    public const THRESHOLD_LEVEL_SEVERITY = [
        self::THRESHOLD_LEVEL_INFO => 1,
        self::THRESHOLD_LEVEL_WARNING => 2,
        self::THRESHOLD_LEVEL_DANGER => 3,
    ];

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'notification_threshold_info' => 'decimal:2',
            'notification_threshold_warning' => 'decimal:2',
            'notification_threshold_danger' => 'decimal:2',
            'notify_via_toast' => 'boolean',
            'notify_via_widget' => 'boolean',
            'notify_via_bell' => 'boolean',
            'is_active' => 'boolean',
            'last_threshold_notified_at' => 'datetime',
        ];
    }

    /**
     * Reset threshold-level tracking so that future descent triggers notification again.
     * Called by FlightGroupController::payDebt when balance improves.
     */
    public function resetThresholdTracking(): void
    {
        $this->last_threshold_level = null;
        $this->last_threshold_notified_at = null;
        $this->save();
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(FlightCarrier::class, 'flight_carrier_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(FlightBooking::class);
    }

    public function groupTransactions(): HasMany
    {
        return $this->hasMany(FlightGroupTransaction::class, 'flight_group_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCarrier($query, int $carrierId)
    {
        return $query->where('flight_carrier_id', $carrierId);
    }
}
