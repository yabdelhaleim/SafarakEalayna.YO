<?php

namespace App\Models;

use App\Enums\VisaStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class VisaBooking extends Model
{
    use HasFactory, SoftDeletes;

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
            throw new \RuntimeException('لا يمكن حذف حجز التأشيرة برمجياً للحفاظ على السجلات المالية وتوازن العُهد. يرجى إلغاء الحجز (Cancel) لتسوية الأرصدة تلقائياً.');
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
        return (float) $this->selling_price + (float) ($this->service_fee ?? 0)
            - (float) $this->payments()->sum('amount');
    }

    public function getPaidAmountAttribute(): float
    {
        return (float) $this->payments()->sum('amount');
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
