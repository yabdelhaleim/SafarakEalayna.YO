<?php

namespace App\Models\Flight;

use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

#[Fillable([
    'name',
    'code',
    'system_type',
    'currency',
    // ⚠️ 'balance' intentionally REMOVED — ممنوع التعديل الجماعي المباشر (mass assignment).
    //    السبب: تعديل balance بدون تسجيل في الـ prepaid GL + account_entries
    //    بيسبب desync محاسبي. استخدم AirlineAccountDebitService أو db()->decrement()/increment()
    //    داخل LedgerBalanceMutationGuard::run() فقط.
    'credit_limit',
    'is_active',
    'notes',
])]
class AirlineAccount extends Model
{
    // ملاحظة: مفيش use SoftDeletes — جدول airline_accounts ما عندوش deleted_at column
    // (الـ schema قديم — الحذف يكون hard delete فقط).

    /**
     * Flag يدل على أن التعديل جاي من debit()/credit() (مسار معتمد).
     */
    private static bool $internalBalanceUpdate = false;

    /**
     * Defense-in-depth: منع تعديل `balance` خارج نطاق debit()/credit() و LedgerBalanceMutationGuard.
     *
     * الـ legacy model ده كان سبب الـ "MYSTERY DESYNC" في flight_systems (NDC_WONDR, NDC_X_NSAS).
     * لحد ما تتم ترحيلتها لـ flight_carriers الجديدة، نطبّق نفس الـ 4 protection layers:
     *   ① Mass Assignment (مش في [Fillable])
     *   ② Eloquent Observer (booted + static::updating)
     *   ③ Flag-based bypass (mutateBalanceInternal)
     *   ④ DB::listen() في AppServiceProvider (notification للـ admin)
     *
     * @see AppServiceProvider::boot() — DB::listen() safety net
     * @see App\Services\Flight\AirlineAccountDebitService
     */
    protected static function booted(): void
    {
        static::updating(function (AirlineAccount $account): void {
            if (! $account->isDirty('balance')) {
                return;
            }

            // مسموح: من داخل debit()/credit() عبر increment/decrement (يرفع العلم)
            // أو من داخل LedgerBalanceMutationGuard::run (مسار الدفتر المعتمد)
            if (self::$internalBalanceUpdate || LedgerBalanceMutationGuard::isAllowed()) {
                return;
            }

            if (app()->runningUnitTests() && ! (bool) config('accounting.strict_test_guards', false)) {
                return;
            }

            Log::warning('AirlineAccount balance mutation blocked', [
                'airline_account_id' => $account->id,
                'account_name' => $account->name,
                'attempted_balance' => (float) $account->balance,
                'original_balance' => (float) $account->getOriginal('balance'),
                'user_id' => Auth::id(),
            ]);

            throw new \RuntimeException(
                sprintf(
                    'لا يمكن تعديل رصيد حساب "%s" مباشرة. استخدم AirlineAccountDebitService أو debit()/credit() لضمان تسجيل القيد المحاسبي الصحيح في GL.',
                    $account->name
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

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'credit_limit' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(AirlineTransaction::class);
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(FlightBooking::class);
    }

    public function getAvailableBalanceAttribute(): float
    {
        return (float) $this->balance + (float) $this->credit_limit;
    }

    public function debit(float $amount, int $bookingId, int $userId): AirlineTransaction
    {
        // Check if sufficient balance is available
        if ($this->available_balance < $amount) {
            throw new \Exception(
                "رصيد شركة الطيران غير كافٍ. " .
                "المطلوب: {$amount} {$this->currency}، " .
                "المتاح: {$this->available_balance} {$this->currency}"
            );
        }

        $before = $this->balance;
        // استخدم mutateBalanceInternal عشان الـ observer يسمح بالتعديل
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
        // استخدم mutateBalanceInternal عشان الـ observer يسمح بالتعديل
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

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeBySystemType($query, string $systemType)
    {
        return $query->where('system_type', $systemType);
    }
}
