<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Treasury extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'currency',
        'current_balance',
        'is_active',
    ];

    protected $casts = [
        'current_balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function transactions(): HasMany
    {
        return $this->hasMany(TreasuryTransaction::class, 'treasury_id');
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(\App\Models\Flight\RefundRequest::class, 'treasury_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeByCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', $currency);
    }

    /**
     * إيداع مبلغ في الخزينة بشكل آمن.
     */
    public function credit(float $amount): void
    {
        $this->increment('current_balance', $amount);
    }

    /**
     * سحب مبلغ من الخزينة.
     */
    public function debit(float $amount): void
    {
        if ($this->current_balance < $amount) {
            throw new \RuntimeException("رصيد الخزينة غير كافٍ لإتمام السحب. المتاح: {$this->current_balance} {$this->currency}");
        }

        $this->decrement('current_balance', $amount);
    }
}
