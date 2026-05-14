<?php

namespace App\Models\Wallet;

use App\Enums\WalletTransactionType;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'wallet_type_id',
    'customer_id',
    'customer_name',
    'wallet_number',
    'type',
    'amount',
    'service_fee',
    'total_amount',
    'wallet_account_id',
    'cash_account_id',
    'income_transaction_id',
    'expense_transaction_id',
    'employee_id',
    'created_by',
    'notes',
])]
class WalletTransaction extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'type'         => WalletTransactionType::class,
            'amount'       => 'decimal:2',
            'service_fee'  => 'decimal:2',
            'total_amount' => 'decimal:2',
        ];
    }

    public function walletType(): BelongsTo
    {
        return $this->belongsTo(WalletType::class, 'wallet_type_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function walletAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'wallet_account_id');
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function incomeTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'income_transaction_id');
    }

    public function expenseTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'expense_transaction_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeByType(Builder $query, WalletTransactionType $type): Builder
    {
        return $query->where('type', $type->value);
    }

    public function scopeByCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeByWalletType(Builder $query, int $walletTypeId): Builder
    {
        return $query->where('wallet_type_id', $walletTypeId);
    }
}
