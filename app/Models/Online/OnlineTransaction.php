<?php

namespace App\Models\Online;

use App\Enums\OnlineTransactionStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Setting\PaymentMethod;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class OnlineTransaction extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'service_type_id',
        'provider_id',
        'customer_id',
        'customer_name',
        'customer_phone',
        'customer_country',
        'employee_id',
        'purchase_price',
        'selling_price',
        'profit',
        'payment_method',
        'account_id',
        'reference_number',
        'expense_transaction_id',
        'income_transaction_id',
        'status',
        'failure_reason',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'purchase_price' => 'decimal:2',
        'selling_price' => 'decimal:2',
        'profit' => 'decimal:2',
        'status' => OnlineTransactionStatus::class,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $tx): void {
            $tx->profit = (float) $tx->selling_price - (float) $tx->purchase_price;
        });

        static::deleting(function (OnlineTransaction $transaction) {
            throw new \RuntimeException('لا يمكن حذف معاملات الخدمات الإلكترونية برمجياً للحفاظ على السجلات المالية وتوازن الخزينة. يرجى تعديل الحالة بدلاً من الحذف.');
        });
    }

    public function serviceType(): BelongsTo
    {
        return $this->belongsTo(OnlineServiceType::class, 'service_type_id');
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(OnlineServiceProvider::class, 'provider_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function paymentMethodRow(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'payment_method', 'code');
    }

    public function expenseTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'expense_transaction_id');
    }

    public function incomeTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'income_transaction_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeByStatus(Builder $query, OnlineTransactionStatus $status): Builder
    {
        return $query->where('status', $status->value);
    }

    public function scopeByCustomer(Builder $query, int $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    public function scopeByServiceType(Builder $query, int $typeId): Builder
    {
        return $query->where('service_type_id', $typeId);
    }

    public function scopeByProvider(Builder $query, int $providerId): Builder
    {
        return $query->where('provider_id', $providerId);
    }

    public function scopeByEmployee(Builder $query, int $employeeId): Builder
    {
        return $query->where('employee_id', $employeeId);
    }

    public function scopeByPaymentMethod(Builder $query, string $code): Builder
    {
        return $query->where('payment_method', $code);
    }

    public function scopeByDateRange(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }
}
