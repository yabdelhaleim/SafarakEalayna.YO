<?php

namespace App\Models\Flight;

use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

#[Fillable([
    'name',
    'code',
    'type',
    'is_active',
    'currency',
    'balance',
    'credit_limit',
    'description',
    'created_by',
])]
class FlightSystem extends Model
{
    use SoftDeletes;

    /**
     * Flag يدل على أن التعديل جاي من debit()/credit() (مسار معتمد).
     */
    private static bool $internalBalanceUpdate = false;

    /**
     * Defense-in-depth: منع تعديل `balance` خارج نطاق debit()/credit() و LedgerBalanceMutationGuard.
     *
     * السبب: نفس مشكلة FlightCarrier — تعديل الرصيد من Filament بدون قيد محاسبي
     * مقابل في Account ID=23 "رصيد مسبق أنظمة" كان يسبب عجزاً محاسبياً.
     *
     * @see app/Services/Flight/FlightSystemRechargeService.php
     * @see app/Services/Finance/PrepaidLedgerService.php
     */
    protected static function booted(): void
    {
        static::updating(function (FlightSystem $system): void {
            if (! $system->isDirty('balance')) {
                return;
            }

            if (self::$internalBalanceUpdate || LedgerBalanceMutationGuard::isAllowed()) {
                return;
            }

            // في الـ production: الـ guard شغال.
            // في الـ tests افتراضياً: bypassed للـ backwards compatibility مع الـ tests القديمة.
            // في الـ tests الجديدة (PrepaidCogsTest): config('accounting.strict_test_guards') = true → الـ guard شغال.
            if (app()->runningUnitTests() && ! (bool) config('accounting.strict_test_guards', false)) {
                return;
            }

            Log::warning('FlightSystem balance mutation blocked', [
                'flight_system_id' => $system->id,
                'system_name' => $system->name,
                'attempted_balance' => (float) $system->balance,
                'original_balance' => (float) $system->getOriginal('balance'),
                'user_id' => Auth::id(),
            ]);

            throw new \RuntimeException(
                sprintf(
                    'لا يمكن تعديل رصيد نظام الحجز "%s" مباشرةً. استخدم زر "إعادة شحن" في Filament أو FlightSystemRechargeService::rechargeFromAccount() لضمان تسجيل القيد المحاسبي الصحيح.',
                    $system->name
                )
            );
        });
    }

    /**
     * تنفيذ آمن لـ increment/decrement على balance مع رفع العلم لكسر الـ observer guard.
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

    protected $appends = [
        'available_balance',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
        ];
    }

    /**
     * إجمالي القدرة على الخصم قبل الحجز = الرصيد المدفوع + حد الائتمان الإضافي
     * (يتوافق مع AirlineAccount ومع سيناريو «شحن رصيد + سقف ائتمان»).
     */
    public function getAvailableBalanceAttribute(): float
    {
        return (float) $this->balance + (float) $this->credit_limit;
    }

    public function carriers(): HasMany
    {
        return $this->hasMany(FlightCarrier::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(\App\Models\Flight\FlightBooking::class);
    }

    public function systemTransactions(): HasMany
    {
        return $this->hasMany(FlightSystemTransaction::class, 'flight_system_id');
    }

    public function debit(float $amount, int $bookingId, int $userId): FlightSystemTransaction
    {
        if ($this->available_balance < $amount) {
            throw new \Exception(
                'رصيد نظام الحجز غير كافٍ. '.
                "المطلوب: {$amount} {$this->currency}، ".
                "المتاح: {$this->available_balance} {$this->currency}"
            );
        }

        $before = (float) $this->balance;
        $this->mutateBalanceInternal($amount, fn () => $this->decrement('balance', $amount));

        return $this->systemTransactions()->create([
            'flight_booking_id' => $bookingId,
            'type' => 'debit',
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => (float) $this->fresh()->balance,
            'description' => 'خصم تكلفة حجز تذكرة',
            'created_by' => $userId,
        ]);
    }

    public function credit(float $amount, string $description, int $userId, ?int $bookingId = null): FlightSystemTransaction
    {
        $before = (float) $this->balance;
        $this->mutateBalanceInternal($amount, fn () => $this->increment('balance', $amount));

        return $this->systemTransactions()->create([
            'flight_booking_id' => $bookingId,
            'type' => 'credit',
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => (float) $this->fresh()->balance,
            'description' => $description,
            'created_by' => $userId,
        ]);
    }

    public function createdBy(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }
}
