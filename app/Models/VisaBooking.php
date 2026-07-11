<?php

namespace App\Models;

use App\Enums\VisaStatus;
use App\Support\Finance\ModelDeletionGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

use App\Traits\ClearsCache;

class VisaBooking extends Model
{
    use HasFactory, SoftDeletes, ClearsCache, ModelDeletionGuard;

    protected $fillable = [
        'customer_id',
        'visa_detail_id',
        'module',
        'purchase_price',
        'selling_price',
        'service_fee',
        'profit',
        'currency',
        'status',
        'agent_name',
        'notes',
        'account_id',
        'employee_id',
        'created_by',
        'expense_transaction_id',
        'income_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'purchase_price' => 'decimal:2',
            'selling_price' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'profit' => 'decimal:2',
            'status' => VisaStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (VisaBooking $booking) {
            // Allowed only when:
            //   - we're inside VisaBooking::run() (canonical safe path
            //     through VisaBookingService::deleteBookingWithReversal), OR
            //   - the app is running PHPUnit tests.
            // Everything else (Filament `DeleteAction`, raw tinker, accidental API
            // calls, etc.) is blocked to prevent silent balance corruption.
            //
            // The `cancel()` flow is intentionally NOT in this allowlist —
            // it must keep the booking row visible (status=Cancelled) to
            // preserve the financial timeline.
            $bypassViaGuard = VisaBooking::isAllowed();

            if (! app()->runningUnitTests() && ! $bypassViaGuard) {
                throw new \RuntimeException(
                    'لا يمكن حذف حجز التأشيرة برمجياً للحفاظ على السجلات المالية وتوازن العُهد. '
                    .'يرجى استخدام VisaBookingService::deleteBookingWithReversal() للحذف الإداري، '
                    .'أو VisaBookingService::cancel() للإلغاء المرئي الذي يحتفظ بالصف.'
                );
            }
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function visaDetail(): BelongsTo
    {
        return $this->belongsTo(VisaDetail::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function expenseTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'expense_transaction_id');
    }

    public function incomeTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'income_transaction_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(VisaPayment::class);
    }

    public function scopeByStatus(Builder $query, VisaStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    public function getRemainingAmountAttribute(): float
    {
        return max(0.0, (float) $this->selling_price + (float) ($this->service_fee ?? 0) - $this->paid_amount);
    }

    public function getPaidAmountAttribute(): float
    {
        return $this->relationLoaded('payments')
            ? (float) $this->payments->sum('amount')
            : (float) $this->payments()->sum('amount');
    }

    public function getIsFullyPaidAttribute(): bool
    {
        return $this->paid_amount >= ((float) $this->selling_price + (float) ($this->service_fee ?? 0));
    }

    public function toArray(): array
    {
        $data = parent::toArray();
        if ($this->status instanceof VisaStatus) {
            $data['status'] = $this->status->value;
            $data['status_label'] = $this->status->label();
        }
        $data['paid_amount'] = $this->paid_amount;
        $data['remaining_amount'] = $this->remaining_amount;
        $data['is_fully_paid'] = $this->is_fully_paid;
        return $data;
    }
}
