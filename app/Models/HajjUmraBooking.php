<?php

namespace App\Models;

use App\Enums\HajjUmraStatus;
use App\Models\HajjUmra\AccommodationType;
use App\Models\HajjUmra\HajjUmraExecutingCompany;
use App\Models\HajjUmra\TripSupervisor;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HajjUmraBooking extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'companion_customer_id',
        'program_id',
        'module',
        'purchase_price',
        'selling_price',
        'profit',
        'currency',
        'per_person',
        'status',
        'agent_name',
        'notes',
        'baggage',
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
            'profit' => 'decimal:2',
            'per_person' => 'boolean',
            'status' => HajjUmraStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (HajjUmraBooking $booking) {
            throw new \RuntimeException('لا يمكن حذف حجز الحج والعمرة برمجياً لتجنب إفساد السجلات المالية والتسكين. يرجى إلغاء الحجز (Cancel) بدلاً من حذفه.');
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function companion(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'companion_customer_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
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
        return $this->hasMany(HajjUmraPayment::class);
    }

    public function scopeByStatus(Builder $query, HajjUmraStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    public function getRemainingAmountAttribute(): float
    {
        return (float) $this->selling_price - (float) $this->payments()->sum('amount');
    }

    public function getPaidAmountAttribute(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function getIsFullyPaidAttribute(): bool
    {
        return $this->paid_amount >= (float) $this->selling_price;
    }

    public function toArray(): array
    {
        $data = parent::toArray();
        if ($this->status instanceof HajjUmraStatus) {
            $data['status'] = $this->status->value;
            $data['status_label'] = $this->status->label();
        }
        $data['paid_amount'] = $this->paid_amount;
        $data['remaining_amount'] = $this->remaining_amount;
        $data['is_fully_paid'] = $this->is_fully_paid;
        return $data;
    }
}
