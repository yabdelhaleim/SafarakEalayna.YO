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
    'amount_paid',
    'wallet_account_id',
    'cash_account_id',
    'income_transaction_id',
    'expense_transaction_id',
    'employee_id',
    'created_by',
    'notes',
    // IDM-1 (2026-08-20): replay protection. Caller-supplied via the
    // `Idempotency-Key` HTTP header. Scoped by (created_by, idempotency_key)
    // via the UNIQUE index `wt_idem_uniq`. NULL when the caller does not
    // supply a key (legacy / non-idempotent clients — backward compat).
    'idempotency_key',
])]
class WalletTransaction extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'type' => WalletTransactionType::class,
            'amount' => 'decimal:2',
            'service_fee' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
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

    /**
     * SEC-2 (2026-08-21) — IDOR mitigation: scope the query to transactions
     * the viewer is allowed to see.
     *
     *   - admin / owner → no extra filter (full visibility by design).
     *   - any other role → `created_by = viewer.id`.
     *
     * Used by `WalletTransactionController::show`, `customerStatement`,
     * and `customerBalances` to enforce horizontal privilege separation.
     * The middleware stack already guarantees the request is authenticated
     * and active; this scope assumes `$user` is a non-null User instance.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if (in_array($user->role, ['admin', 'owner'], true)) {
            return $query;
        }

        return $query->where('created_by', $user->id);
    }
}
