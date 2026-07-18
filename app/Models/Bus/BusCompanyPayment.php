<?php

namespace App\Models\Bus;

use App\Enums\BusCompanyPaymentStatus;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Database\Factories\Bus\BusCompanyPaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'company_id',
    'inventory_id',
    'amount',
    'account_id',
    'transaction_id',
    'status',
    'notes',
    'created_by',
])]
class BusCompanyPayment extends Model
{
    use SoftDeletes, HasFactory;

    protected static function newFactory(): BusCompanyPaymentFactory
    {
        return BusCompanyPaymentFactory::new();
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => BusCompanyPaymentStatus::class,
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(BusCompany::class, 'company_id');
    }

    public function inventory(): BelongsTo
    {
        return $this->belongsTo(BusInventory::class, 'inventory_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'transaction_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
