<?php

namespace App\Models;

use App\Enums\SupplierType;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Supplier extends Model
{
    use SoftDeletes;

    #[Fillable([
        'name',
        'code',
        'type',
        'contact_person',
        'email',
        'phone',
        'mobile',
        'address',
        'city',
        'country',
        'account_id',
        'credit_limit',
        'current_debt',
        'payment_terms',
        'is_active',
        'notes',
        'created_by',
    ])]

    protected function casts(): array
    {
        return [
            'type' => SupplierType::class,
            'credit_limit' => 'decimal:2',
            'current_debt' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    public function scopeWithDebt($query)
    {
        return $query->where('current_debt', '>', 0);
    }

    // Helper methods
    public function getRemainingCreditAttribute(): float
    {
        return (float) ($this->credit_limit - $this->current_debt);
    }

    public function isOverCreditLimit(): bool
    {
        return $this->current_debt > $this->credit_limit;
    }
}
