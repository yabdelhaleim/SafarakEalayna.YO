<?php

namespace App\Models\Fawry;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FawryMachine extends Model
{
    use HasFactory, SoftDeletes;

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
        $this->decrement('balance', $amount);

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
        $this->increment('balance', $amount);

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
