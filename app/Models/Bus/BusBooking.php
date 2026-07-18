<?php

namespace App\Models\Bus;

use App\Enums\BusBookingStatus;
use App\Enums\BusPaymentStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Transaction;
use App\Models\User;
use App\Support\Finance\ModelDeletionGuard;
use App\Support\Finance\ModelProfitMutationGuard;
use Database\Factories\Bus\BusBookingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Traits\ClearsCache;

#[Fillable([
    'inventory_id',
    'customer_id',
    'employee_id',
    'quantity',
    'unit_price',
    'total_price',
    'paid_amount',
    'payment_status',
    'profit',
    'status',
    'account_id',
    'transaction_id',
    'notes',
    'currency',
    'exchange_rate_to_egp',
    'created_by',
])]
class BusBooking extends Model
{
    use SoftDeletes, ClearsCache, ModelDeletionGuard, ModelProfitMutationGuard, HasFactory;

    protected static function newFactory(): BusBookingFactory
    {
        return BusBookingFactory::new();
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'total_price' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'profit' => 'decimal:2',
            'status' => BusBookingStatus::class,
            'payment_status' => BusPaymentStatus::class,
            'currency' => 'string',
            'exchange_rate_to_egp' => 'decimal:6',
        ];
    }

    protected static function booted(): void
    {
        // Allowed only when:
        //   - we're inside BusBooking::run() (canonical safe path through
        //     BusBookingService::deleteBooking() / deleteBookingWithReversal()), OR
        //   - the app is running PHPUnit tests (unit/integration tests).
        // Everything else (Filament `DeleteAction`, raw tinker, accidental API
        // calls, etc.) is blocked to prevent silent balance corruption.
        //
        // The `cancelBooking()` flow is intentionally NOT in this allowlist —
        // it must keep the booking row visible (status=Cancelled) to preserve
        // the financial timeline.
        static::deleting(function (BusBooking $booking) {
            $bypassViaGuard = BusBooking::isAllowed();

            if (! app()->runningUnitTests() && ! $bypassViaGuard) {
                throw new \RuntimeException(
                    'لا يمكن حذف حجز الباص برمجياً لتجنب إفساد السجلات المالية. '
                    .'يرجى استخدام BusBookingService::deleteBookingWithReversal() للحذف الإداري، '
                    .'أو BusBookingService::cancelBooking() للإلغاء المرئي الذي يحتفظ بالصف.'
                );
            }
        });

        // Profit-column guard: `profit` is a derived figure ((selling − cost) × qty)
        // and must be set only via BusBookingService::createBooking.
        static::saving(function (BusBooking $booking): void {
            if (! $booking->isDirty('profit')) {
                return;
            }
            if (\App\Support\Finance\LedgerBalanceMutationGuard::isAllowed()) {
                return;
            }
            if (app()->runningUnitTests()) {
                return;
            }
            if (BusBooking::isProfitMutationAllowed()) {
                return;
            }
            throw new \RuntimeException(
                'لا يمكن تعديل عمود profit في حجز الباص مباشرةً. '
                .'استخدم BusBookingService::createBooking.'
            );
        });
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(BusInventory::class, 'inventory_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
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

    public function payments(): HasMany
    {
        return $this->hasMany(\App\Models\Bus\BusPayment::class, 'booking_id');
    }

    public function relatedTransactions(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(\App\Models\Transaction::class, 'related');
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(BusRefundRequest::class, 'bus_booking_id');
    }

    public function refund(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(BusRefundRequest::class, 'bus_booking_id')->latestOfMany();
    }

    public function scopeByStatus(Builder $query, BusBookingStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    public function scopeByCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeByInventory(Builder $query, int $inventoryId): Builder
    {
        return $query->where('inventory_id', $inventoryId);
    }

    public function getRemainingAmountAttribute(): float
    {
        return max(0.0, (float) $this->total_price - (float) $this->paid_amount);
    }

    public function getIsFullyPaidAttribute(): bool
    {
        return $this->paid_amount >= $this->total_price;
    }

    public function getIsPartialPaidAttribute(): bool
    {
        return $this->paid_amount > 0 && $this->paid_amount < $this->total_price;
    }

    public function recalculatePaymentStatus(): void
    {
        $this->load('payments');
        $totalPaid = $this->payments->sum('amount');
        $this->paid_amount = $totalPaid;
        $this->payment_status = $totalPaid >= $this->total_price
            ? BusPaymentStatus::Paid
            : ($totalPaid > 0 ? BusPaymentStatus::Partial : BusPaymentStatus::Pending);
        $this->save();
    }
}
