<?php

namespace App\Models\Bus;

use App\Enums\BusInventoryPaymentType;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id',
    'route',
    'travel_date',
    'departure_time',
    'total_tickets',
    'available_tickets',
    'cost_per_ticket',
    'selling_price',
    'payment_type',
    'total_cost',
    'amount_paid',
    'remaining_debt',
    'account_id',
    'transaction_id',
    'notes',
    'created_by',
])]
class BusInventory extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'payment_type' => BusInventoryPaymentType::class,
            'cost_per_ticket' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'total_cost' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'remaining_debt' => 'decimal:2',
            'travel_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (BusInventory $inventory) {
            if ($inventory->bookings()->exists()) {
                throw new \RuntimeException('لا يمكن حذف رحلة باص تحتوي على حجوزات نشطة. يرجى إلغاء الحجز أولاً أو أرشفة الرحلة.');
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(BusCompany::class, 'company_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(BusBooking::class, 'inventory_id');
    }

    public function companyPayments(): HasMany
    {
        return $this->hasMany(BusCompanyPayment::class, 'inventory_id');
    }

    public function scopeByCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }

    public function scopeByDate(Builder $query, string $date): Builder
    {
        return $query->where('travel_date', $date);
    }

    public function scopeHasAvailableTickets(Builder $query): Builder
    {
        return $query->where('available_tickets', '>', 0);
    }

    public function scopeWithDebt(Builder $query): Builder
    {
        return $query->where('remaining_debt', '>', 0);
    }
}
