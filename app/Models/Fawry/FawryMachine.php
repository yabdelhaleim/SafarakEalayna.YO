<?php

namespace App\Models\Fawry;

use App\Support\Finance\LedgerBalanceMutationGuard;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class FawryMachine extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Flag indicates the balance mutation is coming from the sanctioned
     * `debit()` / `credit()` path (which raises it before increment/decrement)
     * so the boot guard below allows it.
     */
    private static bool $internalBalanceUpdate = false;

    protected $fillable = [
        'name',
        'type',
        'balance',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updating(function (FawryMachine $machine): void {
            if (! $machine->isDirty('balance')) {
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

            Log::warning('FawryMachine balance mutation blocked', [
                'fawry_machine_id' => $machine->id,
                'machine_name' => $machine->name,
                'attempted_balance' => (float) $machine->balance,
                'original_balance' => (float) $machine->getOriginal('balance'),
                'user_id' => Auth::id(),
            ]);

            throw new \RuntimeException(
                sprintf(
                    'لا يمكن تعديل رصيد ماكينة "%s" مباشرة. استخدم debit()/credit() أو FawryMachineRechargeService لضمان تسجيل القيد المحاسبي الصحيح في GL.',
                    $machine->name
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

    public function transactions(): HasMany
    {
        return $this->hasMany(FawryMachineTransaction::class, 'fawry_machine_id');
    }

    public function debit(float $amount, string $description, int $userId, ?int $fawryTransactionId = null): FawryMachineTransaction
    {
        if ((float) $this->balance < $amount) {
            throw new \Exception('رصيد الماكينة غير كافٍ.');
        }

        $before = $this->balance;
        // استخدم mutateBalanceInternal عشان الـ observer يسمح بالتعديل
        $this->mutateBalanceInternal($amount, fn () => $this->decrement('balance', $amount));

        return $this->transactions()->create([
            'fawry_transaction_id' => $fawryTransactionId,
            'type' => 'debit',
            'amount' => $amount,
            'balance_before' => $before,
            'balance_after' => $this->balance,
            'description' => $description,
            'created_by' => $userId,
        ]);
    }

    public function credit(float $amount, string $description, int $userId, ?int $fawryTransactionId = null): FawryMachineTransaction
    {
        $before = $this->balance;
        // استخدم mutateBalanceInternal عشان الـ observer يسمح بالتعديل
        $this->mutateBalanceInternal($amount, fn () => $this->increment('balance', $amount));

        return $this->transactions()->create([
            'fawry_transaction_id' => $fawryTransactionId,
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
}
