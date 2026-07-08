<?php

namespace App\Models\Flight;

use App\Models\User;
use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Log;

#[Fillable([
    'flight_system_id',
    'name',
    'code',
    'iata_code',
    'currency',
    // ⚠️ 'balance' intentionally REMOVED — ممنوع التعديل الجماعي المباشر (mass assignment).
    //    يجب استخدام FlightCarrierRechargeService::rechargeFromAccount() فقط.
    //    السبب: تعديل balance بدون update للحساب المسبق
    //    (Account "رصيد مسبق — ناقلو الطيران") يسبب desync محاسبي.
    'credit_limit',
    'is_active',
    'notes',
    'created_by',
])]
class FlightCarrier extends Model
{
    use SoftDeletes;

    /**
     * Flag يدل على أن التعديل جاي من debit()/credit() (مسار معتمد).
     */
    private static bool $internalBalanceUpdate = false;

    /**
     * Defense-in-depth: منع تعديل `balance` خارج نطاق debit()/credit() و LedgerBalanceMutationGuard.
     *
     * السبب: Filament Resources كانت تعرض حقل `balance` قابلاً للتعديل مما سمح للأدمن
     * بتعديل رصيد الناقل مباشرةً بدون تسجيل القيد المحاسبي المقابل في الـ GL
     * (Account ID=24 "رصيد مسبق ناقلي")، مما كان يسبب عجزاً محاسبياً.
     *
     * @see app/Services/Flight/FlightCarrierRechargeService.php
     * @see app/Services/Finance/PrepaidLedgerService.php
     */
    protected static function booted(): void
    {
        static::updating(function (FlightCarrier $carrier): void {
            if (! $carrier->isDirty('balance')) {
                return;
            }

            // مسموح: من داخل debit()/credit() عبر increment/decrement (يرفع العلم)
            // أو من داخل LedgerBalanceMutationGuard::run (مسار الدفتر المعتمد)
            if (self::$internalBalanceUpdate || LedgerBalanceMutationGuard::isAllowed()) {
                return;
            }

            // في الـ production: الـ guard شغال.
            // في الـ tests افتراضياً: bypassed للـ backwards compatibility مع الـ tests القديمة.
            // في الـ tests الجديدة (PrepaidCogsTest): config('accounting.strict_test_guards') = true → الـ guard شغال.
            if (app()->runningUnitTests() && ! (bool) config('accounting.strict_test_guards', false)) {
                return;
            }

            Log::warning('FlightCarrier balance mutation blocked', [
                'flight_carrier_id' => $carrier->id,
                'carrier_name' => $carrier->name,
                'attempted_balance' => (float) $carrier->balance,
                'original_balance' => (float) $carrier->getOriginal('balance'),
                'user_id' => \Illuminate\Support\Facades\Auth::id(),
            ]);

            throw new \RuntimeException(
                sprintf(
                    'لا يمكن تعديل رصيد الناقل "%s" مباشرةً. استخدم زر "شحن رصيد" في Filament أو FlightCarrierRechargeService::rechargeFromAccount() لضمان تسجيل القيد المحاسبي الصحيح.',
                    $carrier->name
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

    /**
     * يُضمَّن في JSON (قائمة الناقلين، Vue، وغيرها) حتى تُحسب الواجهة «المتاح» بشكل صحيح.
     *
     * @var list<string>
     */
    protected $appends = [
        'available_balance',
    ];

    protected function casts(): array
    {
        return [
            // ⚠️ 'balance' ممنوع تعديله مباشرةً:
            //   - ليس في $fillable (لا mass assignment)
            //   - لا يُقبل التعديل إلا من داخل debit()/credit() أو LedgerBalanceMutationGuard::run()
            //   - أي محاولة فردية تعدّله ترمي RuntimeException
            //   - لتعديل الرصيد: FlightCarrierRechargeService::rechargeFromAccount()
            'balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function system(): BelongsTo
    {
        return $this->belongsTo(FlightSystem::class, 'flight_system_id')->withDefault([
            'name' => 'ساين (بدون نظام)',
        ]);
    }

    public function groups(): HasMany
    {
        return $this->hasMany(FlightGroup::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(\App\Models\Flight\FlightBooking::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AirlineTransaction::class, 'flight_carrier_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * إجمالي المتاح للخصم = الرصيد الحالي + حد الائتمان (سقف إضافي)، وليس طرح الحد من الرصيد.
     */
    public function getAvailableBalanceAttribute(): float
    {
        return (float) $this->balance + (float) $this->credit_limit;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCurrency($query, string $currency)
    {
        return $query->where('currency', $currency);
    }

    public function scopeBySystem($query, ?int $systemId)
    {
        if ($systemId === null) {
            return $query->whereNull('flight_system_id');
        }
        return $query->where('flight_system_id', $systemId);
    }

    public function debit(float $amount, int $bookingId, int $userId): AirlineTransaction
    {
        if ($this->available_balance < $amount) {
            throw new \Exception(
                "رصيد شركة الطيران غير كافٍ. " .
                "المطلوب: {$amount} {$this->currency}، " .
                "المتاح: {$this->available_balance} {$this->currency}"
            );
        }

        $before = $this->balance;
        $this->mutateBalanceInternal($amount, fn () => $this->decrement('balance', $amount));

        return $this->transactions()->create([
            'flight_booking_id' => $bookingId,
            'type' => 'debit',
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $this->balance,
            'description' => 'خصم حجز تذكرة',
            'created_by' => $userId,
        ]);
    }

    public function credit(float $amount, string $description, int $userId, ?int $bookingId = null): AirlineTransaction
    {
        $before = $this->balance;
        $this->mutateBalanceInternal($amount, fn () => $this->increment('balance', $amount));

        return $this->transactions()->create([
            'flight_booking_id' => $bookingId,
            'type' => 'credit',
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $this->balance,
            'description' => $description,
            'created_by' => $userId,
        ]);
    }
}
