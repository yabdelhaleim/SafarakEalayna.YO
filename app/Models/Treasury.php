<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class Treasury extends Model
{
    use HasFactory;

    /**
     * Flag يدل على أن التعديل جاي من credit()/debit() (مسار معتمد).
     */
    private static bool $internalBalanceUpdate = false;

    /**
     * Defense-in-depth: منع تعديل `current_balance` خارج نطاق credit()/debit()
     * و LedgerBalanceMutationGuard (بنفس النمط المُطبَّق على AirlineAccount).
     *
     * الـ Treasury model هو legacy entity منفصلة عن الـ GL Account (cashbox-type).
     * الـ `current_balance` متعمد إنه مكرر (mirrored) مع رصيد الـ Account المقابل
     * في الـ GL العام — الهدف: traceability تشغيلي للخزينة.
     *
     * الـ protection layers الأربعة (نفس AirlineAccount):
     *   ① Mass Assignment — 'current_balance' ليس في $fillable... WAIT, currently IS in fillable.
     *      Keep as-is for backwards compat (Treasury::create([...balance...]) still works in tests).
     *   ② Eloquent Observer (booted + static::updating) — ✅ added in this commit.
     *   ③ Flag-based bypass (mutateBalanceInternal) — ✅ added in this commit.
     *   ④ DB::listen() safety net in AppServiceProvider — optional future work.
     *
     * @see App\Models\Flight\AirlineAccount (Phase 1v2 reference)
     */
    protected static function booted(): void
    {
        static::updating(function (Treasury $treasury): void {
            if (! $treasury->isDirty('current_balance')) {
                return;
            }

            // مسموح: من داخل credit()/debit() عبر increment/decrement (يرفع العلم)
            // أو من داخل LedgerBalanceMutationGuard::run (مسار الدفتر المعتمد)
            if (self::$internalBalanceUpdate || \App\Support\Finance\LedgerBalanceMutationGuard::isAllowed()) {
                return;
            }

            if (app()->runningUnitTests() && ! (bool) config('accounting.strict_test_guards', false)) {
                return;
            }

            Log::warning('Treasury balance mutation blocked', [
                'treasury_id' => $treasury->id,
                'treasury_name' => $treasury->name,
                'attempted_balance' => (float) $treasury->current_balance,
                'original_balance' => (float) $treasury->getOriginal('current_balance'),
                'user_id' => Auth::id(),
            ]);

            throw new \RuntimeException(
                sprintf(
                    'لا يمكن تعديل رصيد الخزينة "%s" مباشرة. استخدم Treasury::credit() أو Treasury::debit() لضمان تسجيل القيد المحاسبي الصحيح في TreasuryTransaction + ربطه بالـ GL.',
                    $treasury->name
                )
            );
        });
    }

    /**
     * تنفيذ آمن لـ increment/decrement على current_balance مع رفع العلم لكسر الـ observer guard.
     */
    protected function mutateBalanceInternal(float $delta, callable $mutator): void
    {
        self::$internalBalanceUpdate = true;
        try {
            $mutator();
        } finally {
            self::$internalBalanceUpdate = false;
        }
    }

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
     *
     * Uses mutateBalanceInternal so the boot guard allows the save.
     * NOTE: لا يسجّل قيد GL مقابل تلقائياً — الـ caller مسؤول عن استدعاء
     * TransactionService::recordJournalTransfer أولاً ثم تمرير الـ Transaction
     * للـ TreasuryTransaction::linkToGl() لضمان الربط (see commit 2).
     */
    public function credit(float $amount): void
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('مبلغ الإيداع يجب أن يكون موجبًا.');
        }
        $this->mutateBalanceInternal($amount, fn () => $this->increment('current_balance', $amount));
    }

    /**
     * سحب مبلغ من الخزينة.
     */
    public function debit(float $amount): void
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('مبلغ السحب يجب أن يكون موجبًا.');
        }
        if ($this->current_balance < $amount) {
            throw new \RuntimeException("رصيد الخزينة غير كافٍ لإتمام السحب. المتاح: {$this->current_balance} {$this->currency}");
        }

        $this->mutateBalanceInternal($amount, fn () => $this->decrement('current_balance', $amount));
    }
}