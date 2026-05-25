<?php

namespace App\Models\Bus;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'name',
    'phone',
    'account_id',
    'address',
    'is_active',
    'notes',
    'created_by',
])]
class BusCompany extends Model
{
    use SoftDeletes;

    public function account(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Account::class);
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(BusInventory::class, 'company_id');
    }

    public function companyPayments(): HasMany
    {
        return $this->hasMany(BusCompanyPayment::class, 'company_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
