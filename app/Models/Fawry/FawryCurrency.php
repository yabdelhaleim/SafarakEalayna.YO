<?php

namespace App\Models\Fawry;

use App\Models\Setting\Currency as BaseCurrency;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FawryCurrency extends Model
{
    use HasFactory;

    protected $fillable = [
        'currency_id',
        'exchange_rate',
        'min_amount',
        'max_amount',
        'fee_percent',
        'fixed_fee',
        'is_active',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'exchange_rate' => 'decimal:4',
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'fee_percent' => 'decimal:2',
            'fixed_fee' => 'decimal:2',
        ];
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('order');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(BaseCurrency::class, 'currency_id');
    }

    /**
     * حساب الرسم الإجمالي لمبلغ معين
     */
    public function calculateFee(float $amount): float
    {
        $percentFee = $amount * ($this->fee_percent / 100);
        return $percentFee + $this->fixed_fee;
    }

    /**
     * التحقق من أن المبلغ ضمن الحدود المسموحة
     */
    public function isAmountValid(float $amount): bool
    {
        if ($this->min_amount && $amount < $this->min_amount) {
            return false;
        }

        if ($this->max_amount && $amount > $this->max_amount) {
            return false;
        }

        return true;
    }
}
