<?php

namespace App\Models\Flight;

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
    'is_active',
    'notes',
    'created_by',
])]
class FlightGroup extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'commission_rate' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (FlightGroup $group) {
            if ($group->account_id !== null) {
                return;
            }

            $userId = $group->created_by ?: 1;

            $account = \App\Models\Account::create([
                'name' => 'مجموعة طيران — ' . $group->name . ' · ' . $group->code,
                'type' => \App\Enums\AccountType::Supplier->value,
                'currency' => 'EGP',
                'balance' => 0,
                'is_active' => true,
                'owner_type' => \App\Models\Account::OWNER_TYPE_OFFICE,
                'module_type' => 'tourism',
                'module' => 'flight',
                'notes' => 'حساب جاري مجموعة طيران، يُنشأ تلقائياً مع سجل المجموعة للربط المحاسبي.',
                'created_by' => $userId,
            ]);

            $group->account_id = $account->id;
            $group->saveQuietly();
        });
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Account::class, 'account_id');
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(FlightCarrier::class, 'flight_carrier_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(\App\Models\Flight\FlightBooking::class);
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
