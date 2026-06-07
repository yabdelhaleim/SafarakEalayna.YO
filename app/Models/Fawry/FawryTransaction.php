<?php

namespace App\Models\Fawry;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Setting\Currency;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FawryTransaction extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'client_id',
        'client_name',
        'operation_type',
        'client_amount',
        'fawry_price',
        'selling_price',
        'profit',
        'employee_id',
        'account_id',
        'currency_id',
        'payment_method',
        'amount',
        'reference_number',
        'notes',
        'payment_details',
        'expense_transaction_id',
        'income_transaction_id',
        'created_by_user_id',
        'updated_by_user_id',
        'client_ip',
        'fawry_machine_id',
    ];

    protected $casts = [
        'client_amount' => 'decimal:2',
        'fawry_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'profit' => 'decimal:2',
        'amount' => 'decimal:2',
        'payment_details' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function ($transaction) {
            if (empty($transaction->profit)) {
                $transaction->profit = $transaction->selling_price - $transaction->fawry_price;
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'client_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(FawryMachine::class, 'fawry_machine_id');
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    /** تسمية نوع العملية من جدول fawry_operation_types (الكود في العمود operation_type) */
    public function operationTypeRow(): BelongsTo
    {
        return $this->belongsTo(FawryOperationType::class, 'operation_type', 'code');
    }

    /** تسمية طريقة الدفع من جدول fawry_payment_methods */
    public function paymentMethodRow(): BelongsTo
    {
        return $this->belongsTo(FawryPaymentMethod::class, 'payment_method', 'code');
    }

    public function expenseTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'expense_transaction_id');
    }

    public function incomeTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'income_transaction_id');
    }

    public function scopeByOperationType(Builder $query, string $type): Builder
    {
        return $query->where('operation_type', $type);
    }

    public function scopeByPaymentMethod(Builder $query, string $method): Builder
    {
        return $query->where('payment_method', $method);
    }

    public function scopeByEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeByDateRange(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }
}
